<?php

namespace SwedbankPay\Core\Traits;

use SwedbankPay\Core\Adapter\PaymentAdapterInterface;
use SwedbankPay\Core\Api\Response;
use SwedbankPay\Core\Api\FinancialTransaction;
use SwedbankPay\Core\Exception;
use SwedbankPay\Core\Log\LogLevel;
use SwedbankPay\Core\Order;
use SwedbankPay\Core\OrderItemInterface;
use SwedbankPay\Api\Client\Exception as ClientException;
use SwedbankPay\Api\Service\Paymentorder\Resource\Request\Paymentorder;
use SwedbankPay\Api\Service\Paymentorder\Resource\Collection\OrderItemsCollection;
use SwedbankPay\Api\Service\Paymentorder\Resource\Collection\Item\OrderItem;
use SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderUrl;
use SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayeeInfo;
use SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayer;
use SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderMetadata;
use SwedbankPay\Api\Service\Paymentorder\Request\Purchase;
use SwedbankPay\Api\Service\Paymentorder\Request\Verify;
use SwedbankPay\Api\Service\Paymentorder\Request\Recur;
use SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderObject;
use SwedbankPay\Api\Service\Data\ResponseInterface as ResponseServiceInterface;
use SwedbankPay\Api\Service\Paymentorder\Transaction\Resource\Request\Transaction as TransactionData;
use SwedbankPay\Api\Service\Paymentorder\Transaction\Resource\Request\TransactionObject;
use SwedbankPay\Api\Service\Paymentorder\Transaction\Request\TransactionCaptureV3;
use SwedbankPay\Api\Service\Paymentorder\Transaction\Request\TransactionCancelV3;
use SwedbankPay\Api\Service\Paymentorder\Transaction\Request\TransactionReversalV3;

trait Checkout
{
    /**
     * Initiate Payment Order Purchase.
     *
     * @param mixed $orderId
     * @param string|null $consumerProfileRef
     *
     * @return Response
     * @throws Exception
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.LongVariable)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function initiatePaymentOrderPurchase(
        $orderId,
        $consumerProfileRef = null
    ) {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        $urls = $this->getPlatformUrls($orderId);

        $urlData = new PaymentorderUrl();
        $urlData
            ->setHostUrls($urls->getHostUrls())
            ->setCompleteUrl($urls->getCompleteUrl())
            ->setCancelUrl($urls->getCancelUrl())
            ->setPaymentUrl($urls->getPaymentUrl())
            ->setCallbackUrl($urls->getCallbackUrl())
            ->setTermsOfService($urls->getTermsUrl())
            ->setLogoUrl($urls->getLogoUrl());

        $payeeInfo = new PaymentorderPayeeInfo($this->getPayeeInfo($orderId)->toArray());

        // Add metadata
        $metadata = new PaymentorderMetadata();
        $metadata->setData('order_id', $order->getOrderId());

        // Build items collection
        $items = $order->getItems();
        $orderItems = new OrderItemsCollection();
        foreach ($items as $item) {
            /** @var OrderItemInterface $item */

            $orderItem = new OrderItem();
            $orderItem
                ->setReference($item->getReference())
                ->setName($item->getName())
                ->setType($item->getType())
                ->setItemClass($item->getClass())
                ->setItemUrl($item->getItemUrl())
                ->setImageUrl($item->getImageUrl())
                ->setDescription($item->getDescription())
                //->setDiscountDescription($item->getDiscountDescription())
                ->setQuantity($item->getQty())
                ->setUnitPrice($item->getUnitPrice())
                ->setQuantityUnit($item->getQtyUnit())
                ->setVatPercent($item->getVatPercent())
                ->setAmount($item->getAmount())
                ->setVatAmount($item->getVatAmount())
                ->setRestrictedToInstruments($item->getRestrictedToInstruments());

