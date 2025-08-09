<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentHandler;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
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
use UnzerPayment6\Components\BookingMode;
use UnzerPayment6\Components\ClientFactory\ClientFactoryInterface;
use UnzerPayment6\Components\ConfigReader\ConfigReader;
use UnzerPayment6\Components\ConfigReader\ConfigReaderInterface;
use UnzerPayment6\Components\CustomFieldsHelper\CustomFieldsHelperInterface;
use UnzerPayment6\Components\PaymentHandler\Exception\UnzerPaymentProcessException;
use UnzerPayment6\Components\PaymentHandler\Traits\CanAuthorize;
use UnzerPayment6\Components\PaymentHandler\Traits\CanCharge;
use UnzerPayment6\Components\PaymentHandler\Traits\HasDeviceVault;
use UnzerPayment6\Components\ResourceHydrator\CustomerResourceHydrator\CustomerResourceHydratorInterface;
use UnzerPayment6\Components\ResourceHydrator\ResourceHydratorInterface;
use UnzerPayment6\Components\Struct\KeyPairContext;
use UnzerPayment6\Components\TransactionStateHandler\TransactionStateHandlerInterface;
use UnzerPayment6\DataAbstractionLayer\Entity\PaymentDevice\UnzerPaymentDeviceEntity;
use UnzerPayment6\DataAbstractionLayer\Repository\PaymentDevice\UnzerPaymentDeviceRepositoryInterface;
use UnzerSDK\Constants\RecurrenceTypes;
use UnzerSDK\Exceptions\UnzerApiException;
use UnzerSDK\Resources\PaymentTypes\BasePaymentType;
use UnzerSDK\Resources\PaymentTypes\Card;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;

class UnzerCreditCardPaymentHandler extends AbstractUnzerPaymentHandler
{
    use CanCharge;
    use CanAuthorize;
    use HasDeviceVault;

    public const REMEMBER_CREDIT_CARD_KEY = 'creditCardRemember';

    /** @var BasePaymentType|Card */
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
        $this->logger->info(
            'Starting pay()',
            [
                'transactionId' => $transaction->getOrderTransactionId(),
                'paymentHandler' => static::class,
            ]
        );
        parent::pay($request, $transaction, $context, $validateStruct);

