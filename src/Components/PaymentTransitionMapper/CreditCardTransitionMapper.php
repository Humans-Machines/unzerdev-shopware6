<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentTransitionMapper;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use UnzerPayment6\Components\BookingMode;
use UnzerPayment6\Components\ConfigReader\ConfigReader;
use UnzerPayment6\Components\ConfigReader\ConfigReaderInterface;
use UnzerPayment6\Components\PaymentTransitionMapper\Exception\TransitionMapperException;
use UnzerPayment6\Components\PaymentTransitionMapper\Traits\HasBookingMode;
use UnzerSDK\Resources\Payment;
use UnzerSDK\Resources\PaymentTypes\BasePaymentType;
use UnzerSDK\Resources\PaymentTypes\Card;
use UnzerSDK\Resources\TransactionTypes\Authorization;

class CreditCardTransitionMapper extends AbstractTransitionMapper
{
    use HasBookingMode;

    private const BOOKING_MODE_KEY = ConfigReader::CONFIG_KEY_BOOKING_MODE_CARD;
    private const DEFAULT_MODE     = BookingMode::CHARGE;

    private LoggerInterface $logger;

    public function __construct(
        ConfigReaderInterface $configReader, 
        EntityRepository $orderTransactionRepository,
        LoggerInterface $logger
    ) {
        $this->configReader               = $configReader;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->logger = $logger;
    }

    public function supports(BasePaymentType $paymentType): bool
    {
        return $paymentType instanceof Card;
    }

    public function getTargetPaymentStatus(Payment $paymentObject): string
    {
        $bookingMode = $this->getBookingMode($paymentObject);

        if ($bookingMode !== self::DEFAULT_MODE) {
            return $this->mapForAuthorizeMode($paymentObject);
        }

        // Handle 3DS pending transactions
        if ($paymentObject->isPending()) {
            $orderId = $paymentObject->getOrderId();
            if ($orderId) {
                $currentState = $this->getCurrentTransactionState($orderId);
                
                // If transaction is still in open state, it's likely a 3DS transaction
                // Use process action to move it to in_progress to avoid error redirect
                if ($currentState === 'open') {
                    $this->logger->info('3DS pending transaction detected, setting to in_progress', [
                        'orderId' => $orderId,
                        'currentState' => $currentState
                    ]);
                    return StateMachineTransitionActions::ACTION_PROCESS;
                }
                
                // If transaction is already in paid/in_progress state, keep it there
                if (in_array($currentState, ['paid', 'paid_partially', 'in_progress'], true)) {
                    return StateMachineTransitionActions::ACTION_PAID;
                }
            }
        }

        return parent::getTargetPaymentStatus($paymentObject);
    }

    protected function getResourceName(): string
    {
        return Card::getResourceName();
    }

    protected function mapForAuthorizeMode(Payment $paymentObject): string
    {
        if ($paymentObject->isCanceled()) {
            $status = $this->checkForRefund($paymentObject);

            if ($status !== self::INVALID_TRANSITION) {
                return $status;
            }

            $status = $this->checkForCancellation($paymentObject);

            if ($status !== self::INVALID_TRANSITION) {
                return $status;
            }

            throw new TransitionMapperException($this->getResourceName());
        }

        if ($this->stateMachineTransitionExists(AbstractTransitionMapper::CONST_KEY_AUTHORIZE) && $paymentObject->isPending()) {
            $authorization = $paymentObject->getAuthorization();

            if ($authorization instanceof Authorization && $authorization->isSuccess()) {
                return constant(sprintf('%s::%s', StateMachineTransitionActions::class, AbstractTransitionMapper::CONST_KEY_AUTHORIZE));
            }
        }

        return $this->checkForRefund($paymentObject, $this->mapPaymentStatus($paymentObject));
    }

    private function getCurrentTransactionState(string $orderId): ?string
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderId', $orderId));
            $criteria->addAssociation('stateMachineState');
            
            $result = $this->orderTransactionRepository->search($criteria, Context::createDefaultContext());
            $transaction = $result->first();
            
            if ($transaction === null) {
                return null;
            }
            
            $stateMachineState = $transaction->getStateMachineState();
            if ($stateMachineState === null) {
                return null;
            }
            
            return $stateMachineState->getTechnicalName();
            
        } catch (\Throwable $exception) {
            // If we can't determine the state, return null
            return null;
        }
    }
}
