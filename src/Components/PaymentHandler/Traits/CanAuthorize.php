<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentHandler\Traits;

use RuntimeException;
use UnzerSDK\Resources\EmbeddedResources\RiskData;
use UnzerSDK\Resources\TransactionTypes\Authorization;

trait CanAuthorize
{
    public function authorize(
        string $returnUrl,
        ?float $amount = null,
        ?string $recurrenceType = null,
        ?RiskData $riskData = null
    ): string {
        if ($this->unzerClient === null) {
            throw new RuntimeException('UnzerClient can not be null');
        }

        if (!method_exists($this->unzerClient, 'performAuthorization')) {
            throw new RuntimeException('The SDK Version is older then expected');
        }

        if ($this->paymentType === null) {
            throw new RuntimeException('PaymentType can not be null');
        }

        // Transform return URL for reverse proxy setups if available
        $transformedReturnUrl = method_exists($this, 'transformReturnUrl') && $this->currentRequest
            ? $this->transformReturnUrl($returnUrl, $this->currentRequest)
            : $returnUrl;

        $authorization = new Authorization(
            $amount ?? $this->unzerBasket->getTotalValueGross(),
            $this->unzerBasket->getCurrencyCode(),
            $transformedReturnUrl
        );

        $authorization->setOrderId($this->unzerBasket->getOrderId());
        $authorization->setCard3ds(true);

        if ($recurrenceType !== null) {
            $authorization->setRecurrenceType($recurrenceType);
        }

        if ($riskData !== null) {
            $authorization->setRiskData($riskData);
        }

        $this->logger->error(json_encode([
            $authorization,
            $this->paymentType,
            $this->unzerCustomer,
            $this->unzerMetadata,
            $this->unzerBasket
        ]));
        $paymentResult = $this->unzerClient->performAuthorization(
            $authorization,
            $this->paymentType,
            $this->unzerCustomer,
            $this->unzerMetadata,
            $this->unzerBasket
        );

        $this->payment = $paymentResult->getPayment();

        if ($this->payment !== null && !empty($paymentResult->getRedirectUrl())) {
            return $paymentResult->getRedirectUrl();
        }

        return $returnUrl;
    }
}
