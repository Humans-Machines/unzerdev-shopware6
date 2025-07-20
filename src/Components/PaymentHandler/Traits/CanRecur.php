<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentHandler\Traits;

use RuntimeException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use UnzerPayment6\Installer\PaymentInstaller;
use UnzerSDK\Exceptions\UnzerApiException;
use UnzerSDK\Resources\AbstractUnzerResource;
use UnzerSDK\Unzer;

/**
 * @property EntityRepository $transactionRepository
 */
trait CanRecur
{
    /** @var string */
    protected $sessionIsRecurring = 'UnzerPaymentIsRecurring';

    /** @var string */
    protected $sessionPaymentTypeKey = 'UnzerPaymentTypeId';

    /** @var string */
    protected $sessionCustomerIdKey = 'UnzerPaymentCustomerId';

    /**
     * @throws UnzerApiException
     */
    public function activateRecurring(string $returnUrl, ?string $recurrenceType = null): string
    {
        if ($this->paymentType === null) {
            throw new RuntimeException('PaymentType can not be null');
        }

        if (!method_exists($this->paymentType, 'activateRecurring')) {
            throw new RuntimeException('This payment type does not support recurring');
        }

        $this->recurring = $this->paymentType->activateRecurring($returnUrl, $recurrenceType);

        if ($this->recurring !== null && !empty($this->recurring->getRedirectUrl())) {
            return $this->recurring->getRedirectUrl();
        }

        return $returnUrl;
    }

    /**
     * @throws UnzerApiException
     */
    public function fetchPaymentByTypeId(string $paymentTypeId): ?AbstractUnzerResource
    {
        if ($this->unzerClient === null || !($this->unzerClient instanceof Unzer)) {
            return null;
        }

        return $this->unzerClient->fetchPaymentType($paymentTypeId);
    }

    protected function recur(
        PaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ): void {
        $orderTransaction = $this->fetchTransactionById($transaction->getOrderTransactionId(), $salesChannelContext->getContext());

        if ($orderTransaction === null) {
            throw new RuntimeException('Order transaction not found for transaction ID: ' . $transaction->getOrderTransactionId());
        }

        $this->unzerBasket   = $this->basketHydrator->hydrateObject($salesChannelContext, $orderTransaction);
        $this->unzerMetadata = $this->metadataHydrator->hydrateObject($salesChannelContext, $orderTransaction);

        $paymentMethodId = $orderTransaction->getPaymentMethodId();
        $this->unzerCustomer = $this->getUnzerCustomer($orderTransaction->getCustomFields()[$this->sessionCustomerIdKey] ?? '', $paymentMethodId, $salesChannelContext);
    }

    protected function fetchTransactionById(string $transactionId, Context $context): ?OrderTransactionEntity
    {
        $transactionCriteria = new Criteria([$transactionId]);
        $transactionCriteria->addAssociation('order');
        $transactionCriteria->addAssociation('order.currency');
        $transactionCriteria->addAssociation('order.lineItems');
        $transactionCriteria->addAssociation('order.deliveries');

        $transactionSearchResult = $this->transactionRepository->search($transactionCriteria, $context);

        return $transactionSearchResult->first();
    }
}
