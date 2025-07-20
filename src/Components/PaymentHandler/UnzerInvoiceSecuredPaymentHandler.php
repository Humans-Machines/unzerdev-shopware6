<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentHandler;

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use UnzerPayment6\Components\PaymentHandler\Exception\UnzerPaymentProcessException;
use UnzerPayment6\Components\PaymentHandler\Traits\CanCharge;
use UnzerPayment6\Components\PaymentHandler\Traits\HasTransferInfoTrait;
use UnzerSDK\Exceptions\UnzerApiException;

class UnzerInvoiceSecuredPaymentHandler extends AbstractUnzerPaymentHandler
{
    use HasTransferInfoTrait;
    use CanCharge;

    /**
     * {@inheritdoc}
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): RedirectResponse
    {
        parent::pay($request, $transaction, $context, $validateStruct);
        $currentRequest = $this->getCurrentRequestFromStack($transaction->getOrderTransactionId());
        $birthday = $currentRequest->get('unzerPaymentBirthday', '');

        $orderTransaction = $this->getOrderTransactionById($transaction->getOrderTransactionId(), $context);

        try {
            if (!empty($birthday)
                && (empty($this->unzerCustomer->getBirthDate()) || $birthday !== $this->unzerCustomer->getBirthDate())) {
                $this->unzerCustomer->setBirthDate($birthday);
                $this->unzerCustomer = $this->unzerClient->createOrUpdateCustomer($this->unzerCustomer);
            }

            $returnUrl = $this->charge($transaction->getReturnUrl());
            $this->saveTransferInfo($orderTransaction, $context);

            return new RedirectResponse($returnUrl);
        } catch (UnzerApiException $apiException) {
            $this->logger->error(
                sprintf('Caught an API exception in %s of %s', __METHOD__, __CLASS__),
                [
                    'request' => $this->getLoggableRequest($currentRequest),
                    'transaction' => $transaction,
                    'exception' => $apiException,
                ]
            );

            $this->executeFailTransition(
                $transaction->getOrderTransactionId(),
                $context
            );

            throw new UnzerPaymentProcessException($orderTransaction->getOrderId(), $transaction->getOrderTransactionId(), $apiException);
        } catch (Throwable $exception) {
            $this->logger->error(
                sprintf('Caught a generic exception in %s of %s', __METHOD__, __CLASS__),
                [
                    'request' => $this->getLoggableRequest($currentRequest),
                    'transaction' => $transaction,
                    'exception' => $exception,
                ]
            );

            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), $exception->getMessage());
        }
    }
}
