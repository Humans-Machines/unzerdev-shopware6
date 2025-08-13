<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentHandler;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;
use UnzerPayment6\Components\ClientFactory\ClientFactoryInterface;
use UnzerPayment6\Components\ConfigReader\ConfigReaderInterface;
use UnzerPayment6\Components\CustomFieldsHelper\CustomFieldsHelperInterface;
use UnzerPayment6\Components\PaymentHandler\Exception\UnzerPaymentProcessException;
use UnzerPayment6\Components\ResourceHydrator\CustomerResourceHydrator\CustomerResourceHydratorInterface;
use UnzerPayment6\Components\ResourceHydrator\ResourceHydratorInterface;
use UnzerPayment6\Components\Struct\Configuration;
use UnzerPayment6\Components\Struct\KeyPairContext;
use UnzerPayment6\Components\TransactionStateHandler\TransactionStateHandlerInterface;
use UnzerSDK\Exceptions\UnzerApiException;
use UnzerSDK\Resources\AbstractUnzerResource;
use UnzerSDK\Resources\Basket;
use UnzerSDK\Resources\Customer;
use UnzerSDK\Resources\Metadata;
use UnzerSDK\Resources\Payment;
use UnzerSDK\Resources\PaymentTypes\BasePaymentType;
use UnzerSDK\Resources\Recurring;
use UnzerSDK\Unzer;
use Shopware\Core\PlatformRequest;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

abstract class AbstractUnzerPaymentHandler extends AbstractPaymentHandler
{
    /** @var BasePaymentType */
    protected $paymentType;

    /** @var null|Payment */
    protected $payment;

    /** @var Recurring */
    protected $recurring;

    /** @var Unzer */
    protected $unzerClient;

    /** @var Customer */
    protected $unzerCustomer;

    /** @var Basket */
    protected $unzerBasket;

    /** @var Metadata */
    protected $unzerMetadata;

    /** @var Configuration */
    protected $pluginConfig;

    /** @var Request|null */
    protected $currentRequest;

    public function __construct(
        protected readonly ResourceHydratorInterface         $basketHydrator,
        protected readonly CustomerResourceHydratorInterface $customerHydrator,
        protected readonly ResourceHydratorInterface         $metadataHydrator,
        protected readonly EntityRepository                  $transactionRepository,
        protected readonly ConfigReaderInterface             $configReader,
        protected readonly TransactionStateHandlerInterface  $transactionStateHandler,
        protected readonly ClientFactoryInterface            $clientFactory,
        protected readonly RequestStack                      $requestStack,
        protected readonly LoggerInterface                   $logger,
        protected readonly CustomFieldsHelperInterface       $customFieldsHelper,
        protected readonly AbstractSalesChannelContextFactory $salesChannelContextFactory
    )
    {

    }

