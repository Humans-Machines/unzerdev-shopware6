<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentHandler\Exception;

use Shopware\Core\Checkout\Payment\PaymentException;
use Symfony\Component\HttpFoundation\Response;

class PaymentPendingException extends PaymentException
{
    public function __construct(
        string $orderTransactionId,
        string $paymentMethod = 'Payment',
        ?\Throwable $previous = null
    ) {
        $message = sprintf(
            '%s payment is still being processed. Please wait a moment and refresh the page. Your order will be automatically confirmed once the payment is completed.',
            $paymentMethod
        );

        parent::__construct(
            Response::HTTP_BAD_REQUEST,
            'UNZER_PAYMENT_PENDING',
            $message,
            [],
            $previous
        );
    }
}