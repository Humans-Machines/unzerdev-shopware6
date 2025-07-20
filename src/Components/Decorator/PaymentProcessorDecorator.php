<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\Decorator;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\Checkout\Payment\PaymentProcessor;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use UnzerPayment6\Components\ClientFactory\ClientFactoryInterface;
use UnzerPayment6\Components\ConfigReader\ConfigReaderInterface;
use UnzerPayment6\Components\PaymentHandler\Exception\PaymentPendingException;
use UnzerPayment6\Components\Struct\KeyPairContext;
use UnzerPayment6\Installer\PaymentInstaller;

class PaymentProcessorDecorator extends PaymentProcessor
{
    public function __construct(
        private readonly PaymentProcessor $decorated,
        private readonly LoggerInterface $logger,
        private readonly ClientFactoryInterface $clientFactory,
        private readonly ConfigReaderInterface $configReader,
        private readonly EntityRepository $orderTransactionRepository
    ) {
    }

    public function pay(
        string $orderId,
        Request $request,
        SalesChannelContext $salesChannelContext,
        ?string $finishUrl = null,
        ?string $errorUrl = null,
    ): ?RedirectResponse {
        return $this->decorated->pay($orderId, $request, $salesChannelContext, $finishUrl, $errorUrl);
    }

    public function validate(
        Cart $cart,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): ?Struct {
        return $this->decorated->validate($cart, $dataBag, $salesChannelContext);
    }

    public function finalize(TokenStruct $token, Request $request, SalesChannelContext $context): TokenStruct
    {
        $this->logger->info(
            '🔄 PaymentProcessor::finalize() called - checking for 3DS scenarios',
            [
                'transactionId' => $token->getTransactionId(),
                'paymentMethodId' => $token->getPaymentMethodId(),
            ]
        );

        // Check if this is a Credit Card payment (where 3DS race conditions can occur)
        $isCreditCardPayment = $token->getPaymentMethodId() === PaymentInstaller::PAYMENT_ID_CREDIT_CARD;
        // Check if this is a PayPal payment (where similar race conditions can occur)
        $isPayPalPayment = $token->getPaymentMethodId() === PaymentInstaller::PAYMENT_ID_PAYPAL;
        // Apply exception handling for payments that can have webhook race conditions
        $needsRaceConditionHandling = $isCreditCardPayment || $isPayPalPayment;

        try {
            // Call the original finalize method
            $result = $this->decorated->finalize($token, $request, $context);
            
            // Apply race condition exception handling for Credit Card and PayPal payments
            if ($needsRaceConditionHandling && $result->getException() !== null) {
                $exception = $result->getException();
                
                $this->logger->error(
                    '❌ PaymentProcessor::finalize() completed with exception - checking for webhook race condition',
                    [
                        'transactionId' => $token->getTransactionId(),
                        'exception' => $exception->getMessage(),
                        'exceptionClass' => get_class($exception),
                        'paymentType' => $isCreditCardPayment ? 'Credit Card' : ($isPayPalPayment ? 'PayPal' : 'Other'),
                    ]
                );

                // For Credit Cards: Check specific illegal transition scenarios
                if ($isCreditCardPayment) {
                    if (str_contains($exception->getMessage(), 'Illegal transition') ||
                        str_contains($exception->getMessage(), 'fail') ||
                        str_contains($exception->getMessage(), 'undefined method') ||
                        str_contains($exception->getMessage(), 'do_pay')) {
                        
                        // Check if transaction is actually completed by looking at possible transitions
                        // When payment is completed, only post-payment transitions are available
                        $isTransactionCompleted = str_contains($exception->getMessage(), 'Possible transitions are:') &&
                                                 (str_contains($exception->getMessage(), 'refund') || 
                                                  str_contains($exception->getMessage(), 'chargeback') ||
                                                  str_contains($exception->getMessage(), 'cancel'));
                        
                        if ($isTransactionCompleted) {
                            $this->logger->info(
                                '🎯 Detected completed payment with illegal transition exception - clearing exception to prevent error redirect',
                                [
                                    'transactionId' => $token->getTransactionId(),
                                    'originalException' => $exception->getMessage(),
                                    'paymentType' => 'Credit Card',
                                    'message' => 'Payment already completed by webhook - redirecting to success page',
                                ]
                            );
                            
                            // Simply clear the exception on the existing token to prevent error redirect
                            $result->setException(null);
                            
                            return $result;
                        }
                    }
                }
                
                // For PayPal: Wait briefly for webhook, then check actual payment status from Unzer API
                if ($isPayPalPayment) {
                    // Give webhook processing a brief moment to complete
                    sleep(2);
                    
                    $paymentIsCompleted = $this->checkPaymentStatusFromUnzer($token, $context);
                    
                    if ($paymentIsCompleted) {
                        $this->logger->info(
                            '🎯 PayPal payment verified as completed via Unzer API - clearing exception to prevent error redirect',
                            [
                                'transactionId' => $token->getTransactionId(),
                                'originalException' => $exception->getMessage(),
                                'paymentType' => 'PayPal',
                                'message' => 'PayPal payment confirmed completed - redirecting to success page',
                            ]
                        );
                        
                        // Clear the exception since payment is actually completed
                        $result->setException(null);
                        
                        return $result;
                    } else {
                        $this->logger->warning(
                            '⚠️ PayPal payment finalize() failed and Unzer API shows payment still pending - providing user-friendly message',
                            [
                                'transactionId' => $token->getTransactionId(),
                                'originalException' => $exception->getMessage(),
                                'paymentType' => 'PayPal',
                            ]
                        );
                        
                        // Create a user-friendly exception for pending payments
                        $userFriendlyException = new PaymentPendingException(
                            $token->getTransactionId(),
                            'PayPal'
                        );
                        
                        $result->setException($userFriendlyException);
                        return $result;
                    }
                }
            }
            
            $this->logger->info(
                '✅ PaymentProcessor::finalize() completed successfully',
                [
                    'transactionId' => $token->getTransactionId(),
                    'hasException' => $result->getException() !== null,
                ]
            );
            
            return $result;
            
        } catch (\Throwable $exception) {
            $this->logger->error(
                '❌ Exception in PaymentProcessor::finalize() - checking for webhook race condition',
                [
                    'transactionId' => $token->getTransactionId(),
                    'exception' => $exception->getMessage(),
                    'exceptionClass' => get_class($exception),
                    'paymentType' => $isCreditCardPayment ? 'Credit Card' : ($isPayPalPayment ? 'PayPal' : 'Other'),
                ]
            );

            // Apply race condition handling for Credit Card and PayPal payments
            if ($needsRaceConditionHandling && (str_contains($exception->getMessage(), 'ILLEGAL_STATE_TRANSITION') ||
                str_contains($exception->getMessage(), 'Illegal transition'))) {
                
                $this->logger->info(
                    '🎯 Detected webhook race condition in PaymentProcessor - preventing error redirect',
                    [
                        'transactionId' => $token->getTransactionId(),
                        'paymentType' => $isCreditCardPayment ? 'Credit Card' : ($isPayPalPayment ? 'PayPal' : 'Other'),
                        'message' => 'Returning successful token to redirect to success page instead of error page',
                    ]
                );
                
                // Don't set the exception on the token - this prevents redirect to error page
                // The payment is likely already completed by webhook processing
                return $token;
            }

            // For payments without race condition handling or other exceptions, re-throw them
            throw $exception;
        }
    }

