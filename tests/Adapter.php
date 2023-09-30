<?php

use SwedbankPay\Core\Adapter\PaymentAdapterInterface;
use SwedbankPay\Core\ConfigurationInterface;
use SwedbankPay\Core\Order\PlatformUrlsInterface;
use SwedbankPay\Core\OrderInterface;
use SwedbankPay\Core\OrderItemInterface;
use SwedbankPay\Core\Order\PayeeInfoInterface;
use SwedbankPay\Core\Exception;

class Adapter implements PaymentAdapterInterface
{
    /**
     * @var Gateway
     */
    private $gateway;

    /**
     * Adapter constructor.
     *
     * @param Gateway $gateway
     */
    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Log a message.
     *
     * @param $level
     * @param $message
     * @param array $context
     */
    public function log($level, $message, array $context = [])
    {
        file_put_contents(
            sys_get_temp_dir() . '/swedbankpay.log',
            sprintf('[%s] %s [%s]', $level, $message, var_export($context, true)) . "\n",
            FILE_APPEND
        );
    }

    /**
     * Get Initiating System User Agent.
     * @return string
     */
    public function getInitiatingSystemUserAgent()
    {
        return 'Test adapter';
    }

    /**
     * Get Adapter Configuration.
     *
     * @return array
     */
    public function getConfiguration()
    {
        return [
            ConfigurationInterface::DEBUG => $this->gateway->debug,
            ConfigurationInterface::ACCESS_TOKEN => $this->gateway->access_token,
            ConfigurationInterface::PAYEE_ID => $this->gateway->payee_id,
            ConfigurationInterface::PAYEE_NAME => $this->gateway->payee_name,
            ConfigurationInterface::MODE => $this->gateway->testmode,
            ConfigurationInterface::SUBSITE => $this->gateway->subsite,
            ConfigurationInterface::LANGUAGE => $this->gateway->language,
            ConfigurationInterface::TERMS_URL => property_exists($this->gateway, 'terms_url') ?
                $this->gateway->terms_url : '',
            ConfigurationInterface::LOGO_URL => property_exists($this->gateway, 'logo_url') ?
                $this->gateway->logo_url : '',
            ConfigurationInterface::USE_PAYER_INFO => property_exists($this->gateway, 'use_payer_info') ?
                'yes' === $this->gateway->use_payer_info : true,
        ];
    }

    /**
     * Get Platform Urls of Actions of Order (complete, cancel, callback urls).
     *
     * @param mixed $orderId
     *
     * @return array
     */
    public function getPlatformUrls($orderId)
    {
        return [
            PlatformUrlsInterface::COMPLETE_URL => 'https://example.com/complete',
            PlatformUrlsInterface::CANCEL_URL => 'https://example.com/cancel',
            PlatformUrlsInterface::CALLBACK_URL => 'https://example.com/callback',
            PlatformUrlsInterface::TERMS_URL => 'https://example.com/terms',
            PlatformUrlsInterface::LOGO_URL => 'https://example.com/logo.png'
        ];
    }

    /**
     * Get Order Data.
     *
     * @param mixed $orderId
     *
     * @return array
     */
    public function getOrderData($orderId)
    {
        $items = [];
        $items[] = [
            // The field Reference must match the regular expression '[\\w-]*'
            OrderItemInterface::FIELD_REFERENCE   => 'TEST',
            OrderItemInterface::FIELD_NAME        => 'Test',
            OrderItemInterface::FIELD_TYPE        => OrderItemInterface::TYPE_PRODUCT,
            OrderItemInterface::FIELD_CLASS       => 'product',
            OrderItemInterface::FIELD_ITEM_URL    => 'https://example.com/product1',
            OrderItemInterface::FIELD_IMAGE_URL   => 'https://example.com/product1.jpg',
            OrderItemInterface::FIELD_DESCRIPTION => 'Test product',
            OrderItemInterface::FIELD_QTY         => 1,
            OrderItemInterface::FIELD_QTY_UNIT    => 'pcs',
            OrderItemInterface::FIELD_UNITPRICE   => round( 125 * 100 ),
            OrderItemInterface::FIELD_VAT_PERCENT => round( 25 * 100 ),
            OrderItemInterface::FIELD_AMOUNT      => round( 125 * 100 ),
            OrderItemInterface::FIELD_VAT_AMOUNT  => round( 25 * 100 ),
        ];

        return [
            OrderInterface::ORDER_ID => $orderId,
            OrderInterface::AMOUNT => 125,
            OrderInterface::VAT_AMOUNT => 25,
            OrderInterface::VAT_RATE => 25,
            OrderInterface::SHIPPING_AMOUNT => 0,
            OrderInterface::SHIPPING_VAT_AMOUNT => 0,
            OrderInterface::DESCRIPTION => 'Test order',
            OrderInterface::CURRENCY => $this->gateway->currency,
            OrderInterface::STATUS => OrderInterface::STATUS_AUTHORIZED,
            OrderInterface::CREATED_AT => gmdate( 'Y-m-d H:i:s' ),
            OrderInterface::PAYMENT_ID => '/psp/creditcard/payments/70abecfe-7ed3-47cb-a0ef-08db0362a155',
            OrderInterface::PAYMENT_ORDER_ID => '/psp/paymentorders/a074162f-68d2-4eeb-e201-08db05daa63c',
            OrderInterface::NEEDS_SAVE_TOKEN_FLAG => false,
            OrderInterface::HTTP_ACCEPT => null,
            OrderInterface::HTTP_USER_AGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0',
            OrderInterface::BILLING_COUNTRY => 'Sweden',
            OrderInterface::BILLING_COUNTRY_CODE => 'SE',
            OrderInterface::BILLING_ADDRESS1 => 'Hökvägen 5',
            OrderInterface::BILLING_ADDRESS2 => '',
            OrderInterface::BILLING_ADDRESS3 => '',
            OrderInterface::BILLING_CITY => 'Järfälla',
            OrderInterface::BILLING_STATE => null,
            OrderInterface::BILLING_POSTCODE => '17674',
            OrderInterface::BILLING_PHONE => '+46739000001',
            OrderInterface::BILLING_EMAIL => 'leia.ahlstrom@payex.com',
            OrderInterface::BILLING_FIRST_NAME => 'Leia',
            OrderInterface::BILLING_LAST_NAME => 'Ahlström',
            OrderInterface::SHIPPING_COUNTRY => 'Sweden',
            OrderInterface::SHIPPING_COUNTRY_CODE => 'SE',
            OrderInterface::SHIPPING_ADDRESS1 => 'Hökvägen 5',
            OrderInterface::SHIPPING_ADDRESS2 => '',
            OrderInterface::SHIPPING_ADDRESS3 => '',
            OrderInterface::SHIPPING_CITY => 'Järfälla',
            OrderInterface::SHIPPING_STATE => null,
            OrderInterface::SHIPPING_POSTCODE => '17674',
            OrderInterface::SHIPPING_PHONE => '+46739000001',
            OrderInterface::SHIPPING_EMAIL => 'leia.ahlstrom@payex.com',
            OrderInterface::SHIPPING_FIRST_NAME => 'Leia',
            OrderInterface::SHIPPING_LAST_NAME => 'Ahlström',
            OrderInterface::CUSTOMER_ID => 1,
            OrderInterface::CUSTOMER_IP => '127.0.0.1',
            OrderInterface::PAYER_REFERENCE => uniqid('ref'),
            OrderInterface::ITEMS => $items,
            OrderInterface::LANGUAGE => 'en-US',
        ];
    }

