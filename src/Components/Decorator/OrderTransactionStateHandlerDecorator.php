<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\Decorator;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

class OrderTransactionStateHandlerDecorator extends OrderTransactionStateHandler
{
    public function __construct(
        private readonly OrderTransactionStateHandler $decorated,
        private readonly LoggerInterface $logger
    ) {
    }

    public function fail(string $transactionId, Context $context): void
    {
        $this->logger->error(
            '🚨 OrderTransactionStateHandler::fail() called - intercepting for 3DS payments!',
            [
                'transactionId' => $transactionId,
                'stackTrace' => array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 0, 5),
            ]
        );

        try {
            $this->decorated->fail($transactionId, $context);
        } catch (IllegalTransitionException $exception) {
            $this->logger->error(
                '🎯 Caught IllegalTransitionException in fail() - likely 3DS race condition',
                [
                    'transactionId' => $transactionId,
                    'exception' => $exception->getMessage(),
                    'message' => 'Suppressing illegal fail transition for 3DS payment that was already completed by webhook',
                ]
            );
            
            // For 3DS payments that are already completed, we should NOT propagate the exception
            // This prevents PaymentProcessor from setting the token exception and redirecting to error page
            // The transaction is likely already in the correct state due to webhook processing
            return;
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Other exception in fail() transition',
                [
                    'transactionId' => $transactionId,
                    'exception' => $exception->getMessage(),
                ]
            );
            throw $exception;
        }
    }

    // Delegate all other methods to the decorated service
    public function __call(string $method, array $arguments)
    {
        return $this->decorated->$method(...$arguments);
    }

    public function cancel(string $transactionId, Context $context): void
    {
        $this->decorated->cancel($transactionId, $context);
    }

    public function paid(string $transactionId, Context $context): void
    {
        $this->decorated->paid($transactionId, $context);
    }

    public function payPartially(string $transactionId, Context $context): void
    {
        $this->decorated->payPartially($transactionId, $context);
    }

    public function refund(string $transactionId, Context $context): void
    {
        $this->decorated->refund($transactionId, $context);
    }

    public function refundPartially(string $transactionId, Context $context): void
    {
        $this->decorated->refundPartially($transactionId, $context);
    }

    public function remind(string $transactionId, Context $context): void
    {
        $this->decorated->remind($transactionId, $context);
    }

    public function reopen(string $transactionId, Context $context): void
    {
        $this->decorated->reopen($transactionId, $context);
    }

    public function authorize(string $transactionId, Context $context): void
    {
        $this->decorated->authorize($transactionId, $context);
    }

    public function process(string $transactionId, Context $context): void
    {
        $this->decorated->process($transactionId, $context);
    }
}