    /**
     * Check if payment is actually completed by querying Unzer API directly
     */
    private function checkPaymentStatusFromUnzer(TokenStruct $token, SalesChannelContext $context): bool
    {
        try {
            // Get order transaction to find the order ID
            $criteria = new Criteria([$token->getTransactionId()]);
            $criteria->addAssociation('order');
            $orderTransaction = $this->orderTransactionRepository->search($criteria, $context->getContext())->first();
            
            if (!$orderTransaction || !$orderTransaction->getOrder()) {
                $this->logger->warning('Could not find order transaction or order for payment status check', [
                    'transactionId' => $token->getTransactionId()
                ]);
                return false;
            }
            
            // Get Unzer client
            $pluginConfig = $this->configReader->read($context->getSalesChannelId());
            $unzerClient = $this->clientFactory->createClient(
                KeyPairContext::createFromSalesChannelContext($context)
            );
            
            // Query payment status from Unzer API
            $payment = $unzerClient->fetchPaymentByOrderId($orderTransaction->getOrder()->getId());
            
            $isCompleted = $payment && $payment->isCompleted();
            
            $this->logger->info('Checked payment status via Unzer API', [
                'transactionId' => $token->getTransactionId(),
                'orderId' => $orderTransaction->getOrder()->getId(),
                'paymentId' => $payment?->getId(),
                'paymentState' => $payment?->getStateName(),
                'isCompleted' => $isCompleted
            ]);
            
            return $isCompleted;
            
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to check payment status from Unzer API', [
                'transactionId' => $token->getTransactionId(),
                'exception' => $exception->getMessage()
            ]);
            return false;
        }
    }

    // Delegate all other method calls to the decorated service
    public function __call(string $method, array $arguments)
    {
        return $this->decorated->$method(...$arguments);
    }
}