            $orderItems->addItem($orderItem);
        }

        $paymentOrder = new Paymentorder();
        $paymentOrder
            ->setOperation(self::OPERATION_PURCHASE)
            ->setCurrency($order->getCurrency())
            ->setAmount($order->getAmountInCents())
            ->setVatAmount($order->getVatAmountInCents())
            ->setDescription($order->getDescription())
            ->setUserAgent($order->getHttpUserAgent())
            ->setLanguage($order->getLanguage())
            ->setProductName('Checkout3')
            ->setImplementation($this->adapter->getImplementation())
            ->setDisablePaymentMenu(false)
            ->setUrls($urlData)
            ->setPayeeInfo($payeeInfo)
            ->setMetadata($metadata)
            ->setOrderItems($orderItems);

        $payer = $this->getPayer($order);
        $payer->setPayerReference($order->getPayerReference());

        // Add consumerProfileRef if exists
        if (!empty($consumerProfileRef)) {
            $payer = $this->getPayer($order);
            $payer->setConsumerProfileRef($consumerProfileRef);
        }

        // Add payer info
        if ($this->configuration->getUsePayerInfo()) {
            $payer->setFirstName($order->getBillingFirstName())
                ->setLastName($order->getBillingLastName())
                ->setEmail($order->getBillingEmail())
                ->setMsisdn($order->getBillingPhone());
        }

        $paymentOrder->setPayer($payer);

        $paymentOrderObject = new PaymentorderObject();
        $paymentOrderObject->setPaymentorder($paymentOrder);

        // Process payment object
        $paymentOrderObject = $this->adapter->processPaymentObject($paymentOrderObject, $orderId);

        $purchaseRequest = new Purchase($paymentOrderObject);
        $purchaseRequest->setClient($this->client);

        try {
            /** @var ResponseServiceInterface $responseService */
            $responseService = $purchaseRequest->send();

            $this->log(
                LogLevel::DEBUG,
                $purchaseRequest->getClient()->getDebugInfo()
            );

            return new Response($responseService->getResponseData());
        } catch (ClientException $e) {
            $this->log(
                LogLevel::DEBUG,
                $purchaseRequest->getClient()->getDebugInfo()
            );

            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($this->formatErrorMessage($purchaseRequest->getClient()->getResponseBody()));
        }
    }

    /**
     * Initiate Payment Order Verify
     *
     * @param mixed $orderId
     *
     * @return Response
     * @throws Exception
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function initiatePaymentOrderVerify(
        $orderId
    ) {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        $urls = $this->getPlatformUrls($orderId);

        // PaymentUrl should be disabled here. Because `redirect-paymentorder` will be empty in Payment Only mode.
        $urlData = new PaymentorderUrl();
        $urlData->setHostUrls($urls->getHostUrls())
                ->setCompleteUrl($urls->getCompleteUrl())
                ->setCancelUrl($urls->getCancelUrl())
                ->setCallbackUrl($urls->getCallbackUrl())
                ->setTermsOfService($urls->getTermsUrl())
                ->setLogoUrl($urls->getLogoUrl());

        $payeeInfo = new PaymentorderPayeeInfo($this->getPayeeInfo($orderId)->toArray());

        // Add metadata
        $metadata = new PaymentorderMetadata();
        $metadata->setData('order_id', $order->getOrderId());

        $payer = $this->getPayer($order);
        $payer->setPayerReference($order->getPayerReference());

        // Add payer info
        if ($this->configuration->getUsePayerInfo()) {
            $payer->setFirstName($order->getBillingFirstName())
                  ->setLastName($order->getBillingLastName())
                  ->setEmail($order->getBillingEmail())
                  ->setMsisdn($order->getBillingPhone());
        }

        $payer->setPayerReference($order->getPayerReference());

        $paymentOrder = new Paymentorder();
        $paymentOrder
            ->setPayer($payer)
            ->setOperation(self::OPERATION_VERIFY)
            ->setCurrency($order->getCurrency())
            ->setDescription('Verification of Credit Card')
            ->setUserAgent($order->getHttpUserAgent())
            ->setLanguage($order->getLanguage())
            ->setProductName('Checkout3')
            ->setImplementation($this->adapter->getImplementation())
            ->setUrls($urlData)
            ->setPayeeInfo($payeeInfo)
            ->setMetadata($metadata);

        $paymentOrderObject = new PaymentorderObject();
        $paymentOrderObject->setPaymentorder($paymentOrder);

        // Process payment object
        $paymentOrderObject = $this->adapter->processPaymentObject($paymentOrderObject, $orderId);

        $purchaseRequest = new Verify($paymentOrderObject);
        $purchaseRequest->setClient($this->client);

        try {
            /** @var ResponseServiceInterface $responseService */
            $responseService = $purchaseRequest->send();

            $this->log(
                LogLevel::DEBUG,
                $purchaseRequest->getClient()->getDebugInfo()
            );

            return new Response($responseService->getResponseData());
        } catch (ClientException $e) {
            $this->log(
                LogLevel::DEBUG,
                $purchaseRequest->getClient()->getDebugInfo()
            );

            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($this->formatErrorMessage($purchaseRequest->getClient()->getResponseBody()));
        }
    }

    /**
     * Initiate Payment Order Recurrent Payment
     *
     * @param mixed $orderId
     * @param string $recurrenceToken
     *
     * @return Response
     * @throws \Exception
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function initiatePaymentOrderRecur($orderId, $recurrenceToken)
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        $urls = $this->getPlatformUrls($orderId);

        $urlData = new PaymentorderUrl();
        $urlData
            ->setHostUrls($urls->getHostUrls())
            ->setCompleteUrl($urls->getCompleteUrl())
            ->setCancelUrl($urls->getCancelUrl())
            ->setPaymentUrl($urls->getPaymentUrl())
            ->setCallbackUrl($urls->getCallbackUrl())
            ->setTermsOfService($urls->getTermsUrl())
            ->setLogoUrl($urls->getLogoUrl());

        $payeeInfo = new PaymentorderPayeeInfo($this->getPayeeInfo($orderId)->toArray());

        // Add metadata
        $metadata = new PaymentorderMetadata();
        $metadata->setData('order_id', $order->getOrderId());

        // Build items collection
        $items = $order->getItems();
        $orderItems = new OrderItemsCollection();
        foreach ($items as $item) {
            /** @var OrderItemInterface $item */

            $orderItem = new OrderItem();
            $orderItem
                ->setReference($item->getReference())
                ->setName($item->getName())
                ->setType($item->getType())
                ->setItemClass($item->getClass())
                ->setItemUrl($item->getItemUrl())
                ->setImageUrl($item->getImageUrl())
                ->setDescription($item->getDescription())
                //->setDiscountDescription($item->getDiscountDescription())
                ->setQuantity($item->getQty())
                ->setUnitPrice($item->getUnitPrice())
                ->setQuantityUnit($item->getQtyUnit())
                ->setVatPercent($item->getVatPercent())
                ->setAmount($item->getAmount())
                ->setVatAmount($item->getVatAmount())
                ->setRestrictedToInstruments($item->getRestrictedToInstruments());

            $orderItems->addItem($orderItem);
        }

        $paymentOrder = new Paymentorder();
        $paymentOrder
            ->setOperation(self::OPERATION_RECUR)
            ->setRecurrenceToken($recurrenceToken)
            ->setIntent(self::INTENT_AUTHORIZATION)
            ->setCurrency($order->getCurrency())
            ->setAmount($order->getAmountInCents())
            ->setVatAmount($order->getVatAmountInCents())
            ->setDescription($order->getDescription())
            ->setUserAgent($order->getHttpUserAgent())
            ->setLanguage($order->getLanguage())
            ->setProductName('Checkout3')
            ->setImplementation($this->adapter->getImplementation())
            ->setUrls($urlData)
            ->setPayeeInfo($payeeInfo)
            ->setMetadata($metadata)
            ->setOrderItems($orderItems);

        $paymentOrderObject = new PaymentorderObject();
        $paymentOrderObject->setPaymentorder($paymentOrder);

        // Process payment object
        $paymentOrderObject = $this->adapter->processPaymentObject($paymentOrderObject, $orderId);

        $purchaseRequest = new Recur($paymentOrderObject);
        $purchaseRequest->setClient($this->client);

        try {
            /** @var ResponseServiceInterface $responseService */
            $responseService = $purchaseRequest->send();

            $this->log(
                LogLevel::DEBUG,
                $purchaseRequest->getClient()->getDebugInfo()
            );

            return new Response($responseService->getResponseData());
        } catch (ClientException $e) {
            $this->log(
                LogLevel::DEBUG,
                $purchaseRequest->getClient()->getDebugInfo()
            );

            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($this->formatErrorMessage($purchaseRequest->getClient()->getResponseBody()));
        }
    }

    /**
     * Initiate Payment Order Unscheduled Payment.
     *
     * @param mixed $orderId
     * @param string $unscheduledToken
     *
     * @return Response
     * @throws \Exception
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function initiatePaymentOrderUnscheduledPurchase($orderId, $unscheduledToken)
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        $urls = $this->getPlatformUrls($orderId);

        $urlData = new PaymentorderUrl();
        $urlData
            ->setHostUrls($urls->getHostUrls())
            ->setCompleteUrl($urls->getCompleteUrl())
            ->setCancelUrl($urls->getCompleteUrl())
            ->setPaymentUrl($urls->getPaymentUrl())
            ->setCallbackUrl($urls->getCallbackUrl())
            ->setTermsOfService($urls->getTermsUrl())
            ->setLogoUrl($urls->getLogoUrl());

        $payeeInfo = new PaymentorderPayeeInfo($this->getPayeeInfo($orderId)->toArray());

        // Add metadata
        $metadata = new PaymentorderMetadata();
        $metadata->setData('order_id', $order->getOrderId());

        // Build items collection
        $items = $order->getItems();
        $orderItems = new OrderItemsCollection();
        foreach ($items as $item) {
            /** @var OrderItemInterface $item */

            $orderItem = new OrderItem();
            $orderItem
                ->setReference($item->getReference())
                ->setName($item->getName())
                ->setType($item->getType())
                ->setItemClass($item->getClass())
                ->setItemUrl($item->getItemUrl())
                ->setImageUrl($item->getImageUrl())
                ->setDescription($item->getDescription())
                //->setDiscountDescription($item->getDiscountDescription())
                ->setQuantity($item->getQty())
                ->setUnitPrice($item->getUnitPrice())
                ->setQuantityUnit($item->getQtyUnit())
                ->setVatPercent($item->getVatPercent())
                ->setAmount($item->getAmount())
                ->setVatAmount($item->getVatAmount())
                ->setRestrictedToInstruments($item->getRestrictedToInstruments());

            $orderItems->addItem($orderItem);
        }

        $paymentOrder = new Paymentorder();
        $paymentOrder
            ->setOperation(self::OPERATION_UNSCHEDULED_PURCHASE)
            ->setUnscheduledToken($unscheduledToken)
            ->setIntent(self::INTENT_AUTHORIZATION)
            ->setCurrency($order->getCurrency())
            ->setAmount($order->getAmountInCents())
            ->setVatAmount($order->getVatAmountInCents())
            ->setDescription($order->getDescription())
            ->setUserAgent($order->getHttpUserAgent())
            ->setLanguage($order->getLanguage())
            ->setUrls($urlData)
            ->setPayeeInfo($payeeInfo)
            ->setMetadata($metadata)
            ->setOrderItems($orderItems);

        $payer = $this->getPayer($order);
        $payer->setPayerReference($order->getPayerReference());

        // Add payer info
        if ($this->configuration->getUsePayerInfo()) {
            $payer->setFirstName($order->getBillingFirstName())
                  ->setLastName($order->getBillingLastName())
                  ->setEmail($order->getBillingEmail())
                  ->setMsisdn($order->getBillingPhone());
        }

        $payer->setPayerReference($order->getPayerReference());

        $paymentOrderObject = new PaymentorderObject();
        $paymentOrderObject->setPaymentorder($paymentOrder);

        // Process payment object
        $paymentOrderObject = $this->adapter->processPaymentObject($paymentOrderObject, $orderId);

        $purchaseRequest = new Purchase($paymentOrderObject);
        $purchaseRequest->setClient($this->client);

        try {
            /** @var ResponseServiceInterface $responseService */
            $responseService = $purchaseRequest->send();

            $this->log(
                LogLevel::DEBUG,
                $purchaseRequest->getClient()->getDebugInfo()
            );

            return new Response($responseService->getResponseData());
        } catch (ClientException $e) {
            $this->log(
                LogLevel::DEBUG,
                $purchaseRequest->getClient()->getDebugInfo()
            );

            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($this->formatErrorMessage($purchaseRequest->getClient()->getResponseBody()));
        }
    }

    /**
     * @param string $updateUrl
     * @param mixed $orderId
     *
     * @return Response
     * @throws Exception
     */
    public function updatePaymentOrder($updateUrl, $orderId)
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        // Update Order
        $params = [
            'paymentorder' => [
                'operation' => 'UpdateOrder',
                'amount' => $order->getAmountInCents(),
                'vatAmount' => $order->getVatAmountInCents(),
                'orderItems' => $order->getItems()
            ]
        ];

        try {
            $result = $this->request('PATCH', $updateUrl, $params);
        } catch (\SwedbankPay\Core\Exception $e) {
            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($e->getMessage(), $e->getCode(), null, $e->getProblems());
        } catch (\Exception $e) {
            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($e->getMessage());
        }

        return $result;
    }

    /**
     * Capture Checkout.
     *
     * @param mixed $orderId
     * @param \SwedbankPay\Core\OrderItem[] $items
     *
     * @return Response
     * @throws Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.MissingImport)
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function captureCheckout($orderId, array $items = [])
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        $paymentOrderId = $order->getPaymentOrderId();
        if (empty($paymentOrderId)) {
            throw new Exception('Unable to get the payment order ID');
        }

        if (count($items) === 0) {
            $items = $order->getItems();
        }

        // Build items collection
        $orderItems = new OrderItemsCollection();

        // Recalculate amount and VAT amount
        $amount = 0;
        $vatAmount = 0;
        foreach ($items as $item) {
            if (is_array($item)) {
                $item = new \SwedbankPay\Core\OrderItem($item);
            }

            $amount += $item->getAmount();
            $vatAmount += $item->getVatAmount();

            $orderItem = new OrderItem();
            $orderItem
                ->setReference($item->getReference())
                ->setName($item->getName())
                ->setType($item->getType())
                ->setItemClass($item->getClass())
                ->setItemUrl($item->getItemUrl())
                ->setImageUrl($item->getImageUrl())
                ->setDescription($item->getDescription())
                //->setDiscountDescription($item->getDiscountDescription())
                ->setQuantity($item->getQty())
                ->setUnitPrice($item->getUnitPrice())
                ->setQuantityUnit($item->getQtyUnit())
                ->setVatPercent($item->getVatPercent())
                ->setAmount($item->getAmount())
                ->setVatAmount($item->getVatAmount())
                ->setRestrictedToInstruments($item->getRestrictedToInstruments());

            $orderItems->addItem($orderItem);
        }

        $transactionData = new TransactionData();
        $transactionData
            ->setAmount($amount)
            ->setVatAmount($vatAmount)
            ->setDescription(sprintf('Capture for Order #%s', $order->getOrderId()))
            ->setPayeeReference($this->generatePayeeReference($orderId))
            ->setOrderItems($orderItems);

        $transaction = new TransactionObject();
        $transaction->setTransaction($transactionData);

        // Process transaction object
        $transaction = $this->adapter->processTransactionObject($transaction, $orderId);

        $requestService = new TransactionCaptureV3($transaction);
        $requestService->setClient($this->client)
                       ->setPaymentOrderId($paymentOrderId);

        try {
            /** @var ResponseServiceInterface $responseService */
            $responseService = $requestService->send();

            $this->log(
                LogLevel::DEBUG,
                $requestService->getClient()->getDebugInfo()
            );

            $result = $responseService->getResponseData();

            // Save transaction
            /** @var FinancialTransaction $transaction */
            $transaction = $result['capture']['transaction'];
            if (is_array($transaction)) {
                $transaction = new FinancialTransaction($transaction);
            }

            $this->saveFinancialTransaction($orderId, $transaction);
            $this->processFinancialTransaction($orderId, $transaction);

            return new Response($result);
        } catch (ClientException $e) {
            $this->log(
                LogLevel::DEBUG,
                $requestService->getClient()->getDebugInfo()
            );

            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($this->formatErrorMessage($requestService->getClient()->getResponseBody()));
        }
    }

    /**
     * Cancel Checkout.
     *
     * @param mixed $orderId
     * @param int|float|null $amount
     * @param int|float $vatAmount
     *
     * @return Response
     * @throws Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function cancelCheckout($orderId, $amount = null, $vatAmount = 0)
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        if ($amount > 0 && $amount !== $order->getAmount()) {
            throw new Exception('Partial cancellation isn\'t available.');
        }

        if ($vatAmount > 0 && $vatAmount !== $order->getVatAmount()) {
            throw new Exception('Partial cancellation isn\'t available.');
        }

        $paymentOrderId = $order->getPaymentOrderId();
        if (empty($paymentOrderId)) {
            throw new Exception('Unable to get the payment order ID');
        }

        $transactionData = new TransactionData();
        $transactionData
            ->setDescription(sprintf('Cancellation for Order #%s', $order->getOrderId()))
            ->setPayeeReference($this->generatePayeeReference($orderId));

        $transaction = new TransactionObject();
        $transaction->setTransaction($transactionData);

        // Process transaction object
        $transaction = $this->adapter->processTransactionObject($transaction, $orderId);

        $requestService = new TransactionCancelV3($transaction);
        $requestService->setClient($this->client)
                       ->setPaymentOrderId($paymentOrderId);

        try {
            /** @var ResponseServiceInterface $responseService */
            $responseService = $requestService->send();

            $this->log(
                LogLevel::DEBUG,
                $requestService->getClient()->getDebugInfo()
            );

            $result = $responseService->getResponseData();

            // Save transaction
            /** @var FinancialTransaction $transaction */
            $transaction = $result['cancellation']['transaction'];
            if (is_array($transaction)) {
                $transaction = new FinancialTransaction($transaction);
            }

            $this->saveFinancialTransaction($orderId, $transaction);
            $this->processFinancialTransaction($orderId, $transaction);

            return new Response($result);
        } catch (ClientException $e) {
            $this->log(
                LogLevel::DEBUG,
                $requestService->getClient()->getDebugInfo()
            );

            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($this->formatErrorMessage($requestService->getClient()->getResponseBody()));
        }
    }

    /**
     * Refund Checkout.
     *
     * @param mixed $orderId
     * @param \SwedbankPay\Core\OrderItem[] $items
     *
     * @return Response
     * @throws Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ElseExpression)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.MissingImport)
     */
    public function refundCheckout($orderId, array $items = [])
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        $paymentOrderId = $order->getPaymentOrderId();
        if (empty($paymentOrderId)) {
            throw new Exception('Unable to get the payment order ID');
        }

        if (count($items) === 0) {
            $items = $order->getItems();
        }

        // Build items collection
        $orderItems = new OrderItemsCollection();

        // Recalculate amount and VAT amount
        $amount = 0;
        $vatAmount = 0;
        foreach ($items as $item) {
            if (is_array($item)) {
                $item = new \SwedbankPay\Core\OrderItem($item);
            }

            $amount += $item->getAmount();
            $vatAmount += $item->getVatAmount();

            $orderItem = new OrderItem();
            $orderItem
                ->setReference($item->getReference())
                ->setName($item->getName())
                ->setType($item->getType())
                ->setItemClass($item->getClass())
                ->setItemUrl($item->getItemUrl())
                ->setImageUrl($item->getImageUrl())
                ->setDescription($item->getDescription())
                //->setDiscountDescription($item->getDiscountDescription())
                ->setQuantity($item->getQty())
                ->setUnitPrice($item->getUnitPrice())
                ->setQuantityUnit($item->getQtyUnit())
                ->setVatPercent($item->getVatPercent())
                ->setAmount($item->getAmount())
                ->setVatAmount($item->getVatAmount())
                ->setRestrictedToInstruments($item->getRestrictedToInstruments());

            $orderItems->addItem($orderItem);
        }

        $transactionData = new TransactionData();
        $transactionData
            ->setAmount($amount)
            ->setVatAmount($vatAmount)
            ->setDescription(sprintf('Refund for Order #%s. Amount: %s', $order->getOrderId(), ($amount / 100)))
            ->setPayeeReference($this->generatePayeeReference($orderId))
            ->setReceiptReference($this->generatePayeeReference($orderId))
            ->setOrderItems($orderItems);

        $transaction = new TransactionObject();
        $transaction->setTransaction($transactionData);

        // Process transaction object
        $transaction = $this->adapter->processTransactionObject($transaction, $orderId);

        $requestService = new TransactionReversalV3($transaction);
        $requestService->setClient($this->client)
                       ->setPaymentOrderId($paymentOrderId);

        try {
            /** @var ResponseServiceInterface $responseService */
            $responseService = $requestService->send();

            $this->log(
                LogLevel::DEBUG,
                $requestService->getClient()->getDebugInfo()
            );

            $result = $responseService->getResponseData();

            // Save transaction
            /** @var FinancialTransaction $transaction */
            $transaction = $result['reversal']['transaction'];
            if (is_array($transaction)) {
                $transaction = new FinancialTransaction($transaction);
            }

            $this->saveFinancialTransaction($orderId, $transaction);
            $this->processFinancialTransaction($orderId, $transaction);

            return new Response($result);
        } catch (ClientException $e) {
            $this->log(
                LogLevel::DEBUG,
                $requestService->getClient()->getDebugInfo()
            );

            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($this->formatErrorMessage($requestService->getClient()->getResponseBody()));
        }
    }

    /**
     * Get `PaymentorderPayer`.
     *
     * @param Order $order
     * @return PaymentorderPayer
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    private function getPayer(Order $order)
    {
        $payer = new PaymentorderPayer();

        if ($order->needsShipping()) {
            if ($this->adapter->getImplementation() !== PaymentAdapterInterface::IMPLEMENTATION_PAYMENTS_ONLY) {
                $payer->setShippingAddressRestrictedToCountryCodes(['XX']);
            }
        } else {
            $payer->setDigitalProducts(true);
        }

        return $payer;
    }
}
