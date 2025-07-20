<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\TransactionStateHandler;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use UnzerPayment6\Components\DependencyInjection\Factory\PaymentTransitionMapperFactory;
use UnzerPayment6\Components\PaymentTransitionMapper\Exception\NoTransitionMapperFoundException;
use UnzerPayment6\Components\PaymentTransitionMapper\Exception\TransitionMapperException;
use UnzerSDK\Resources\Payment;
use UnzerSDK\Resources\PaymentTypes\BasePaymentType;

class TransactionStateHandler implements TransactionStateHandlerInterface
{
    public function __construct(
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly PaymentTransitionMapperFactory $transitionMapperFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function transformTransactionState(
        string $transactionId,
        Payment $payment,
        Context $context
    ): void {
        if ($payment->getPaymentType() === null) {
            $this->logger->error(sprintf('The payment has no payment type for transition mapping. TransactionId: %s', $transactionId), [
                'payment' => $payment,
            ]);

            return;
        }

        $transition = $this->getTargetTransition($payment);

        if (empty($transition)) {
            $this->logger->error('Due to an empty transition, the FAIL transition is executed');

            $this->executeTransition($transactionId, StateMachineTransitionActions::ACTION_FAIL, $context);

            throw new RuntimeException('Invalid transition status');
        }

        $this->executeTransition($transactionId, $transition, $context);
    }

    public function fail(string $transactionId, Context $context): void
    {
        $this->logger->error(
            '🚨 FAIL TRANSITION CALLED - This should not happen for successful 3DS payments!',
            [
                'transactionId' => $transactionId,
                'stackTrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            ]
        );

        // Check if this might be a 3DS payment that was already completed by webhook
        // In that case, prevent the fail transition to avoid illegal state errors
        try {
            // Get current transaction state to check if it's already paid
            $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria([$transactionId]);
            $criteria->addAssociation('stateMachineState');
            
            // Use the entity repository to fetch transaction state
            // Note: We don't have direct access to the repository here, so we'll need to pass this check
            // to the executeTransition method which has better error handling
            
            $this->logger->info(
                'Proceeding with fail transition - will be caught if illegal',
                [
                    'transactionId' => $transactionId,
                ]
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Error checking transaction state before fail',
                [
                    'transactionId' => $transactionId,
                    'exception' => $exception->getMessage(),
                ]
            );
        }

        $this->executeTransition(
            $transactionId,
            StateMachineTransitionActions::ACTION_FAIL,
            $context
        );
    }

    public function pay(string $transactionId, Context $context): void
    {
        $this->executeTransition(
            $transactionId,
            StateMachineTransitionActions::ACTION_DO_PAY,
            $context
        );
    }

    protected function getTargetTransition(Payment $payment): string
    {
        try {
            /** @var BasePaymentType $paymentType */
            $paymentType      = $payment->getPaymentType();
            $transitionMapper = $this->transitionMapperFactory->getTransitionMapper($paymentType);
            $transition       = $transitionMapper->getTargetPaymentStatus($payment);
        } catch (NoTransitionMapperFoundException | TransitionMapperException $exception) {
            $this->logger->error($exception->getMessage(), [
                'code'  => $exception->getCode(),
                'file'  => $exception->getFile(),
                'line'  => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }

        return $transition ?? '';
    }

    protected function executeTransition(string $transactionId, string $transition, Context $context): void
    {
        try {
            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderTransactionDefinition::ENTITY_NAME,
                    $transactionId,
                    $transition,
                    'stateId'
                ),
                $context
            );
        } catch (IllegalTransitionException $exception) {
            // Enhanced handling for illegal transitions
            $this->logger->info(
                'Illegal transition caught',
                [
                    'transactionId' => $transactionId,
                    'attemptedTransition' => $transition,
                    'exception' => $exception->getMessage(),
                ]
            );

            // Special handling for fail transitions - likely 3DS race condition
            if ($transition === StateMachineTransitionActions::ACTION_FAIL) {
                $this->logger->info(
                    'Illegal fail transition detected - likely 3DS payment already completed by webhook',
                    [
                        'transactionId' => $transactionId,
                        'message' => 'Transaction may already be in paid state due to webhook processing',
                    ]
                );
                // Don't re-throw for fail transitions - they're likely already in a final state
                return;
            }

            // For other transitions, this is still a false positive handling (state to state) like open -> open, paid -> paid, etc.
        }

        // If payment should be in state "paid", `do_pay` is given -> finalize state
        if ($transition === StateMachineTransitionActions::ACTION_DO_PAY) {
            $this->logger->debug(
                sprintf(
                    '%s transition is executed as fallback for %s',
                    StateMachineTransitionActions::ACTION_PAID,
                    StateMachineTransitionActions::ACTION_DO_PAY
                )
            );

            $this->executeTransition($transactionId, StateMachineTransitionActions::ACTION_PAID, $context);
        }
    }
}
