<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentHandler;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;
use UnzerPayment6\Components\ClientFactory\ClientFactoryInterface;
use UnzerPayment6\Components\ConfigReader\ConfigReaderInterface;
use UnzerPayment6\Components\CustomFieldsHelper\CustomFieldsHelperInterface;
use UnzerPayment6\Components\PaymentHandler\Exception\UnzerPaymentProcessException;
use UnzerPayment6\Components\PaymentHandler\Traits\CanCharge;
use UnzerPayment6\Components\PaymentHandler\Traits\HasDeviceVault;
use UnzerPayment6\Components\ResourceHydrator\CustomerResourceHydrator\CustomerResourceHydratorInterface;
use UnzerPayment6\Components\ResourceHydrator\ResourceHydratorInterface;
use UnzerPayment6\Components\TransactionStateHandler\TransactionStateHandlerInterface;
use UnzerPayment6\DataAbstractionLayer\Entity\PaymentDevice\UnzerPaymentDeviceEntity;
use UnzerPayment6\DataAbstractionLayer\Repository\PaymentDevice\UnzerPaymentDeviceRepositoryInterface;
use UnzerSDK\Exceptions\UnzerApiException;
use UnzerSDK\Resources\PaymentTypes\BasePaymentType;
use UnzerSDK\Resources\PaymentTypes\SepaDirectDebit;

class UnzerDirectDebitPaymentHandler extends AbstractUnzerPaymentHandler
{
    use CanCharge;
    use HasDeviceVault;

    public const REMEMBER_SEPA_MANDATE_KEY = 'rememberSepaMandate';

    /** @var BasePaymentType|SepaDirectDebit */
    protected $paymentType;

    public function __construct(
        ResourceHydratorInterface             $basketHydrator,
        CustomerResourceHydratorInterface     $customerHydrator,
        ResourceHydratorInterface             $metadataHydrator,
        EntityRepository                      $transactionRepository,
        ConfigReaderInterface                 $configReader,
        TransactionStateHandlerInterface      $transactionStateHandler,
        ClientFactoryInterface                $clientFactory,
        RequestStack                          $requestStack,
        LoggerInterface                       $logger,
        CustomFieldsHelperInterface           $customFieldsHelper,
        UnzerPaymentDeviceRepositoryInterface $deviceRepository,
        AbstractSalesChannelContextFactory    $salesChannelContextFactory
    )
    {
        parent::__construct(
            $basketHydrator,
            $customerHydrator,
            $metadataHydrator,
            $transactionRepository,
            $configReader,
            $transactionStateHandler,
            $clientFactory,
            $requestStack,
            $logger,
            $customFieldsHelper,
            $salesChannelContextFactory
        );

        $this->deviceRepository = $deviceRepository;
    }

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

        if (!$this->isPaymentAllowed($transaction->getOrderTransactionId())) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), 'SEPA direct debit mandate has not been accepted by the customer.');
        }

        $salesChannelContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        $dataBag = new RequestDataBag($request->request->all());
        $registerDirectDebit = $dataBag->has(self::REMEMBER_SEPA_MANDATE_KEY);

        try {
            $returnUrl = $this->charge($transaction->getReturnUrl());

            if ($registerDirectDebit && $salesChannelContext->getCustomer() !== null && $salesChannelContext->getCustomer()->getGuest() === false) {
                $this->saveToDeviceVault(
                    $salesChannelContext->getCustomer(),
                    UnzerPaymentDeviceEntity::DEVICE_TYPE_DIRECT_DEBIT,
                    $context
                );
            }

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
            throw new UnzerPaymentProcessException($orderTransaction->getOrder()->getId(), $transaction->getOrderTransactionId(), $apiException);
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

    private function isPaymentAllowed(string $transactionId): bool
    {
        $currentRequest = $this->getCurrentRequestFromStack($transactionId);

        $isSepaAccepted = ((string)$currentRequest->get('acceptSepaMandate', 'off')) === 'on';
        $isNewAccount = ((string)$currentRequest->get('savedDirectDebitDevice', 'new')) === 'new';

        return ($isSepaAccepted && $isNewAccount) || !$isNewAccount;
    }
}
