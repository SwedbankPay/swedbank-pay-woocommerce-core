<?php

namespace SwedbankPay\Core\Library\Methods;

use SwedbankPay\Core\Api\Response;
use SwedbankPay\Core\ConfigurationInterface;
use SwedbankPay\Core\Exception;
use SwedbankPay\Core\Log\LogLevel;
use SwedbankPay\Core\Order;
use SwedbankPay\Core\OrderInterface;
use SwedbankPay\Core\OrderItemInterface;
use SwedbankPay\Core\Api\TransactionInterface;

use SwedbankPay\Api\Client\Exception as ClientException;
use SwedbankPay\Api\Service\Payment\Resource\Collection\PricesCollection;
use SwedbankPay\Api\Service\Payment\Resource\Collection\Item\PriceItem;
use SwedbankPay\Api\Service\Payment\Resource\Request\Metadata;
use SwedbankPay\Api\Service\Invoice\Request\CreateInvoice;
use SwedbankPay\Api\Service\Invoice\Resource\Request\PaymentPayeeInfo;
use SwedbankPay\Api\Service\Invoice\Resource\Request\PaymentUrl;
use SwedbankPay\Api\Service\Invoice\Resource\Request\Payment;
use SwedbankPay\Api\Service\Invoice\Resource\Request\Invoice as InvoiceRequest;
use SwedbankPay\Api\Service\Invoice\Resource\Request\InvoicePaymentObject as PaymentObject;
use SwedbankPay\Api\Service\Data\ResponseInterface as ResponseServiceInterface;
use SwedbankPay\Api\Service\Payment\Transaction\Resource\Request\TransactionObject;
use SwedbankPay\Api\Service\Invoice\Transaction\Request\CreateCapture;
use SwedbankPay\Api\Service\Invoice\Transaction\Request\CreateReversal;
use SwedbankPay\Api\Service\Invoice\Transaction\Request\CreateCancellation;
use SwedbankPay\Api\Service\Invoice\Transaction\Resource\Request\Capture as TransactionCapture;
use SwedbankPay\Api\Service\Invoice\Transaction\Resource\Request\Reversal as TransactionReversal;
use SwedbankPay\Api\Service\Invoice\Transaction\Resource\Request\Cancellation as TransactionCancellation;

trait Invoice
{
    /**
     * @param mixed $orderId
     *
     * @return Response
     * @throws Exception
     */
    public function initiateInvoicePayment($orderId)
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        $urls = $this->getPlatformUrls($orderId);

        $url = new PaymentUrl();
        $url->setCompleteUrl($urls->getCompleteUrl())
            ->setCancelUrl($urls->getCancelUrl())
            ->setCallbackUrl($urls->getCallbackUrl())
            ->setLogoUrl($urls->getLogoUrl())
            ->setTermsOfService($urls->getTermsUrl())
            ->setHostUrls($urls->getHostUrls());

        if ($this->configuration->getData(ConfigurationInterface::CHECKOUT_METHOD) ===
            ConfigurationInterface::METHOD_SEAMLESS
        ) {
            $url->setPaymentUrl($urls->getPaymentUrl());
        }

        $payeeInfo = new PaymentPayeeInfo($this->getPayeeInfo($orderId)->toArray());

        $price = new PriceItem();
        $price->setType(InvoiceInterface::PRICE_TYPE_INVOICE)
            ->setAmount($order->getAmountInCents())
            ->setVatAmount($order->getVatAmountInCents());

        $prices = new PricesCollection();
        $prices->addItem($price);

        $metadata = new Metadata();
        $metadata->setData('order_id', $order->getOrderId());

        $payment = new Payment();
        $payment->setInitiatingSystemUserAgent($this->adapter->getInitiatingSystemUserAgent())
            ->setOperation(self::OPERATION_FINANCING_CONSUMER)
            ->setIntent(self::INTENT_AUTHORIZATION)
            ->setCurrency($order->getCurrency())
            ->setDescription($order->getDescription())
            ->setUserAgent($order->getHttpUserAgent())
            ->setLanguage($order->getLanguage())
            ->setUrls($url)
            ->setPayeeInfo($payeeInfo)
            ->setPrices($prices)
            ->setMetadata($metadata);

