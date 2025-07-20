<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\WebhookHandler;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Exception\InvalidUuidException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use UnzerPayment6\Components\ClientFactory\ClientFactoryInterface;
use UnzerPayment6\Components\CustomFieldsHelper\CustomFieldsHelperInterface;
use UnzerPayment6\Components\Struct\Webhook;
use UnzerPayment6\Components\TransactionStateHandler\TransactionStateHandlerInterface;
use UnzerSDK\Resources\Payment;

/**
 * @property Payment $resource
 */
class PaymentStatusWebhookHandler implements WebhookHandlerInterface
{
    public function __construct(
        private readonly TransactionStateHandlerInterface $transactionStateHandler,
        private readonly ClientFactoryInterface $clientFactory,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly LoggerInterface $logger,
        private readonly CustomFieldsHelperInterface $customFieldsHelper
    ) {
    }

    public function supports(Webhook $webhook, SalesChannelContext $context): bool
    {
        return stripos($webhook->getEvent(), 'payment.') !== false;
    }

    public function execute(Webhook $webhook, SalesChannelContext $context): void
    {
        $this->logger->info(
            'Processing payment status webhook',
            [
                'event' => $webhook->getEvent(),
                'retrieveUrl' => $webhook->getRetrieveUrl(),
                'publicKey' => substr($webhook->getPublicKey(), 0, 10) . '...',
            ]
        );

        try {
            $client = $this->clientFactory->createClientFromPublicKey(
                $webhook->getPublicKey(), 
                $context->getSalesChannelId()
            );
            
            $payment = $client->getResourceService()->fetchResourceByUrl($webhook->getRetrieveUrl());

            if (!$payment instanceof Payment) {
                $this->logger->error(
                    'Webhook processing failed - invalid payment resource',
                    [
                        'event' => $webhook->getEvent(),
                        'retrieveUrl' => $webhook->getRetrieveUrl(),
                        'resourceType' => get_class($payment),
                    ]
                );
                return;
            }

            $this->logger->info(
                'Payment resource fetched successfully',
                [
                    'event' => $webhook->getEvent(),
                    'paymentId' => $payment->getId(),
                    'orderId' => $payment->getOrderId(),
                    'paymentState' => $payment->getStateName(),
                    'paymentAmount' => $payment->getAmount()?->getTotal(),
                ]
            );

            $transaction = $this->getOrderTransaction($payment->getOrderId(), $context->getContext());

            if ($transaction === null) {
                $this->logger->error(
                    'Webhook processing failed - transaction not found',
                    [
                        'event' => $webhook->getEvent(),
                        'paymentId' => $payment->getId(),
                        'orderId' => $payment->getOrderId(),
                    ]
                );
                return;
            }

            $this->logger->info(
                'Transaction found, updating custom fields',
                [
                    'event' => $webhook->getEvent(),
                    'transactionId' => $transaction->getId(),
                    'orderId' => $transaction->getOrderId(),
                ]
            );

            $this->customFieldsHelper->setOrderTransactionCustomFields($transaction, $context->getContext());

            $this->logger->info(
                'Transforming transaction state',
                [
                    'event' => $webhook->getEvent(),
                    'transactionId' => $transaction->getId(),
                    'currentPaymentState' => $payment->getStateName(),
                ]
            );

            $this->transactionStateHandler->transformTransactionState(
                $transaction->getId(),
                $payment,
                $context->getContext()
            );

            $this->logger->info(
                'Webhook processing completed successfully',
                [
                    'event' => $webhook->getEvent(),
                    'transactionId' => $transaction->getId(),
                    'finalPaymentState' => $payment->getStateName(),
                ]
            );

        } catch (\Throwable $exception) {
            $this->logger->error(
                'Webhook processing exception',
                [
                    'event' => $webhook->getEvent(),
                    'exceptionType' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]
            );
        }
    }

    private function getOrderTransaction(?string $orderId, Context $context): ?OrderTransactionEntity
    {
        if (empty($orderId)) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));

        try {
            $orderTransactions = $this->orderTransactionRepository->search($criteria, $context);

            return $orderTransactions->first();
        } catch (InvalidUuidException $exception) {
            $this->logger->error($exception->getMessage(), $exception->getTrace());

            return null;
        }
    }
}
