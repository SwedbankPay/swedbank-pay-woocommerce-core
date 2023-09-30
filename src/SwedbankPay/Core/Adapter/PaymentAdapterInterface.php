<?php

namespace SwedbankPay\Core\Adapter;

/**
 * Interface PaymentAdapterInterface
 * @package SwedbankPay\Core
 */
interface PaymentAdapterInterface
{
    const PRODUCT_CHECKOUT2 = 'Checkout2';
    const PRODUCT_CHECKOUT3 = 'Checkout3';

    const IMPLEMENTATION_STARTER = 'Starter';
    const IMPLEMENTATION_ENTERPRISE = 'Enterprise';
    const IMPLEMENTATION_PAYMENTS_ONLY = 'PaymentsOnly';

    /**
     * Log a message.
     *
     * @param $level
     * @param $message
     * @param array $context
     *
     * @see WC_Log_Levels
     */
    public function log($level, $message, array $context = []);

    /**
     * Get Initiating System User Agent.
     *
     * @return string
     */
    public function getInitiatingSystemUserAgent();

    /**
     * Get Adapter Configuration.
     *
     * @return array
     */
    public function getConfiguration();

    /**
     * Get Platform Urls of Actions of Order (complete, cancel, callback urls).
     *
     * @param mixed $orderId
     *
     * @return array
     */
    public function getPlatformUrls($orderId);

    /**
     * Get Order Data.
     *
     * @param mixed $orderId
     *
     * @return array
     */
    public function getOrderData($orderId);

    /**
     * Get Payee Info of Order.
     *
     * @param mixed $orderId
     *
     * @return array
     */
    public function getPayeeInfo($orderId);

    /**
     * Check if order status can be updated.
     *
     * @param mixed $orderId
     * @param string $status
     * @param string|null $transactionNumber
     *
     * @return bool
     */
    public function canUpdateOrderStatus($orderId, $status, $transactionNumber = null);

    /**
     * Get Order Status.
     *
     * @param $order_id
     *
     * @see wc_get_order_statuses()
     * @return string
     */
    public function getOrderStatus($orderId);

    /**
     * Update Order Status.
     *
     * @param mixed $orderId
     * @param string $status
     * @param string|null $message
     * @param mixed|null $transactionNumber
     */
    public function updateOrderStatus($orderId, $status, $message = null, $transactionNumber = null);

    /**
     * Set Payment Order Id to Order.
     *
     * @param mixed $orderId
     * @param string $paymentOrderId
     *
     * @return void
     */
    public function setPaymentOrderId($orderId, $paymentOrderId);

    /**
     * Add Order Note.
     *
     * @param mixed $orderId
     * @param string $message
     */
    public function addOrderNote($orderId, $message);

    /**
     * Save Transaction data.
     *
     * @param mixed $orderId
     * @param array $transactionData
     */
    public function saveFinancialTransaction($orderId, array $transactionData = []);

    /**
     * Find for Transaction.
     *
     * @param $field
     * @param $value
     *
     * @return array
     */
    public function findFinancialTransaction($field, $value);

    /**
     * Process payment object.
     *
     * @param mixed $paymentObject
     * @param mixed $orderId
     *
     * @return mixed
     */
    public function processPaymentObject($paymentObject, $orderId);

    /**
     * Process transaction object.
     *
     * @param mixed $transactionObject
     * @param mixed $orderId
     *
     * @return mixed
     */
    public function processTransactionObject($transactionObject, $orderId);

    /**
     * Generate Payee Reference for Order.
     *
     * @param mixed $orderId
     *
     * @return string
     */
    public function generatePayeeReference($orderId);

    /**
     * Create Credit Memo.
     *
     * @param mixed $orderId
     * @param float $amount
     * @param mixed $transactionId
     * @param string $description
     */
    public function createCreditMemo($orderId, $amount, $transactionId, $description);

    /**
     * Check if Credit Memo exist.
     *
     * @param string $transactionId
     *
     * @return bool
     */
    public function isCreditMemoExist($transactionId);

    /**
     * Get Implementation.
     *
     * @return string|null
     */
    public function getImplementation();
}