        $invoice = new InvoiceRequest();
        $invoice->setInvoiceType('PayExFinancing' . ucfirst(strtolower($order->getBillingCountryCode())));

        $paymentObject = new PaymentObject();
        $paymentObject->setPayment($payment)
            ->setInvoice($invoice);

        $purchaseRequest = new CreateInvoice($paymentObject);
        $purchaseRequest->setClient($this->client);

        try {
            /** @var ResponseServiceInterface $responseService */
            $responseService = $purchaseRequest->send();

            $this->log(
                LogLevel::DEBUG,
                $purchaseRequest->getClient()->getDebugInfo()
            );

            return new Response($responseService->getResponseData());
        } catch (\Exception $e) {
            $this->log(
                LogLevel::DEBUG,
                $purchaseRequest->getClient()->getDebugInfo()
            );

            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($e->getMessage());
        }
    }

    /**
     * Get Approved Legal Address.
     *
     * @param string $legalAddressHref
     * @param string $socialSecurityNumber
     * @param string $postCode
     *
     * @return Response
     * @throws Exception
     */
    public function getApprovedLegalAddress($legalAddressHref, $socialSecurityNumber, $postCode)
    {
        $params = [
            'addressee' => [
                'socialSecurityNumber' => $socialSecurityNumber,
                'zipCode' => str_replace(' ', '', $postCode)
            ]
        ];

        try {
            $result = $this->request('POST', $legalAddressHref, $params);
        } catch (\Exception $e) {
            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($e->getMessage());
        }

        // @todo Implement LegalAddress class

        return $result;
    }

    public function createApprovedLegalAddress($orderId, $socialSecurityNumber, $postCode)
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        $paymentId = $order->getPaymentId();
        if (empty($paymentId)) {
            throw new Exception('Unable to get payment ID');
        }

        $href = $this->fetchPaymentInfo($paymentId)->getOperationByRel('create-approved-legal-address');
        if (empty($href)) {
            throw new Exception('"Create approved legal address" is unavailable');
        }

        $params = [
            'addressee' => [
                'socialSecurityNumber' => $socialSecurityNumber,
                'zipCode' => str_replace(' ', '', $postCode)
            ]
        ];

        try {
            $result = $this->request('POST', $href, $params);
        } catch (\Exception $e) {
            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($e->getMessage());
        }

        // @todo Implement LegalAddress class

        return $result;
    }

    /**
     * Initiate a Financing Consumer Transaction
     *
     * @param string $authorizeHref
     * @param string $orderId
     * @param string $ssn
     * @param string $addressee
     * @param string $coAddress
     * @param string $streetAddress
     * @param string $zipCode
     * @param string $city
     * @param string $countryCode
     *
     * @return Response
     * @throws Exception
     */
    public function transactionFinancingConsumer(
        $authorizeHref,
        $orderId,
        $ssn,
        $addressee,
        $coAddress,
        $streetAddress,
        $zipCode,
        $city,
        $countryCode
    ) {
        /** @var Order $order */
        $order = $this->getOrder($orderId);


        $params = [
            'transaction' => [
                'activity' => 'FinancingConsumer'
            ],
            'consumer' => [
                'socialSecurityNumber' => $ssn,
                'customerNumber' => $order->getCustomerId(),
                'email' => $order->getBillingEmail(),
                'msisdn' => $order->getBillingPhone(),
                'ip' => $order->getCustomerIp()
            ],
            'legalAddress' => [
                'addressee' => $addressee,
                'coAddress' => $coAddress,
                'streetAddress' => $streetAddress,
                'zipCode' => $zipCode,
                'city' => $city,
                'countryCode' => $countryCode
            ]
        ];

        try {
            $result = $this->request('POST', $authorizeHref, $params);
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
     * Capture Invoice.
     *
     * @param mixed $orderId
     * @param int|float $amount
     * @param int|float $vatAmount
     * @param array $items
     *
     * @return Response
     * @throws Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function captureInvoice($orderId, $amount = null, $vatAmount = 0, array $items = [])
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        if (!$amount) {
            $amount = $order->getAmount();
            $vatAmount = $order->getVatAmount();
        }

        if (!$this->canCapture($orderId, $amount)) {
            throw new Exception('Capturing is not available.');
        }

        $paymentId = $order->getPaymentId();
        if (empty($paymentId)) {
            throw new Exception('Unable to get payment ID');
        }

        // Covert order lines
        $itemDescriptions = [];
        $vatSummary = [];
        foreach ($items as $item) {
            $itemDescriptions[] = [
                'amount' => $item[OrderItemInterface::FIELD_AMOUNT],
                'description' => $item[OrderItemInterface::FIELD_NAME]
            ];

            $vatSummary[] = [
                'amount' => $item[OrderItemInterface::FIELD_AMOUNT],
                'vatPercent' => $item[OrderItemInterface::FIELD_VAT_PERCENT],
                'vatAmount' => $item[OrderItemInterface::FIELD_VAT_AMOUNT]
            ];
        }

        $transactionData = new TransactionCapture();
        $transactionData
            ->setActivity('FinancingConsumer')
            ->setAmount((int)bcmul(100, $amount))
            ->setVatAmount((int)bcmul(100, $vatAmount))
            ->setDescription(sprintf('Capture for Order #%s', $order->getOrderId()))
            ->setPayeeReference($this->generatePayeeReference($orderId))
            ->setReceiptReference($this->generatePayeeReference($orderId))
            ->setItemDescriptions($itemDescriptions)
            ->setVatSummary($vatSummary);

        $transaction = new TransactionObject();
        $transaction->setTransaction($transactionData);

        $requestService = new CreateCapture($transaction);
        $requestService->setClient($this->client);
        $requestService->setPaymentId($paymentId);

        try {
            /** @var ResponseServiceInterface $responseService */
            $responseService = $requestService->send();
            $this->log(
                LogLevel::DEBUG,
                $requestService->getClient()->getDebugInfo()
            );

            $result = $responseService->getResponseData();

            // Save transaction
            $transaction = $result['capture']['transaction'];
            $this->saveTransaction($orderId, $transaction);

            switch ($transaction['state']) {
                case TransactionInterface::STATE_COMPLETED:
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_CAPTURED,
                        sprintf('Payment has been captured. Amount: %s', $amount),
                        $transaction['number']
                    );
                    break;
                case TransactionInterface::STATE_INITIALIZED:
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_AUTHORIZED,
                        sprintf('Transaction capture status: %s. Amount: %s', $transaction['state'], $amount)
                    );
                    break;
                case TransactionInterface::STATE_FAILED:
                    $message = $transaction['failedReason'] ?? 'Capture is failed.';
                    throw new Exception($message);
                default:
                    throw new Exception('Capture is failed.');
            }

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

            throw new Exception($e->getMessage());
        }
    }

    /**
     * Cancel Invoice.
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
     */
    public function cancelInvoice($orderId, $amount = null, $vatAmount = 0)
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        if (!$amount) {
            $amount = $order->getAmount();
            $vatAmount = $order->getVatAmount();
        }

        if (!$this->canCancel($orderId, $amount)) {
            throw new Exception('Cancellation is not available.');
        }

        $paymentId = $order->getPaymentId();
        if (empty($paymentId)) {
            throw new Exception('Unable to get payment ID');
        }

        $transactionData = new TransactionCancellation();
        $transactionData
            ->setActivity('FinancingConsumer')
            ->setDescription(sprintf('Refund for Order #%s.', $order->getOrderId()))
            ->setPayeeReference($this->generatePayeeReference($orderId));

        $transaction = new TransactionObject();
        $transaction->setTransaction($transactionData);

        $requestService = new CreateCancellation($transaction);
        $requestService
            ->setClient($this->client)
            ->setPaymentId($paymentId);

        try {
            /** @var ResponseServiceInterface $responseService */
            $responseService = $requestService->send();
            $this->log(
                LogLevel::DEBUG,
                $requestService->getClient()->getDebugInfo()
            );

            $result = $responseService->getResponseData();

            // Save transaction
            $transaction = $result['cancellation']['transaction'];
            $this->saveTransaction($orderId, $transaction);

            switch ($transaction['state']) {
                case TransactionInterface::STATE_COMPLETED:
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_CANCELLED,
                        'Transaction is cancelled.',
                        $transaction['number']
                    );
                    break;
                case TransactionInterface::STATE_INITIALIZED:
                case TransactionInterface::STATE_AWAITING_ACTIVITY:
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_CANCELLED,
                        sprintf('Transaction cancellation status: %s.', $transaction['state'])
                    );
                    break;
                case TransactionInterface::STATE_FAILED:
                    $message = $transaction['failedReason'] ?? 'Cancellation is failed.';

                    throw new Exception($message);
                default:
                    throw new Exception('Capture is failed.');
            }

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

            throw new Exception($e->getMessage());
        }
    }

    /**
     * Refund Invoice.
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
    public function refundInvoice($orderId, $amount = null, $vatAmount = 0)
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        if (!$amount) {
            $amount = $order->getAmount();
            $vatAmount = $order->getVatAmount();
        }

        if (!$this->canRefund($orderId, $amount)) {
            throw new Exception('Refund action is not available.');
        }

        $paymentId = $order->getPaymentId();
        if (empty($paymentId)) {
            throw new Exception('Unable to get payment ID');
        }

        $transactionData = new TransactionReversal();
        $transactionData
            ->setActivity('FinancingConsumer')
            ->setAmount((int)bcmul(100, $amount))
            ->setVatAmount((int)bcmul(100, $vatAmount))
            ->setDescription(sprintf('Refund for Order #%s.', $order->getOrderId()))
            ->setPayeeReference($this->generatePayeeReference($orderId))
            ->setReceiptReference($this->generatePayeeReference($orderId));

        $transaction = new TransactionObject();
        $transaction->setTransaction($transactionData);

        $requestService = new CreateReversal($transaction);
        $requestService
            ->setClient($this->client)
            ->setPaymentId($paymentId);

        try {
            /** @var ResponseServiceInterface $responseService */
            $responseService = $requestService->send();
            $this->log(
                LogLevel::DEBUG,
                $requestService->getClient()->getDebugInfo()
            );

            $result = $responseService->getResponseData();

            // Save transaction
            $transaction = $result['reversal']['transaction'];
            $this->saveTransaction($orderId, $transaction);

            switch ($transaction['state']) {
                case TransactionInterface::STATE_COMPLETED:
                    $info = $this->fetchPaymentInfo($paymentId);

                    // Check if the payment was refund fully
                    $isFullRefund = false;
                    if (!isset($info['payment']['remainingReversalAmount'])) {
                        // Failback if `remainingReversalAmount` is missing
                        if (bccomp($order->getAmount(), $amount, 2) === 0) {
                            $isFullRefund = true;
                        }
                    } elseif ((int) $info['payment']['remainingReversalAmount'] === 0) {
                        $isFullRefund = true;
                    }

                    if ($isFullRefund) {
                        $this->updateOrderStatus(
                            $orderId,
                            OrderInterface::STATUS_REFUNDED,
                            sprintf('Refunded: %s. Transaction state: %s', $amount, $transaction['state']),
                            $transaction['number']
                        );
                    } else {
                        $this->addOrderNote(
                            $orderId,
                            sprintf('Refunded: %s. Transaction state: %s', $amount, $transaction['state'])
                        );
                    }

                    break;
                case TransactionInterface::STATE_INITIALIZED:
                case TransactionInterface::STATE_AWAITING_ACTIVITY:
                    $this->addOrderNote(
                        $orderId,
                        sprintf('Refunded: %s. Transaction state: %s', $amount, $transaction['state'])
                    );

                    break;
                case TransactionInterface::STATE_FAILED:
                    $message = $transaction['failedReason'] ?? 'Refund is failed.';
                    throw new Exception($message);
                default:
                    throw new Exception('Refund is failed.');
            }

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

            throw new Exception($e->getMessage());
        }
    }
}
