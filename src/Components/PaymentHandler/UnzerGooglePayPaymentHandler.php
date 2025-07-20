<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentHandler;

use Exception;
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
use UnzerPayment6\Components\BookingMode;
use UnzerPayment6\Components\ConfigReader\ConfigReader;
use UnzerPayment6\Components\PaymentHandler\Exception\UnzerPaymentProcessException;
use UnzerPayment6\Components\PaymentHandler\Traits\CanAuthorize;
use UnzerPayment6\Components\PaymentHandler\Traits\CanCharge;
use UnzerSDK\Exceptions\UnzerApiException;
use UnzerSDK\Resources\PaymentTypes\BasePaymentType;
use UnzerSDK\Resources\PaymentTypes\Card;
use UnzerSDK\Unzer;

class UnzerGooglePayPaymentHandler extends AbstractUnzerPaymentHandler
{
    use CanCharge;
    use CanAuthorize;

    /** @var BasePaymentType|Card */
    protected $paymentType;

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

        if ($this->paymentType === null) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), 'Can not process payment without a valid payment resource.');
        }

        $bookingMode = $this->pluginConfig->get(ConfigReader::CONFIG_KEY_GOOGLE_PAY_BOOKING_MODE, BookingMode::CHARGE);

        $dataBag = new RequestDataBag($request->request->all());
        try {
            $returnUrl = $bookingMode === BookingMode::CHARGE
                ? $this->charge($transaction->getReturnUrl())
                : $this->authorize($transaction->getReturnUrl(), $this->unzerBasket->getTotalValueGross());

            return new RedirectResponse($returnUrl);
        } catch (UnzerApiException $apiException) {
            $this->logger->error(
                sprintf('Caught an API exception in %s of %s', __METHOD__, __CLASS__),
                [
                    'dataBag' => $dataBag,
                    'transaction' => $transaction,
                    'exception' => $apiException,
                ]
            );

            $this->executeFailTransition(
                $transaction->getOrderTransactionId(),
                $context
            );

            $orderTransaction = $this->getOrderTransactionById($transaction->getOrderTransactionId(), $context);
            throw new UnzerPaymentProcessException($orderTransaction->getOrderId(), $transaction->getOrderTransactionId(), $apiException);
        } catch (Throwable $exception) {
            $this->logger->error(
                sprintf('Caught a generic exception in %s of %s', __METHOD__, __CLASS__),
                [
                    'dataBag' => $dataBag,
                    'transaction' => $transaction,
                    'exception' => $exception,
                ]
            );

            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), $exception->getMessage());
        }
    }

    public static function fetchChannelId(Unzer $client): string
    {
        try {
            $keyPair = $client->fetchKeyPair(true);
            foreach ($keyPair->getPaymentTypes() as $paymentType) {
                if ($paymentType->type === 'googlepay') {
                    $channelId = $paymentType->supports[0]->channel ?? null;
                    if ($channelId) {
                        return $channelId;
                    }
                }
            }
        } catch (Exception $e) {
            //silent to return '' at the end
        }
        // will only be reached, if no channel id was found
        return '';
    }
}
