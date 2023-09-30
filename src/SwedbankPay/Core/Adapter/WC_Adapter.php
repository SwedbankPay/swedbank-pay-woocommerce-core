<?php

namespace SwedbankPay\Core\Adapter;

use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Admin;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Admin\Overrides\OrderRefund;
use SwedbankPay\Core\Exception;
use SwedbankPay\Core\Log\LogLevel;
use SwedbankPay\Core\ConfigurationInterface;
use SwedbankPay\Core\Order\PlatformUrlsInterface;
use SwedbankPay\Core\OrderInterface;
use SwedbankPay\Core\OrderItemInterface;
use SwedbankPay\Core\Order\PayeeInfoInterface;
use WC_Payment_Gateway;
use WC_Log_Levels;
use WC_Order_Item_Product;
use WC_Order_Item_Fee;

/**
 * Class WC_Adapter
 * @package SwedbankPay\Core\Adapter
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
// phpcs:ignore
class WC_Adapter implements PaymentAdapterInterface
{
    /**
     * @var WC_Payment_Gateway
     */
    private $gateway;

    /**
     * WC_Adapter constructor.
     *
     * @param WC_Payment_Gateway $gateway
     */
    public function __construct(WC_Payment_Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Log a message.
     *
     * @param $level
     * @param $message
     * @param array $context
     *
     * @see WC_Log_Levels
     */
    public function log($level, $message, array $context = array())
    {
        $logger = wc_get_logger();

        if (!is_string($message)) {
            $message = var_export($message, true);
        }

        $logger->log(
            $level,
            sprintf('[%s] %s [%s]', $level, $message, count($context) > 0 ? var_export($context, true) : ''),
            array_merge(
                $context,
                array(
                    'source' => $this->gateway->id,
                    '_legacy' => true,
                )
            )
        );
    }

    /**
     * Get Initiating System User Agent.
     *
     * @return string
     */
    public function getInitiatingSystemUserAgent()
    {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');

        switch ($this->gateway->id) {
            case 'payex_checkout':
                $plugins = get_plugins();
                foreach ($plugins as $file => $plugin) {
                    if (strpos($file, 'swedbank-pay-woocommerce-checkout.php') !== false) {
                        return 'swedbankpay-woocommerce-checkout/' . $plugin['Version'];
                    }
                }

                return '';
            default:
                $plugins = get_plugins();
                foreach ($plugins as $file => $plugin) {
                    if (strpos($file, 'swedbank-pay-woocommerce-payments.php') !== false) {
                        return 'swedbankpay-woocommerce-payments/' . $plugin['Version'];
                    }
                }

                return '';
        }
    }

    /**
     * Get Adapter Configuration.
     *
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function getConfiguration()
    {
        // phpcs:disable
        return array(
            ConfigurationInterface::DEBUG => 'yes' === $this->gateway->debug,
            ConfigurationInterface::ACCESS_TOKEN => $this->gateway->access_token,
            ConfigurationInterface::PAYEE_ID => $this->gateway->payee_id,
            ConfigurationInterface::PAYEE_NAME => apply_filters(
                'swedbank_pay_payee_name',
                get_bloginfo('name'),
                $this->gateway->id
            ),
            ConfigurationInterface::MODE => 'yes' === $this->gateway->testmode,
            ConfigurationInterface::SUBSITE => apply_filters(
                'swedbank_pay_subsite',
                $this->gateway->subsite,
                $this->gateway->id
            ),
            ConfigurationInterface::LANGUAGE => apply_filters(
                'swedbank_pay_language',
                $this->gateway->culture,
                $this->gateway->id
            ),
            ConfigurationInterface::TERMS_URL => property_exists($this->gateway, 'terms_url') ?
                $this->gateway->terms_url : '',
            ConfigurationInterface::LOGO_URL => property_exists($this->gateway, 'logo_url') ?
                $this->gateway->logo_url : '',
            ConfigurationInterface::USE_PAYER_INFO => property_exists($this->gateway, 'use_payer_info') ?
                'yes' === $this->gateway->use_payer_info : true,
        );
        // phpcs:enable
    }

    /**
     * Get Platform Urls of Actions of Order (complete, cancel, callback urls).
     *
     * @param mixed $orderId
     *
     * @return array
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function getPlatformUrls($orderId)
    {
        $order = wc_get_order($orderId);

        $callbackUrl = add_query_arg(
            array(
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ),
            WC()->api_request_url(get_class($this->gateway))
        );

        return array(
            PlatformUrlsInterface::COMPLETE_URL => $this->gateway->get_return_url($order),
            PlatformUrlsInterface::CANCEL_URL => $order->get_cancel_order_url_raw(),
            PlatformUrlsInterface::CALLBACK_URL => $callbackUrl,
            PlatformUrlsInterface::TERMS_URL => $this->getConfiguration()[ConfigurationInterface::TERMS_URL],
            PlatformUrlsInterface::LOGO_URL => $this->getConfiguration()[ConfigurationInterface::LOGO_URL],
            PlatformUrlsInterface::PAYMENT_URL => null // Seamless requires it
        );
    }

    /**
     * Get Order Data.
     *
     * @param mixed $orderId
     *
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.Superglobals)
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function getOrderData($orderId)
    {
        $order = wc_get_order($orderId);

        // Load parent order if have `OrderRefund`
        if ('order_refund' === $order->get_type() || $order instanceof OrderRefund) {
            throw new \LogicException('Unable to use OrderRefund objects.');
        }

        // Force a new DB read (and update cache) for meta data.
        $order->read_meta_data(true);

        $countries = WC()->countries->countries;

        // Order Info
        $info = $this->getOrderInfo($order);

        // Get order items
        $items = array();

        // Does an order need shipping?
        $needsShipping = false;

        foreach ($order->get_items() as $orderItem) {
            /** @var WC_Order_Item_Product $orderItem */
            $price = $order->get_line_subtotal($orderItem, false, false);
            $priceWithTax = $order->get_line_subtotal($orderItem, true, false);
            $tax = $priceWithTax - $price;
            $taxPercent = ($tax > 0) ? round(100 / ($price / $tax)) : 0;
            $qty = $orderItem->get_quantity();

            // Get Product
            $product = $orderItem->get_product();

            // Get product image
            if ($product && $product->get_image_id()) {
                $image = wp_get_attachment_image_src($product->get_image_id(), 'full');
                $image = array_shift($image);
            } else {
                $image = wc_placeholder_img_src('full');
            }

            if (null === parse_url($image, PHP_URL_SCHEME) &&
                mb_substr($image, 0, mb_strlen(WP_CONTENT_URL), 'UTF-8') === WP_CONTENT_URL
            ) {
                $image = wp_guess_url() . $image;
            }

            // Get Product Class
            $productClass = 'ProductGroup1';
            if ($product) {
                $productClass = $product->get_meta('_swedbank_pay_product_class');
                if (empty($productClass)) {
                    $productClass = apply_filters('swedbank_pay_product_class', 'ProductGroup1', $product);
                }
            }

            // Get Product name
            $productName = trim($orderItem->get_name());
            if (empty($productName)) {
                $productName = '-';
            }

            // Check is product shippable
            if ($product && $product->needs_shipping()) {
                $needsShipping = true;
            }

            // Get product url
            $productUrl = null;
            if ($product) {
                $productUrl = $product->get_permalink();
            }

            // Get Product Sku
            $productReference = null;
            if ($product && $product->get_sku()) {
                $productReference = trim(str_replace(array(' ', '.', ','), '-', $product->get_sku()));
            }

            if (empty($productReference)) {
                $productReference = wp_generate_password(12, false);
            }

            $items[] = array(
                // The field Reference must match the regular expression '[\\w-]*'
                OrderItemInterface::FIELD_REFERENCE => $productReference,
                OrderItemInterface::FIELD_NAME => $productName,
                OrderItemInterface::FIELD_TYPE => OrderItemInterface::TYPE_PRODUCT,
                OrderItemInterface::FIELD_CLASS => $productClass,
                OrderItemInterface::FIELD_ITEM_URL => $productUrl,
                OrderItemInterface::FIELD_IMAGE_URL => $image,
                OrderItemInterface::FIELD_DESCRIPTION => $orderItem->get_name(),
                OrderItemInterface::FIELD_QTY => $qty,
                OrderItemInterface::FIELD_QTY_UNIT => 'pcs',
                OrderItemInterface::FIELD_UNITPRICE => round($priceWithTax / $qty * 100),
                OrderItemInterface::FIELD_VAT_PERCENT => round($taxPercent * 100),
                OrderItemInterface::FIELD_AMOUNT => round($priceWithTax * 100),
                OrderItemInterface::FIELD_VAT_AMOUNT => round($tax * 100),
            );
        }

        // Add Shipping Line
        if ((float)$order->get_shipping_total() > 0) {
            $shipping = $order->get_shipping_total();
            $tax = $order->get_shipping_tax();
            $shippingWithTax = $shipping + $tax;
            $taxPercent = ($tax > 0) ? round(100 / ($shipping / $tax)) : 0;
            $shippingMethod = trim($order->get_shipping_method());

            $items[] = array(
                OrderItemInterface::FIELD_REFERENCE => 'shipping',
                OrderItemInterface::FIELD_NAME => !empty($shippingMethod) ? $shippingMethod : __(
                    'Shipping',
                    'woocommerce'
                ),
                OrderItemInterface::FIELD_TYPE => OrderItemInterface::TYPE_SHIPPING,
                OrderItemInterface::FIELD_CLASS => apply_filters(
                    'swedbank_pay_product_class_shipping',
                    'ProductGroup1',
                    $order
                ),
                OrderItemInterface::FIELD_QTY => 1,
                OrderItemInterface::FIELD_QTY_UNIT => 'pcs',
                OrderItemInterface::FIELD_UNITPRICE => round($shippingWithTax * 100),
                OrderItemInterface::FIELD_VAT_PERCENT => round($taxPercent * 100),
                OrderItemInterface::FIELD_AMOUNT => round($shippingWithTax * 100),
                OrderItemInterface::FIELD_VAT_AMOUNT => round($tax * 100),
            );
        }

        // Add fee lines
        foreach ($order->get_fees() as $orderFee) {
            /** @var WC_Order_Item_Fee $orderFee */
            $fee = $orderFee->get_total();
            $tax = $orderFee->get_total_tax();
            $feeWithTax = $fee + $tax;
            $taxPercent = ($tax > 0) ? round(100 / ($fee / $tax)) : 0;

            $items[] = array(
                OrderItemInterface::FIELD_REFERENCE => 'fee',
                OrderItemInterface::FIELD_NAME => $orderFee->get_name(),
                OrderItemInterface::FIELD_TYPE => OrderItemInterface::TYPE_OTHER,
                OrderItemInterface::FIELD_CLASS => apply_filters(
                    'swedbank_pay_product_class_fee',
                    'ProductGroup1',
                    $order
                ),
                OrderItemInterface::FIELD_QTY => 1,
                OrderItemInterface::FIELD_QTY_UNIT => 'pcs',
                OrderItemInterface::FIELD_UNITPRICE => round($feeWithTax * 100),
                OrderItemInterface::FIELD_VAT_PERCENT => round($taxPercent * 100),
                OrderItemInterface::FIELD_AMOUNT => round($feeWithTax * 100),
                OrderItemInterface::FIELD_VAT_AMOUNT => round($tax * 100),
            );
        }

        // Add discount line
        if ($order->get_total_discount(false) > 0) {
            $discount = abs($order->get_total_discount(true));
            $discountWithTax = abs($order->get_total_discount(false));
            $tax = $discountWithTax - $discount;
            $taxPercent = ($tax > 0) ? round(100 / ($discount / $tax)) : 0;

            $items[] = array(
                OrderItemInterface::FIELD_REFERENCE => 'discount',
                OrderItemInterface::FIELD_NAME => __('Discount', 'swedbank-pay-woocommerce-payments'),
                OrderItemInterface::FIELD_TYPE => OrderItemInterface::TYPE_DISCOUNT,
                OrderItemInterface::FIELD_CLASS => apply_filters(
                    'swedbank_pay_product_class_discount',
                    'ProductGroup1',
                    $order
                ),
                OrderItemInterface::FIELD_QTY => 1,
                OrderItemInterface::FIELD_QTY_UNIT => 'pcs',
                OrderItemInterface::FIELD_UNITPRICE => round(-100 * $discountWithTax),
                OrderItemInterface::FIELD_VAT_PERCENT => round(100 * $taxPercent),
                OrderItemInterface::FIELD_AMOUNT => round(-100 * $discountWithTax),
                OrderItemInterface::FIELD_VAT_AMOUNT => round(-100 * $tax),
            );
        }

        // Payer reference
        // Get Customer UUID
        if (method_exists($order, 'get_customer_id')) {
            $userId = $order->get_customer_id();
        } elseif (method_exists($order, 'get_report_customer_id')) {
            /** @var \Automattic\WooCommerce\Admin\Overrides\OrderRefund $order */
            $userId = $order->get_report_customer_id();
        } else {
            $userId = get_current_user_id();
        }

        if ($userId > 0) {
            $payerReference = get_user_meta($userId, '_payex_customer_uuid', true);
            if (empty($payerReference)) {
                $payerReference = $this->getUuid($userId);
                update_user_meta($userId, '_payex_customer_uuid', $payerReference);
            }
        } else {
            $payerReference = $this->getUuid(uniqid($order->get_billing_email()));
        }

        $shippingCountry = isset($countries[$order->get_shipping_country()]) ?
            $countries[$order->get_shipping_country()] : '';

        $billingCountry = isset($countries[$order->get_billing_country()]) ?
            $countries[$order->get_billing_country()] : '';

        $items = apply_filters('swedbank_pay_order_items', $items, $order);

        $userAgent = $order->get_customer_user_agent();
        if (empty($userAgent)) {
            $userAgent = 'WooCommerce/' . WC()->version;
        }

        return array(
            OrderInterface::ORDER_ID => $order->get_id(),
            OrderInterface::AMOUNT => apply_filters(
                'swedbank_pay_order_amount',
                $order->get_total(),
                $items,
                $order
            ),
            OrderInterface::VAT_AMOUNT => apply_filters(
                'swedbank_pay_order_vat',
                $info['vat_amount'],
                $items,
                $order
            ),
            OrderInterface::VAT_RATE => 0, // Can be different
            OrderInterface::SHIPPING_AMOUNT => 0, // @todo
            OrderInterface::SHIPPING_VAT_AMOUNT => 0, // @todo
            // phpcs:disable
            OrderInterface::DESCRIPTION => apply_filters(
                'swedbank_pay_payment_description',
                sprintf(
                /* translators: 1: order id */                    __('Order #%1$s', 'swedbank-pay-woocommerce-payments'),
                    $order->get_order_number()
                ),
                $order
            ),
            // phpcs:enable
            OrderInterface::CURRENCY => $order->get_currency(),
            OrderInterface::STATUS => $this->getOrderStatus($orderId),
            OrderInterface::CREATED_AT => gmdate('Y-m-d H:i:s', $order->get_date_created()->getTimestamp()),
            OrderInterface::PAYMENT_ID => $order->get_meta('_payex_payment_id'),
            OrderInterface::PAYMENT_ORDER_ID => $order->get_meta('_payex_paymentorder_id'),
            OrderInterface::NEEDS_SAVE_TOKEN_FLAG => '1' === $order->get_meta('_payex_generate_token') &&
                0 === count($order->get_payment_tokens()),
            OrderInterface::NEEDS_SHIPPING => $needsShipping,
            OrderInterface::HTTP_ACCEPT => isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : null,
            OrderInterface::HTTP_USER_AGENT => $userAgent,
            OrderInterface::BILLING_COUNTRY => $billingCountry,
            OrderInterface::BILLING_COUNTRY_CODE => $order->get_billing_country(),
            OrderInterface::BILLING_ADDRESS1 => $order->get_billing_address_1(),
            OrderInterface::BILLING_ADDRESS2 => $order->get_billing_address_2(),
            OrderInterface::BILLING_ADDRESS3 => null,
            OrderInterface::BILLING_CITY => $order->get_billing_city(),
            OrderInterface::BILLING_STATE => $order->get_billing_state(),
            OrderInterface::BILLING_POSTCODE => $order->get_billing_postcode(),
            OrderInterface::BILLING_PHONE => apply_filters(
                'swedbank_pay_order_billing_phone',
                $order->get_billing_phone(),
                $order
            ),
            OrderInterface::BILLING_EMAIL => $order->get_billing_email(),
            OrderInterface::BILLING_FIRST_NAME => $order->get_billing_first_name(),
            OrderInterface::BILLING_LAST_NAME => $order->get_billing_last_name(),
            OrderInterface::SHIPPING_COUNTRY => $shippingCountry,
            OrderInterface::SHIPPING_COUNTRY_CODE => $order->get_shipping_country(),
            OrderInterface::SHIPPING_ADDRESS1 => $order->get_shipping_address_1(),
            OrderInterface::SHIPPING_ADDRESS2 => $order->get_shipping_address_2(),
            OrderInterface::SHIPPING_ADDRESS3 => null,
            OrderInterface::SHIPPING_CITY => $order->get_shipping_city(),
            OrderInterface::SHIPPING_STATE => $order->get_shipping_state(),
            OrderInterface::SHIPPING_POSTCODE => $order->get_shipping_postcode(),
            OrderInterface::SHIPPING_PHONE => apply_filters(
                'swedbank_pay_order_billing_phone',
                $order->get_billing_phone(),
                $order
            ),
            OrderInterface::SHIPPING_EMAIL => $order->get_billing_email(),
            OrderInterface::SHIPPING_FIRST_NAME => $order->get_shipping_first_name(),
            OrderInterface::SHIPPING_LAST_NAME => $order->get_shipping_last_name(),
            OrderInterface::CUSTOMER_ID => (int)$order->get_customer_id(),
            OrderInterface::CUSTOMER_IP => $order->get_customer_ip_address(),
            OrderInterface::PAYER_REFERENCE => $payerReference,
            OrderInterface::ITEMS => $items,
            OrderInterface::LANGUAGE => $this->getConfiguration()[ConfigurationInterface::LANGUAGE],
        );
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
        $order = wc_get_order($orderId);

        return array(
            PayeeInfoInterface::ORDER_REFERENCE => apply_filters(
                'swedbank_pay_order_reference',
                $order->get_id()
            ),
        );
    }

    /**
     * Check if order status can be updated.
     *
     * @param mixed $orderId
     * @param string $status
     * @param string|null $transactionNumber
     * @return bool
     */
    public function canUpdateOrderStatus($orderId, $status, $transactionNumber = null)
    {
        if ($transactionNumber) {
            $order = wc_get_order($orderId);

            if ($order->get_transaction_id() === $transactionNumber) {
                $this->log(
                    LogLevel::WARNING,
                    sprintf(
                        'Unable to update order status of #%s (%s). Transaction #%s has been processed.',
                        $orderId,
                        $status,
                        $transactionNumber
                    )
                );

                return false;
            }
        }

        return true;
    }


    /**
     * Get Order Status.
     *
     * @param $orderId
     *
     * @return string
     * @see wc_get_order_statuses()
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function getOrderStatus($orderId)
    {
        $order = wc_get_order($orderId);

        switch ($order->get_status()) {
            case 'checkout-draft':
            case 'pending':
                return OrderInterface::STATUS_PENDING;
            case 'on-hold':
                return OrderInterface::STATUS_AUTHORIZED;
            case 'active':
            case 'completed':
            case 'processing':
                return OrderInterface::STATUS_CAPTURED;
            case 'cancelled':
                return OrderInterface::STATUS_CANCELLED;
            case 'refunded':
                return OrderInterface::STATUS_REFUNDED;
            case 'failed':
                return OrderInterface::STATUS_FAILED;
            case 'pending-cancel':
            case 'expired':
            default:
                return OrderInterface::STATUS_UNKNOWN;
        }
    }

    /**
     * Update Order Status.
     *
     * @param mixed $orderId
     * @param string $status
     * @param string|null $message
     * @param mixed|null $transactionNumber
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ElseExpression)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function updateOrderStatus($orderId, $status, $message = null, $transactionNumber = null)
    {
        remove_action(
            'woocommerce_order_status_changed',
            [Swedbank_Pay_Admin::class, 'order_status_changed_transaction'],
            0
        );

        $order = wc_get_order($orderId);

        if ($transactionNumber) {
            $transactions = (array) $order->get_meta('_swedbank_pay_transactions');
            if (in_array($transactionNumber, $transactions)) {
                $this->log('info', sprintf('Skip order status update #%s to %s', $orderId, $status));
                return;
            }

            $transactions[] = $transactionNumber;

            $order->set_transaction_id($transactionNumber);
            $order->update_meta_data('_swedbank_pay_transactions', $transactions);
            $order->save();
        }

        $this->log(
            'info',
            sprintf(
                'Update order status #%s to %s. Transaction ID: %s',
                $orderId,
                $status,
                $transactionNumber
            )
        );

        switch ($status) {
            case OrderInterface::STATUS_PENDING:
                $order->update_meta_data('_payex_payment_state', $status);
                $order->save();

                // Set on-hold
                if (!$order->has_status('on-hold')) {
                    $order->update_status('on-hold', $message);
                } elseif ($message) {
                    $order->add_order_note($message);
                }

                break;
            case OrderInterface::STATUS_AUTHORIZED:
                $order->update_meta_data('_payex_payment_state', $status);
                $order->save();

                // Set on-hold
                if (!$order->has_status('on-hold')) {
                    // Reduce stock
                    wc_maybe_reduce_stock_levels($order->get_id());

                    $order->update_status('on-hold', $message);
                } elseif ($message) {
                    $order->add_order_note($message);
                }

                break;
            case OrderInterface::STATUS_CAPTURED:
                $order->update_meta_data('_payex_payment_state', $status);
                $order->save();

                if (!$order->is_paid()) {
                    $order->payment_complete($transactionNumber);

                    if ($message) {
                        $order->add_order_note($message);
                    }
                } else {
                    $order->update_status(
                        apply_filters(
                            'woocommerce_payment_complete_order_status',
                            $order->needs_processing() ? 'processing' : 'completed',
                            $order->get_id(),
                            $order
                        ),
                        $message
                    );
                }

                break;
            case OrderInterface::STATUS_CANCELLED:
                $order->update_meta_data('_payex_payment_state', $status);
                $order->save();

                // Set cancelled
                if (!$order->has_status('cancelled')) {
                    $order->update_status('cancelled', $message);
                } elseif ($message) {
                    $order->add_order_note($message);
                }

                break;
            case OrderInterface::STATUS_REFUNDED:
                // @todo Implement Refunds creation
                // @see wc_create_refund()

                $order->update_meta_data('_payex_payment_state', $status);
                $order->save();

                if (function_exists('wcs_is_subscription') && wcs_is_subscription($order)) {
                    /** @var $order \WC_Subscription */
                    $parent = $order->get_parent();
                    if ($parent) {
                        $parent->update_status('refunded', $message);
                    }
                } else {
                    if (!$order->has_status('refunded')) {
                        $order->update_status('refunded', $message);
                    } elseif ($message) {
                        $order->add_order_note($message);
                    }
                }

                break;
            case OrderInterface::STATUS_FAILED:
                if (!$order->is_paid()) {
                    $order->update_status('failed', $message);
                } elseif ($message) {
                    $order->add_order_note($message);
                }

                break;
        }
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
        $order = wc_get_order($orderId);

        $order->update_meta_data('_payex_paymentorder_id', $paymentOrderId);
        $order->save();
    }

    /**
     * Add Order Note.
     *
     * @param mixed $orderId
     * @param string $message
     */
    public function addOrderNote($orderId, $message)
    {
        $order = wc_get_order($orderId);
        $order->add_order_note($message);
    }

    /**
     * Save Transaction data.
     *
     * @param mixed $orderId
     * @param array $transactionData
     */
    public function saveFinancialTransaction($orderId, array $transactionData = array())
    {
        $this->gateway->transactions->import($transactionData, $orderId);
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
        return $this->gateway->transactions->get_by($field, $value, true);
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
        return apply_filters('swedbank_pay_payment_object', $paymentObject, $orderId);
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
        return apply_filters('swedbank_pay_transaction_object', $transactionObject, $orderId);
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

        $reference = $orderId . 'x' . substr(implode('', $arr), 0, 5);

        return apply_filters('swedbank_pay_payee_reference', $reference, $orderId);
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
        if (!$transactionId) {
            throw new Exception('Unable to create refund memo. Transaction ID is missing.', 500);
        }

        // Prevent refund credit memo creation through Callback
        if (get_transient('sb_refund_block_' . $orderId)) {
            //delete_transient('sb_refund_block_' . $orderId);
            return;
        }

        // Create the refund
        $refund = wc_create_refund(
            array(
                'order_id' => $orderId,
                'amount' => $amount,
                'reason' => $description,
                'refund_payment' => false,
                'restock_items' => false,
            )
        );

        if (is_wp_error($refund)) {
            throw new Exception($refund->get_error_message(), 500);
        }

        if (!$refund) {
            throw new Exception('Cannot create order refund, please try again.', 500);
        }

        // Add transaction id to identify refund memo
        $refund->update_meta_data('_transaction_id', $transactionId);
        $refund->save();

        $this->log(
            'info',
            sprintf(
                'Created Credit Memo for #%s. Refunded: %s. Transaction ID: %s. Description: %s',
                $orderId,
                $amount,
                $transactionId,
                $description
            ),
            [
                $refund->get_id()
            ]
        );
    }

    /**
     * Check if Credit Memo exist by Transaction ID.
     *
     * @param string $transactionId
     *
     * @return bool
     */
    public function isCreditMemoExist($transactionId)
    {
        if (!$this->isHOPSEnabled()) {
            global $wpdb;

            $query = "
                    SELECT post_id FROM `{$wpdb->prefix}postmeta` postmeta
                    LEFT JOIN `{$wpdb->prefix}posts` AS posts ON postmeta.post_id = posts.ID
                    WHERE meta_key='_transaction_id' AND meta_value=%s AND posts.post_type='shop_order_refund';
                ";

            if ($wpdb->get_var($wpdb->prepare($query, $transactionId))) {
                // Credit Memo is already exists
                return true;
            }

            return false;
        }

        $orders = wc_get_orders(
            array(
                'type'           => 'shop_order_refund',
                'return'         => 'ids',
                'limit'          => 1,
                'transaction_id' => $transactionId,
            )
        );

        return count($orders) > 0;
    }

    /**
     * Get Implementation.
     *
     * @return string|null
     */
    public function getImplementation()
    {
        if (method_exists($this->gateway, 'get_implementation')) {
            return $this->gateway->get_implementation();
        }

        return null;
    }

    /**
     * Get Order Lines
     *
     * @param \WC_Order $order
     *
     * @return array
     */
    private function getOrderItems($order)
    {
        $item = array();

        foreach ($order->get_items() as $orderItem) {
            /** @var WC_Order_Item_Product $orderItem */
            $price = $order->get_line_subtotal($orderItem, false, false);
            $priceWithTax = $order->get_line_subtotal($orderItem, true, false);
            $tax = $priceWithTax - $price;
            $taxPercent = ($tax > 0) ? round(100 / ($price / $tax)) : 0;

            $item[] = array(
                'type' => 'product',
                'name' => $orderItem->get_name(),
                'qty' => $orderItem->get_quantity(),
                'price_with_tax' => sprintf('%.2f', $priceWithTax),
                'price_without_tax' => sprintf('%.2f', $price),
                'tax_price' => sprintf('%.2f', $tax),
                'tax_percent' => sprintf('%.2f', $taxPercent),
            );
        };

        // Add Shipping Line
        if ((float)$order->get_shipping_total() > 0) {
            $shipping = $order->get_shipping_total();
            $tax = $order->get_shipping_tax();
            $shippingWithTax = $shipping + $tax;
            $taxPercent = ($tax > 0) ? round(100 / ($shipping / $tax)) : 0;

            $item[] = array(
                'type' => 'shipping',
                'name' => $order->get_shipping_method(),
                'qty' => 1,
                'price_with_tax' => sprintf('%.2f', $shippingWithTax),
                'price_without_tax' => sprintf('%.2f', $shipping),
                'tax_price' => sprintf('%.2f', $tax),
                'tax_percent' => sprintf('%.2f', $taxPercent),
            );
        }

        // Add fee lines
        foreach ($order->get_fees() as $orderFee) {
            /** @var WC_Order_Item_Fee $orderFee */
            $fee = $orderFee->get_total();
            $tax = $orderFee->get_total_tax();
            $feeWithTax = $fee + $tax;
            $taxPercent = ($tax > 0) ? round(100 / ($fee / $tax)) : 0;

            $item[] = array(
                'type' => 'fee',
                'name' => $orderFee->get_name(),
                'qty' => 1,
                'price_with_tax' => sprintf('%.2f', $feeWithTax),
                'price_without_tax' => sprintf('%.2f', $fee),
                'tax_price' => sprintf('%.2f', $tax),
                'tax_percent' => sprintf('%.2f', $taxPercent),
            );
        }

        // Add discount line
        if ($order->get_total_discount(false) > 0) {
            $discount = $order->get_total_discount(true);
            $discountWithTax = $order->get_total_discount(false);
            $tax = $discountWithTax - $discount;
            $taxPercent = ($tax > 0) ? round(100 / ($discount / $tax)) : 0;

            $item[] = array(
                'type' => 'discount',
                'name' => __('Discount', 'swedbank-pay-woocommerce-payments'),
                'qty' => 1,
                'price_with_tax' => sprintf('%.2f', -1 * $discountWithTax),
                'price_without_tax' => sprintf('%.2f', -1 * $discount),
                'tax_price' => sprintf('%.2f', -1 * $tax),
                'tax_percent' => sprintf('%.2f', $taxPercent),
            );
        }

        return $item;
    }

    /**
     * Get Order Info
     *
     * @param \WC_Order $order
     *
     * @return array
     */
    private function getOrderInfo($order)
    {
        $amount = 0;
        $vatAmount = 0;
        $descriptions = array();
        $items = $this->getOrderItems($order);
        foreach ($items as $item) {
            $amount += $item['price_with_tax'];
            $vatAmount += $item['tax_price'];
            $descriptions[] = array(
                'amount' => $item['price_with_tax'],
                'vatAmount' => $item['tax_price'], // @todo Validate
                'itemAmount' => sprintf('%.2f', $item['price_with_tax'] / $item['qty']),
                'quantity' => $item['qty'],
                'description' => $item['name'],
            );
        }

        return array(
            'amount' => $amount,
            'vat_amount' => $vatAmount,
            'items' => $descriptions,
        );
    }

    /**
     * Generate UUID
     *
     * @param $node
     *
     * @return string
     */
    private function getUuid($node)
    {
        return apply_filters('swedbank_pay_generate_uuid', $node);
    }

    /**
     * Checks if High-Performance Order Storage is enabled.
     *
     * @see https://woocommerce.com/document/high-performance-order-storage/
     * @see https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book
     * @return bool
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function isHOPSEnabled()
    {
        if (!class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return false;
        }

        if (!method_exists(OrderUtil::class, 'custom_orders_table_usage_is_enabled')) {
            return false;
        }

        return OrderUtil::custom_orders_table_usage_is_enabled();
    }
}
