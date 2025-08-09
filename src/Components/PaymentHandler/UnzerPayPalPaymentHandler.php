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
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;
use UnzerPayment6\Components\BookingMode;
use UnzerPayment6\Components\ClientFactory\ClientFactoryInterface;
use UnzerPayment6\Components\ConfigReader\ConfigReader;
use UnzerPayment6\Components\ConfigReader\ConfigReaderInterface;
use UnzerPayment6\Components\CustomFieldsHelper\CustomFieldsHelperInterface;
use UnzerPayment6\Components\PaymentHandler\Exception\UnzerPaymentProcessException;
use UnzerPayment6\Components\PaymentHandler\Traits\CanAuthorize;
use UnzerPayment6\Components\PaymentHandler\Traits\CanCharge;
use UnzerPayment6\Components\PaymentHandler\Traits\CanRecur;
use UnzerPayment6\Components\PaymentHandler\Traits\HasDeviceVault;
use UnzerPayment6\Components\ResourceHydrator\CustomerResourceHydrator\CustomerResourceHydratorInterface;
use UnzerPayment6\Components\ResourceHydrator\ResourceHydratorInterface;
use UnzerPayment6\Components\Struct\KeyPairContext;
use UnzerPayment6\Components\TransactionStateHandler\TransactionStateHandlerInterface;
use UnzerPayment6\DataAbstractionLayer\Entity\PaymentDevice\UnzerPaymentDeviceEntity;
use UnzerPayment6\DataAbstractionLayer\Repository\PaymentDevice\UnzerPaymentDeviceRepositoryInterface;
use UnzerPayment6\Installer\CustomFieldInstaller;
use UnzerSDK\Exceptions\UnzerApiException;
use UnzerSDK\Resources\Payment;
use UnzerSDK\Resources\PaymentTypes\BasePaymentType;
use UnzerSDK\Resources\PaymentTypes\Paypal;
use function array_key_exists;


/**
 * @property Payment $payment
 */
class UnzerPayPalPaymentHandler extends AbstractUnzerPaymentHandler
{
    use CanCharge;
    use CanAuthorize;
    use CanRecur;
    use HasDeviceVault;

    public const REMEMBER_PAYPAL_ACCOUNT_KEY = 'payPalRemember';

    /** @var BasePaymentType|Paypal */
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
        $currentRequest = $this->getCurrentRequestFromStack($transaction->getOrderTransactionId());

