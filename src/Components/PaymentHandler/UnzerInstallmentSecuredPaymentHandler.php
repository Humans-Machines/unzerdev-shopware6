<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentHandler;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use UnzerPayment6\Components\PaymentHandler\Exception\UnzerPaymentProcessException;
use UnzerPayment6\Components\PaymentHandler\Traits\CanAuthorize;
use UnzerPayment6\UnzerPayment6;
use UnzerSDK\Exceptions\UnzerApiException;

class UnzerInstallmentSecuredPaymentHandler extends AbstractUnzerPaymentHandler
{
    use CanAuthorize;

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

        $this->unzerBasket->setTotalValueGross($this->unzerBasket->getTotalValueGross());

        $currentRequest = $this->getCurrentRequestFromStack($transaction->getOrderTransactionId());

        $birthday = $currentRequest->get('unzerPaymentBirthday', '');

        $order = $this->getOrderByTransactionId($transaction->getOrderTransactionId(), $context);

        try {
            if (!empty($birthday)
                && (empty($this->unzerCustomer->getBirthDate()) || $birthday !== $this->unzerCustomer->getBirthDate())) {
                $this->unzerCustomer->setBirthDate($birthday);
                $this->unzerClient->createOrUpdateCustomer($this->unzerCustomer);
            }

            /** @var int $currencyPrecision */
            $currencyPrecision = $order->getCurrency() !== null ? min(
                $order->getCurrency()->getItemRounding()->getDecimals(),
                UnzerPayment6::MAX_DECIMAL_PRECISION
            ) : UnzerPayment6::MAX_DECIMAL_PRECISION;

            $returnUrl = $this->authorize(
                $transaction->getReturnUrl(),
                round($order->getAmountTotal(), $currencyPrecision)
            );

            /** @phpstan-ignore-next-line */
            $this->payment->charge(round($order->getAmountTotal(), $currencyPrecision));

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

            throw new UnzerPaymentProcessException($order->getId(), $transaction->getOrderTransactionId(), $apiException);
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