    protected function getOrderTransactionById(string $transactionId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$transactionId]);
        return $this->transactionRepository->search($criteria, $context)->first();
    }

    protected function getOrderByTransactionId(string $transactionId, Context $context): OrderEntity
    {
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociations([
            'order',
            'order.salesChannel'
        ]);

        $orderTransaction = $this->transactionRepository->search($criteria, $context)->first();
        assert($orderTransaction instanceof OrderTransactionEntity);

        return $orderTransaction->getOrder();
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): RedirectResponse
    {

        $salesChannelContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        // If no sales channel context from request, create a redirect to error URL  
        if (!$salesChannelContext) {
            $this->logger->warning('No SalesChannelContext in request - cannot process payment', [
                'transactionId' => $transaction->getOrderTransactionId()
            ]);
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransactionId(),
                'Missing SalesChannelContext in payment request'
            );
        }

        $currentRequest = $this->getCurrentRequestFromStack($transaction->getOrderTransactionId());
        $this->currentRequest = $currentRequest; // Store for use in traits

        try {
            $salesChannelId = $salesChannelContext->getSalesChannelId();

            $this->pluginConfig = $this->configReader->read($salesChannelId);
            $this->unzerClient = $this->clientFactory->createClient(
                KeyPairContext::createFromSalesChannelContext($salesChannelContext),
                empty($currentRequest->getLocale()) ? $currentRequest->getDefaultLocale() : $currentRequest->getLocale()
            );

            $this->unzerBasket = $this->basketHydrator->hydrateObject($salesChannelContext, $transaction);
            $this->unzerMetadata = $this->metadataHydrator->hydrateObject($salesChannelContext, $transaction);

            $orderTransaction = $this->getOrderTransactionById($transaction->getOrderTransactionId(), $context);

            $paymentMethodId = $orderTransaction->getPaymentMethodId();
            $this->unzerCustomer = $this->getUnzerCustomer($currentRequest->get('unzerCustomerId', ''), $paymentMethodId, $salesChannelContext);

            $resourceId = $currentRequest->get('unzerResourceId', '');

            if (!empty($resourceId)) {
                $this->paymentType = $this->unzerClient->fetchPaymentType($resourceId);
            }

            $this->customFieldsHelper->setOrderTransactionUnzerFlag($orderTransaction, $salesChannelContext->getContext());

            // Transform return URL for reverse proxy setups
            $returnUrl = $this->transformReturnUrl($transaction->getReturnUrl(), $request);
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

            throw new UnzerPaymentProcessException($orderTransaction?->getOrder()?->getId() ?? "-", $transaction->getOrderTransactionId(), $apiException);
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

    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void
    {
        /** @var OrderTransactionEntity|null $orderTransaction */
        $order = $this->getOrderByTransactionId($transaction->getOrderTransactionId(), $context);
        
        if (!$order) {
            $this->logger->error(
                'Order not found for transaction - cannot finalize payment',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'paymentHandler' => static::class,
                ]
            );
            throw PaymentException::asyncFinalizeInterrupted($transaction->getOrderTransactionId(), 'Order not found');
        }
        
        $this->logger->info(
            'AbstractUnzerPaymentHandler::finalize() called',
            [
                'transactionId' => $transaction->getOrderTransactionId(),
                'orderId' => $order->getId(),
                'paymentHandler' => static::class,
            ]
        );

        // create sales channel context from order data
        $salesChannelContext = $this->salesChannelContextFactory->create(
            '', // token will be generated
            $order->getSalesChannelId(),
            [
                'currencyId' => $order->getCurrencyId(),
                'languageId' => $order->getLanguageId(),
                'customerId' => $order->getOrderCustomer()?->getCustomerId(),
            ]
        );

        $this->logger->info(
            'Sales channel context created for payment finalization',
            [
                'transactionId' => $transaction->getOrderTransactionId(),
                'salesChannelId' => $order->getSalesChannelId(),
                'currencyId' => $order->getCurrencyId(),
            ]
        );

        try {
            $this->pluginConfig = $this->configReader->read($salesChannelContext->getSalesChannelId());
            $this->unzerClient = $this->clientFactory->createClient(
                KeyPairContext::createFromSalesChannelContext($salesChannelContext)
            );
            $orderTransaction = $this->getOrderTransactionById($transaction->getOrderTransactionId(), $context);
            
            $this->logger->info(
                'Fetching payment by order ID',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'orderId' => $order->getId(),
                ]
            );
            
            $this->payment = $this->unzerClient->fetchPaymentByOrderId(
                $order->getId()
            );

            $this->logger->info(
                'Payment fetched, current state before transformation',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'paymentId' => $this->payment?->getId(),
                    'paymentState' => $this->payment?->getStateName(),
                    'paymentAmount' => $this->payment?->getAmount()?->getTotal(),
                ]
            );

            // Race conditions are now handled by PaymentProcessorDecorator

            $this->transactionStateHandler->transformTransactionState(
                $transaction->getOrderTransactionId(),
                $this->payment,
                $context
            );

            $this->logger->info(
                'Transaction state transformation completed',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'finalPaymentState' => $this->payment?->getStateName(),
                ]
            );

            $this->customFieldsHelper->setOrderTransactionCustomFields($orderTransaction, $context);
            
            $this->logger->info(
                'Payment finalization completed successfully',
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'paymentHandler' => static::class,
                ]
            );

        } catch (UnzerApiException $apiException) {
            $this->logger->error(
                sprintf('API exception during payment finalization in %s', static::class),
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'orderId' => $order->getId(),
                    'apiCode' => $apiException->getCode(),
                    'apiMessage' => $apiException->getMessage(),
                    'request' => $this->getLoggableRequest($request),
                    'exception' => $apiException,
                ]
            );

            throw PaymentException::asyncFinalizeInterrupted($transaction->getOrderTransactionId(), $apiException->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error(
                sprintf('Generic exception during payment finalization in %s', static::class),
                [
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'orderId' => $order->getId(),
                    'exceptionType' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'request' => $this->getLoggableRequest($request),
                    'exception' => $exception,
                ]
            );

            throw PaymentException::asyncFinalizeInterrupted($transaction->getOrderTransactionId(), $exception->getMessage());
        }
    }

    protected function persistPaymentInformation(array $information, string $transactionId, Context $context): void
    {
        $this->transactionRepository->update(
            [
                [
                    'id' => $transactionId,
                    'customFields' => $information,
                ],
            ],
            $context
        );
    }

    protected function getCurrentRequestFromStack(string $orderTransactionId): Request
    {
        $currentRequest = $this->requestStack->getCurrentRequest();

        if ($currentRequest === null) {
            throw PaymentException::asyncProcessInterrupted($orderTransactionId, 'No request found');
        }

        return $currentRequest;
    }

    protected function executeFailTransition(string $transactionId, Context $context): void
    {
        $this->transactionStateHandler->fail(
            $transactionId,
            $context
        );
    }

    protected function getUnzerCustomer(string $unzerCustomerId, string $paymentMethodId, SalesChannelContext $salesChannelContext): AbstractUnzerResource
    {
        $customer = $salesChannelContext->getCustomer();
        $fetchedCustomer = null;

        if (!empty($unzerCustomerId)) {
            try {
                $fetchedCustomer = $this->unzerClient->fetchCustomer($unzerCustomerId);
            } catch (Throwable $t) {
                // silentfail
            }
        }

        if ($customer && !$fetchedCustomer) {
            $customerNumber = $customer->getCustomerNumber();
            $billingAddress = $customer->getActiveBillingAddress();

            if ($billingAddress !== null && !empty($billingAddress->getCompany())) {
                $customerNumber .= '_b';
            }

            try {
                $fetchedCustomer = $this->unzerClient->fetchCustomerByExtCustomerId($customerNumber);
            } catch (Throwable $t) {
                // silentfail
            }
        }

        if ($fetchedCustomer) {
            /** @var Customer $updatedCustomer */
            $updatedCustomer = $this->customerHydrator->hydrateExistingCustomer($fetchedCustomer, $salesChannelContext);

            try {
                $updatedCustomer = $this->unzerClient->updateCustomer($updatedCustomer);
            } catch (Throwable $t) {
                // silentfail
            }

            return $updatedCustomer;
        }

        return $this->customerHydrator->hydrateObject($paymentMethodId, $salesChannelContext);
    }

    protected function getLoggableRequest(Request $request): array
    {
        $result = [
            'request-info' => sprintf('%s %s %s', $request->getMethod(), $request->getRequestUri(), $request->getScheme()) . "\r\n",
            'header' => $request->headers->all(),
            'content' => $request->getContent(false),
        ];
        $cookies = [];

        foreach ($request->cookies->all() as $cookieKey => $cookieValue) {
            if (is_array($cookieValue)) {
                $cookies[] = $cookieKey . '=' . json_encode($cookieValue);
            } elseif (is_scalar($cookieValue)) {
                $cookies[] = $cookieKey . '=' . $cookieValue;
            }
        }

        if (!empty($cookies)) {
            $result['cookie-header'] = 'Cookie: ' . implode('; ', $cookies) . "\r\n";
        }

        return $result;
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return true;
    }

    /**
     * Optional URL transformation for reverse proxy or custom routing setups.
     * Can be disabled by setting UNZER_DISABLE_URL_TRANSFORM=1 in environment.
     * 
     * Default behavior checks for X-Forwarded-Host header.
     * Can be customized by setting UNZER_EXTERNAL_HOST and UNZER_EXTERNAL_PROTO.
     */
    protected function transformReturnUrl(string $returnUrl, Request $request): string
    {
        // Check if URL transformation is disabled
        if (getenv('UNZER_DISABLE_URL_TRANSFORM') === '1' || 
            ($_ENV['UNZER_DISABLE_URL_TRANSFORM'] ?? false)) {
            return $returnUrl;
        }

        // Get external host from environment or headers
        $externalHost = $_ENV['UNZER_EXTERNAL_HOST'] ?? 
                       getenv('UNZER_EXTERNAL_HOST') ?: 
                       $request->headers->get('X-Forwarded-Host');
                       
        $externalProto = $_ENV['UNZER_EXTERNAL_PROTO'] ?? 
                        getenv('UNZER_EXTERNAL_PROTO') ?: 
                        $request->headers->get('X-Forwarded-Proto', 'https');
        
        if (!$externalHost) {
            // No external host configured, return original URL
            return $returnUrl;
        }

        // Parse the return URL
        $urlParts = parse_url($returnUrl);
        if (!$urlParts || !isset($urlParts['host'])) {
            // Invalid URL, return original
            return $returnUrl;
        }

        // Skip transformation if already using external host
        if ($urlParts['host'] === $externalHost) {
            return $returnUrl;
        }

        // Skip transformation for external payment service URLs (Unzer, PayPal, etc.)
        $externalServices = [
            'sbx-payment.heidelpay.com',
            'payment.heidelpay.com', 
            'api.heidelpay.com',
            'sbx-api.heidelpay.com',
            'api.unzer.com',
            'sbx-api.unzer.com',
            'paypal.com',
            'sandbox.paypal.com'
        ];
        
        if (in_array($urlParts['host'], $externalServices)) {
            $this->logger->info(
                'Skipping URL transformation for external payment service',
                [
                    'url' => $returnUrl,
                    'host' => $urlParts['host'],
                ]
            );
            return $returnUrl;
        }

        // Build the external URL
        $externalUrl = $externalProto . '://' . $externalHost;
        
        if (isset($urlParts['path'])) {
            $externalUrl .= $urlParts['path'];
        }
        
        if (isset($urlParts['query'])) {
            $externalUrl .= '?' . $urlParts['query'];
        }
        
        if (isset($urlParts['fragment'])) {
            $externalUrl .= '#' . $urlParts['fragment'];
        }

        $this->logger->info(
            'Transformed return URL (configurable)',
            [
                'originalUrl' => $returnUrl,
                'externalUrl' => $externalUrl,
                'externalHost' => $externalHost,
                'externalProto' => $externalProto,
                'method' => ($_ENV['UNZER_EXTERNAL_HOST'] ?? null) ? 'environment' : 'header',
            ]
        );

        return $externalUrl;
    }

}