        $salesChannelContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        if ($this->paymentType === null) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), 'Can not process payment without a valid payment resource.');
        }

        $customer = $salesChannelContext->getCustomer();
        $bookingMode = $this->pluginConfig->get(ConfigReader::CONFIG_KEY_BOOKING_MODE_CARD, BookingMode::CHARGE);

        $this->logger->info(
            'Processing pay()',
            [
                'transactionId' => $transaction->getOrderTransactionId(),
                'bookingMode' => $bookingMode,
                'paymentHandler' => static::class,
            ]
        );
        $dataBag = new RequestDataBag($request->request->all());

        $registerCreditCards = $dataBag->has(self::REMEMBER_CREDIT_CARD_KEY);
        $saveToDeviceVault = $this->canSaveToDeviceVault($registerCreditCards, $customer);

        try {
            $recurrenceType = ($this->deviceRepository->exists($this->paymentType->getId(), $context) || $saveToDeviceVault)
                ? RecurrenceTypes::ONE_CLICK
                : null;

            $returnUrl = $bookingMode === BookingMode::CHARGE
                ? $this->charge($transaction->getReturnUrl(), $recurrenceType)
                : $this->authorize($transaction->getReturnUrl(), $this->unzerBasket->getTotalValueGross(), $recurrenceType);

            // Log 3DS detection for debugging
            if ($this->payment && $this->payment->isPending() && !empty($returnUrl) && $returnUrl !== $transaction->getReturnUrl()) {
                $this->logger->info(
                    '3DS transaction detected - will be handled by transition mapper on webhook',
                    [
                        'transactionId' => $transaction->getOrderTransactionId(),
                        'paymentId' => $this->payment->getId(),
                        'paymentState' => $this->payment->getStateName(),
                        'redirectUrl' => $returnUrl,
                    ]
                );
            }

            $this->logger->info(
                'Redirecting in pay()',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'returnUrl' => $returnUrl,
                    'paymentHandler' => static::class,
                ]
            );
            if ($saveToDeviceVault) {
                $this->saveToDeviceVault(
                    $customer,
                    UnzerPaymentDeviceEntity::DEVICE_TYPE_CREDIT_CARD,
                    $context
                );
            }

            // Transform return URL for reverse proxy setups
            $transformedReturnUrl = $this->transformReturnUrl($returnUrl, $request);
            return new RedirectResponse($transformedReturnUrl);
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


    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void
    {
        $this->logger->info(
            'Starting Credit Card payment finalization',
            [
                'transactionId' => $transaction->getOrderTransactionId(),
                'requestParams' => $request->query->all(),
                'requestMethod' => $request->getMethod(),
            ]
        );

        // For 3DS transactions, we need to check payment status first to avoid illegal transitions
        $order = $this->getOrderByTransactionId($transaction->getOrderTransactionId(), $context);
        $salesChannelContext = $this->salesChannelContextFactory->create(
            '',
            $order->getSalesChannelId(),
            [
                'currencyId' => $order->getCurrencyId(),
                'languageId' => $order->getLanguageId(),
                'customerId' => $order->getOrderCustomer()?->getCustomerId(),
            ]
        );

        try {
            $this->pluginConfig = $this->configReader->read($salesChannelContext->getSalesChannelId());
            $this->unzerClient = $this->clientFactory->createClient(
                KeyPairContext::createFromSalesChannelContext($salesChannelContext)
            );

            $orderTransaction = $this->getOrderTransactionById($transaction->getOrderTransactionId(), $context);
            $payment = $this->unzerClient->fetchPaymentByOrderId($orderTransaction->getOrderId());

            $this->logger->info(
                'Pre-finalize payment status check',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'paymentId' => $payment?->getId(),
                    'paymentState' => $payment?->getStateName(),
                    'isPending' => $payment?->isPending(),
                    'isCompleted' => $payment?->isCompleted(),
                ]
            );

            // Check if this is a completed 3DS payment that may cause illegal transition
            if ($payment && $payment->isCompleted()) {
                // For completed payments, use our custom handling to avoid illegal transitions
                $this->handle3DSCompletedPayment($transaction, $context, $payment);
                return;
            }

            // For pending 3DS payments, let the PaymentProcessorDecorator handle race conditions
            if ($payment && $payment->isPending()) {
                $this->logger->info('Detected pending 3DS payment - deferring to PaymentProcessorDecorator for race condition handling');
                parent::finalize($request, $transaction, $context);
                return;
            }

            // For non-3DS or standard payments, use parent finalize
            parent::finalize($request, $transaction, $context);

        } catch (\Throwable $exception) {
            $this->logger->error(
                'Error during Credit Card payment finalization',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'exception' => $exception->getMessage(),
                ]
            );

            // Illegal transitions are now handled by PaymentProcessorDecorator
            $this->logger->info('Credit Card finalize error - will be handled by PaymentProcessorDecorator if race condition related');

            throw $exception;
        }
    }

    private function handle3DSCompletedPayment(PaymentTransactionStruct $transaction, Context $context, \UnzerSDK\Resources\Payment $payment): void
    {
        $this->logger->info(
            'Handling completed 3DS payment to avoid illegal transitions',
            [
                'transactionId' => $transaction->getOrderTransactionId(),
                'paymentId' => $payment->getId(),
                'paymentState' => $payment->getStateName(),
            ]
        );

        try {
            // Directly update transaction state to completed/paid without triggering transitions
            $this->transactionStateHandler->transformTransactionState(
                $transaction->getOrderTransactionId(),
                $payment,
                $context
            );

            // Set custom fields
            $orderTransaction = $this->getOrderTransactionById($transaction->getOrderTransactionId(), $context);
            $this->customFieldsHelper->setOrderTransactionCustomFields($orderTransaction, $context);

            $this->logger->info(
                '3DS completed payment handled successfully',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'paymentId' => $payment->getId(),
                ]
            );

        } catch (\Throwable $exception) {
            $this->logger->error(
                'Failed to handle completed 3DS payment',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'exception' => $exception->getMessage(),
                ]
            );
            throw $exception;
        }
    }


    protected function canSaveToDeviceVault(bool $registerCreditCards, ?CustomerEntity $customer): bool
    {
        return $registerCreditCards && $customer !== null && $customer->getGuest() === false;
    }

}