        $salesChannelContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);


        if (!empty($this->paymentType)) {
            return $this->handleRecurringPayment($transaction, $salesChannelContext);
        }

        $bookingMode = $this->pluginConfig->get(ConfigReader::CONFIG_KEY_BOOKING_MODE_PAYPAL, BookingMode::CHARGE);

        $dataBag = new RequestDataBag($request->request->all());

        try {
            if ($this->paymentType === null) {
                $registerAccounts = $dataBag->has(self::REMEMBER_PAYPAL_ACCOUNT_KEY);
                $payPalPaymentType = new Paypal();

                if (!empty($this->unzerCustomer->getEmail())) {
                    $payPalPaymentType->setEmail($this->unzerCustomer->getEmail());
                }

                $this->paymentType = $this->unzerClient->createPaymentType($payPalPaymentType);

                if ($registerAccounts && $salesChannelContext->getCustomer() !== null && $salesChannelContext->getCustomer()->getGuest() === false) {
                    $returnUrl = $this->activateRecurring($transaction->getReturnUrl());

                    if ($this->recurring !== null && !empty($this->recurring->getRedirectUrl())) {
                        $this->persistPaymentInformation(
                            [
                                CustomFieldInstaller::UNZER_PAYMENT_PAYMENT_ID_KEY => $this->paymentType->getId(),
                                $this->sessionPaymentTypeKey => $this->paymentType->getId(),
                                $this->sessionCustomerIdKey => $this->unzerCustomer->getId(),
                                self::REMEMBER_PAYPAL_ACCOUNT_KEY => true,
                            ],
                            $transaction->getOrderTransactionId(),
                            $context
                        );
                    }

                    // Transform return URL for reverse proxy setups
                    $transformedReturnUrl = $this->transformReturnUrl($returnUrl, $request);
                    return new RedirectResponse($transformedReturnUrl);
                }
            }

            $returnUrl = $bookingMode === BookingMode::CHARGE
                ? $this->charge($transaction->getReturnUrl())
                : $this->authorize($transaction->getReturnUrl());

            $this->persistPaymentInformation(
                [
                    $this->sessionIsRecurring => true,
                    $this->sessionPaymentTypeKey => $this->payment->getId(),
                    CustomFieldInstaller::UNZER_PAYMENT_PAYMENT_ID_KEY => $this->payment->getId(),
                ],
                $transaction->getOrderTransactionId(),
                $context
            );

            // Transform return URL for reverse proxy setups
            $transformedReturnUrl = $this->transformReturnUrl($returnUrl, $request);
            return new RedirectResponse($transformedReturnUrl);
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

            $orderTransaction = $this->getOrderTransactionById($transaction->getOrderTransactionId(), $context);
            throw new UnzerPaymentProcessException($orderTransaction->getOrderId(), $transaction->getOrderTransactionId(), $apiException);
        } catch (Throwable $exception) {
            $this->logger->error(
                sprintf('Caught a generic exception in %s of %s', __METHOD__, __CLASS__),
                [
                    'request' => $this->getLoggableRequest($currentRequest),
                    'dataBag' => $dataBag,
                    'exception' => $exception,
                ]
            );

            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), $exception->getMessage());
        }
    }

    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void
    {
        $salesChannelContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        $this->pluginConfig = $this->configReader->read($salesChannelContext->getSalesChannelId());

        $bookingMode = $this->pluginConfig->get(ConfigReader::CONFIG_KEY_BOOKING_MODE_PAYPAL, BookingMode::CHARGE);

        $orderTransaction = $this->getOrderTransactionById($transaction->getOrderTransactionId(), $context);

        $transactionCustomFields = $orderTransaction->getCustomFields();
        $registerAccounts = !empty($transactionCustomFields[self::REMEMBER_PAYPAL_ACCOUNT_KEY]);

        $this->unzerClient = $this->clientFactory->createClient(
            KeyPairContext::createFromSalesChannelContext($salesChannelContext)
        );

        if (!$registerAccounts) {
            $this->logger->info(
                'PayPal payment finalization - delegating to parent (no account registration)',
                ['transactionId' => $transaction->getOrderTransactionId()]
            );
            parent::finalize($request, $transaction, $context);
            return;
        }

        if ($transactionCustomFields === null || !array_key_exists(CustomFieldInstaller::UNZER_PAYMENT_PAYMENT_ID_KEY, $transactionCustomFields)) {
            $this->logger->error(
                'PayPal payment finalization failed - missing payment ID in custom fields',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'customFields' => $transactionCustomFields,
                ]
            );
            throw PaymentException::asyncFinalizeInterrupted($transaction->getOrderTransactionId(), 'missing payment id');
        }

        $this->recur($transaction, $salesChannelContext);

        try {
            if (!($transactionCustomFields[$this->sessionIsRecurring] ?? false)) {
                /** @phpstan-ignore-next-line */
                $this->paymentType = $this->fetchPaymentByTypeId($transactionCustomFields[$this->sessionPaymentTypeKey]);

                if ($this->paymentType === null) {
                    throw PaymentException::asyncFinalizeInterrupted($transaction->getOrderTransactionId(), 'missing payment type');
                }

                /** Return URLs are required by API but not used in finalize context */
                $bookingMode === BookingMode::CHARGE
                    ? $this->charge('#')
                    : $this->authorize('#');

                if ($registerAccounts
                    && $salesChannelContext->getCustomer() !== null
                    && $salesChannelContext->getCustomer()->getGuest() === false
                    && $this->paymentType instanceof Paypal
                    && $this->paymentType->getEmail() !== null
                ) {
                    $this->saveToDeviceVault(
                        $salesChannelContext->getCustomer(),
                        UnzerPaymentDeviceEntity::DEVICE_TYPE_PAYPAL,
                        $salesChannelContext->getContext()
                    );
                }
            } else {
                $this->payment = $this->unzerClient->fetchPayment($transactionCustomFields[CustomFieldInstaller::UNZER_PAYMENT_PAYMENT_ID_KEY]);
            }

            $this->transactionStateHandler->transformTransactionState(
                $transaction->getOrderTransactionId(),
                $this->payment,
                $context
            );

            $this->customFieldsHelper->setOrderTransactionCustomFields($orderTransaction, $context);
        } catch (UnzerApiException $apiException) {
            $this->logger->error(
                'PayPal API exception during finalization',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'apiCode' => $apiException->getCode(),
                    'apiMessage' => $apiException->getMessage(),
                    'exception' => $apiException,
                ]
            );

            if (in_array($apiException->getCode(), ['API.410.100.100', 'API.404.100.100'])) {
                throw PaymentException::asyncFinalizeInterrupted($transaction->getOrderTransactionId(), $apiException->getMessage());
            }


        } catch (Throwable $exception) {
            $this->logger->error(
                'PayPal generic exception during finalization',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'exceptionType' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]
            );
        }
    }

    protected function handleRecurringPayment(
        PaymentTransactionStruct $transaction,
        SalesChannelContext           $salesChannelContext
    ): RedirectResponse
    {
        try {
            $bookingMode = $this->pluginConfig->get(ConfigReader::CONFIG_KEY_BOOKING_MODE_PAYPAL, BookingMode::CHARGE);

            $returnUrl = $bookingMode === BookingMode::CHARGE
                ? $this->charge($transaction->getReturnUrl())
                : $this->authorize($transaction->getReturnUrl());

            $this->persistPaymentInformation(
                [
                    $this->sessionIsRecurring => true,
                    $this->sessionPaymentTypeKey => $this->payment->getId(),
                    CustomFieldInstaller::UNZER_PAYMENT_PAYMENT_ID_KEY => $this->payment->getId(),
                ],
                $transaction->getOrderTransactionId(),
                $salesChannelContext->getContext()
            );

            // Transform return URL for reverse proxy setups
            $transformedReturnUrl = $this->transformReturnUrl($returnUrl, $this->getCurrentRequestFromStack($transaction->getOrderTransactionId()));
            return new RedirectResponse($transformedReturnUrl);
        } catch (UnzerApiException $apiException) {
            $this->logger->error(
                sprintf('Caught an API exception in %s of %s', __METHOD__, __CLASS__),
                [
                    'transaction' => $transaction,
                    'exception' => $apiException,
                ]
            );

            $this->executeFailTransition(
                $transaction->getOrderTransactionId(),
                $salesChannelContext->getContext()
            );

            $orderTransaction = $this->getOrderTransactionById($transaction->getOrderTransactionId(), $salesChannelContext->getContext());
            throw new UnzerPaymentProcessException($orderTransaction->getOrderId(), $transaction->getOrderTransactionId(), $apiException);
        } catch (Throwable $exception) {
            $this->logger->error(
                sprintf('Caught a generic exception in %s of %s', __METHOD__, __CLASS__),
                [
                    'transaction' => $transaction,
                    'exception' => $exception,
                ]
            );

            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), $exception->getMessage());
        }
    }
}