    /**
     * Get Payee Info of Order.
     *
     * @param mixed $orderId
     *
     * @return array
     */
    public function getPayeeInfo($orderId)
    {
        return array(
            PayeeInfoInterface::ORDER_REFERENCE => $orderId,
        );
    }

    /**
     * Check if order status can be updated.
     *
     * @param mixed $orderId
     * @param string $status
     * @param string|null $transactionNumber
     *
     * @return bool
     */
    public function canUpdateOrderStatus($orderId, $status, $transactionNumber = null) {
        return true;
    }

    /**
     * Update Order Status.
     *
     * @param mixed $orderId
     * @param string $status
     * @param string|null $message
     * @param mixed|null $transactionNumber
     */
    public function updateOrderStatus($orderId, $status, $message = null, $transactionNumber = null)
    {
        // @todo
    }

    /**
     * Add Order Note.
     *
     * @param mixed $orderId
     * @param string $message
     */
    public function addOrderNote($orderId, $message)
    {
        // @todo
    }

    /**
     * Save Transaction data.
     *
     * @param mixed $orderId
     * @param array $transactionData
     */
    public function saveFinancialTransaction($orderId, array $transactionData = [])
    {
        // @todo
    }

    /**
     * Find for Transaction.
     *
     * @param $field
     * @param $value
     *
     * @return array
     */
    public function findFinancialTransaction($field, $value)
    {
        // @todo
    }

    /**
     * Get Order Status.
     *
     * @param $orderId
     *
     * @return string
     * @throws Exception
     *@see wc_get_order_statuses()
     */
    public function getOrderStatus($orderId)
    {
        return OrderInterface::STATUS_AUTHORIZED;
    }

    /**
     * Set Payment Order Id to Order.
     *
     * @param mixed $orderId
     * @param string $paymentOrderId
     *
     * @return void
     */
    public function setPaymentOrderId($orderId, $paymentOrderId)
    {
        // @todo
    }

    /**
     * Process payment object.
     *
     * @param mixed $paymentObject
     * @param mixed $orderId
     *
     * @return mixed
     */
    public function processPaymentObject($paymentObject, $orderId)
    {
        return $paymentObject;
    }

    /**
     * Process transaction object.
     *
     * @param mixed $transactionObject
     * @param mixed $orderId
     *
     * @return mixed
     */
    public function processTransactionObject($transactionObject, $orderId)
    {
        return $transactionObject;
    }

    /**
     * Generate Payee Reference for Order.
     *
     * @param mixed $orderId
     *
     * @return string
     */
    public function generatePayeeReference($orderId)
    {
        $arr = range('a', 'z');
        shuffle($arr);

        return $orderId . 'x' . substr(implode('', $arr), 0, 5);
    }

    /**
     * Create Credit Memo.
     *
     * @param mixed $orderId
     * @param float $amount
     * @param mixed $transactionId
     * @param string $description
     *
     * @throws Exception
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function createCreditMemo($orderId, $amount, $transactionId, $description)
    {
        throw new Exception('createCreditMemo exception');
    }

    /**
     * Check if Credit Memo exist.
     *
     * @param string $transactionId
     *
     * @return bool
     */
    public function isCreditMemoExist($transactionId)
    {
        return false;
    }

    /**
     * Get Implementation.
     *
     * @return string
     */
    public function getImplementation()
    {
        return null;
    }
}
