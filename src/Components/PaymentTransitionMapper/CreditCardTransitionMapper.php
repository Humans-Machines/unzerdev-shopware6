<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentTransitionMapper;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
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

    public function __construct(
        ConfigReaderInterface $configReader, 
        EntityRepository $orderTransactionRepository,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->configReader = $configReader;
        $this->setTransactionStateDependencies($orderTransactionRepository, $logger);
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
            if ($orderId && $this->transactionRepository) {
                $currentState = $this->getCurrentTransactionState($orderId);
                
                // If transaction is still in open state, move it to in_progress
                if ($currentState === 'open') {
                    $this->logger->info('3DS pending transaction detected, setting to in_progress', [
                        'orderId' => $orderId,
                        'currentState' => $currentState
                    ]);
                    return StateMachineTransitionActions::ACTION_PROCESS;
                }
                
                // CRITICAL FIX: Never set pending payments to paid!
                // If transaction is already in progress, keep it there but don't move to paid
                if ($currentState === 'in_progress') {
                    $this->logger->info('3DS pending transaction already in progress - keeping state', [
                        'orderId' => $orderId,
                        'currentState' => $currentState,
                        'paymentState' => 'pending'
                    ]);
                    return StateMachineTransitionActions::ACTION_PROCESS; // Keep in progress
                }
                
                // If somehow already paid, this should only happen for legitimate race conditions
                if (in_array($currentState, ['paid', 'paid_partially'], true)) {
                    $this->logger->warning('Pending payment but transaction already paid - potential race condition', [
                        'orderId' => $orderId,
                        'currentState' => $currentState,
                        'paymentState' => 'pending'
                    ]);
                    return StateMachineTransitionActions::ACTION_PAID; // Keep existing state
                }
            }
        }

        // Handle 3DS race condition for canceled payments
        if ($paymentObject->isCanceled()) {
            $orderId = $paymentObject->getOrderId();
            if ($orderId && $this->transactionRepository) {
                $currentState = $this->getCurrentTransactionState($orderId);
                
                // Check if this is a genuine fraud/failure vs race condition
                $isFraudOrFailure = $this->isPaymentFraudOrFailure($paymentObject);
                
                // If transaction is already paid but payment shows fraud/failure, this is a security issue
                if (in_array($currentState, ['paid', 'paid_partially'], true)) {
                    if ($isFraudOrFailure) {
                        $this->logger->critical('SECURITY ALERT: Fraud/failed payment incorrectly marked as paid - forcing to cancel state', [
                            'orderId' => $orderId,
                            'currentState' => $currentState,
                            'paymentState' => $paymentObject->getStateName(),
                            'paymentId' => $paymentObject->getId(),
                            'fraudIndicators' => $this->getFraudIndicators($paymentObject)
                        ]);
                        // From paid state, we can only transition to cancel, not fail directly
                        return StateMachineTransitionActions::ACTION_CANCEL;
                    }
                    
                    // Only treat as race condition if no fraud indicators
                    $this->logger->info('3DS race condition detected: payment canceled but transaction already paid (no fraud indicators)', [
                        'orderId' => $orderId,
                        'currentState' => $currentState,
                        'paymentState' => $paymentObject->getStateName()
                    ]);
                    return StateMachineTransitionActions::ACTION_PAID;
                }
                
                // If transaction is in progress, likely a webhook race condition
                if ($currentState === 'in_progress') {
                    $this->logger->info('3DS race condition detected: payment canceled but transaction in progress', [
                        'orderId' => $orderId,
                        'currentState' => $currentState,
                        'paymentState' => $paymentObject->getStateName()
                    ]);
                    // Let parent handle the cancellation normally
                }
                
                // If transaction is already failed, don't try to transition again
                if (in_array($currentState, ['failed', 'cancelled'], true)) {
                    $this->logger->info('Payment canceled but transaction already in terminal state', [
                        'orderId' => $orderId,
                        'currentState' => $currentState,
                        'paymentState' => $paymentObject->getStateName()
                    ]);
                    return StateMachineTransitionActions::ACTION_FAIL; // Keep it as failed
                }
            }
        }

        try {
            return parent::getTargetPaymentStatus($paymentObject);
        } catch (TransitionMapperException $exception) {
            // For credit card payments, if we can't find a valid transition, 
            // default to cancel action for canceled payments to avoid throwing exceptions
            if ($paymentObject->isCanceled()) {
                $this->logger->info('Credit card payment canceled - using cancel action as fallback', [
                    'paymentId' => $paymentObject->getId(),
                    'paymentState' => $paymentObject->getStateName(),
                    'originalException' => $exception->getMessage()
                ]);
                return StateMachineTransitionActions::ACTION_CANCEL;
            }
            
            // Re-throw for other cases
            throw $exception;
        }
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

    /**
     * Check if a payment cancellation is due to fraud or genuine failure
     * rather than a race condition
     */
    private function isPaymentFraudOrFailure(Payment $paymentObject): bool
    {
        // Check for fraud-related messages
        $fraudIndicators = $this->getFraudIndicators($paymentObject);
        
        return !empty($fraudIndicators);
    }
    
    /**
     * Get list of fraud/failure indicators from payment object
     */
    private function getFraudIndicators(Payment $paymentObject): array
    {
        $indicators = [];
        
        // Check for charge errors - this is the most reliable indicator
        foreach ($paymentObject->getCharges() as $charge) {
            // Check charge status for errors
            if ($charge->isError()) {
                $indicators[] = 'charge_error_status';
            }
        }
        
        // Check if payment amount is non-zero but charged amount is zero (typical fraud pattern)
        $amount = $paymentObject->getAmount();
        if ($amount && $amount->getTotal() > 0 && $amount->getCharged() === 0.0) {
            $indicators[] = 'zero_charged_amount_pattern';
        }
        
        // Check if payment is canceled but state suggests it was rejected/failed
        if ($paymentObject->isCanceled() && $amount && $amount->getCharged() === 0.0) {
            $indicators[] = 'canceled_with_zero_charge';
        }
        
        return $indicators;
    }
    
    /**
     * Check if a message contains fraud-related keywords
     */
    private function containsFraudKeywords(string $message): bool
    {
        $fraudKeywords = [
            'fraud',
            'suspected fraud',
            '3DS User Authentication Failed',
            'authentication failed',
            'declined',
            'insufficient funds',
            'card declined',
            'payment declined',
            'authorization failed',
            'security check failed',
            'risk management',
            'blocked',
            'restricted'
        ];
        
        $messageLower = strtolower($message);
        
        foreach ($fraudKeywords as $keyword) {
            if (strpos($messageLower, strtolower($keyword)) !== false) {
                return true;
            }
        }
        
        return false;
    }

}
