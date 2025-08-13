<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentHandler;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\PlatformRequest;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use UnzerPayment6\Components\PaymentHandler\Exception\UnzerPaymentProcessException;
use UnzerPayment6\Components\PaymentHandler\Traits\CanAuthorize;
use UnzerPayment6\Components\PaymentHandler\Traits\CanCharge;
use UnzerPayment6\Components\PaymentHandler\Traits\HasRiskDataTrait;
use UnzerPayment6\Components\PaymentHandler\Traits\HasTransferInfoTrait;
use UnzerSDK\Exceptions\UnzerApiException;
use UnzerSDK\Resources\EmbeddedResources\CompanyInfo;

class UnzerPaylaterInvoicePaymentHandler extends AbstractUnzerPaymentHandler
{
    use HasTransferInfoTrait;
    use CanAuthorize;
    use CanCharge;
    use HasRiskDataTrait;

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

        $salesChannelContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        $currentRequest = $this->getCurrentRequestFromStack($transaction->getOrderTransactionId());

        try {
            $this->updateUnzerCustomer($currentRequest);

            $riskData = $this->generateRiskDataResource($transaction, $salesChannelContext);

            if (null === $riskData) {
                throw new \RuntimeException('fraud prevention session id is missing from the current request');
            }

            $orderTransaction = $this->getOrderTransactionById($transaction->getOrderTransactionId(), $context);

            $returnUrl = $this->authorize(
                $transaction->getReturnUrl(),
                $orderTransaction->getAmount()->getTotalPrice(),
                null,
                $riskData
            );

            $this->saveTransferInfoFromAuthorize($orderTransaction, $context);

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

            throw new UnzerPaymentProcessException($orderTransaction?->getOrder()?->getId() ?? '-', $transaction->getOrderTransactionId(), $apiException);
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

    private function updateUnzerCustomer(Request $request): void
    {
        $birthday = $request->get('unzerPaymentBirthday', '');
        $companyType = $request->get('unzerPaymentCompanyType', '');
        $createOrUpdate = false;

        if (!empty($birthday)
            && (empty($this->unzerCustomer->getBirthDate()) || $birthday !== $this->unzerCustomer->getBirthDate())) {
            $createOrUpdate = true;
            $this->unzerCustomer->setBirthDate($birthday);
        }

        $companyInfo = $this->unzerCustomer->getCompanyInfo() ?? new CompanyInfo();

        if (!empty($companyType) && $companyInfo->getCompanyType() !== $companyType) {
            $createOrUpdate = true;
            $companyInfo->setCompanyType($companyType);
            $this->unzerCustomer->setCompanyInfo($companyInfo);
        }

        if (!$createOrUpdate) {
            return;
        }

        $this->unzerCustomer = $this->unzerClient->createOrUpdateCustomer($this->unzerCustomer);
    }
}
