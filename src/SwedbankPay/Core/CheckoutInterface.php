<?php

namespace SwedbankPay\Core;

use SwedbankPay\Core\Api\Response;
use SwedbankPay\Core\Exception;

/**
 * Interface CheckoutInterface
 * @package SwedbankPay\Core\Library\Methods
 */
interface CheckoutInterface
{
    const PAYMENTORDER_DELETE_TOKEN_URL = '/psp/paymentorders/payerownedtokens/%s';

    /**
     * Initiate Payment Order Purchase.
     *
     * @param mixed $orderId
     * @param string|null $consumerProfileRef
     *
     * @return Response
     * @throws Exception
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function initiatePaymentOrderPurchase(
        $orderId,
        $consumerProfileRef = null
    );

    /**
     * Initiate Payment Order Verify
     *
     * @param mixed $orderId
     *
     * @return Response
     * @throws Exception
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function initiatePaymentOrderVerify(
        $orderId
    );

    /**
     * Initiate Payment Order Recurrent Payment
     *
     * @param mixed $orderId
     * @param string $recurrenceToken
     *
     * @return Response
     * @throws \Exception
     */
    public function initiatePaymentOrderRecur($orderId, $recurrenceToken);

    /**
     * Initiate Payment Order Unscheduled Payment.
     *
     * @param mixed $orderId
     * @param string $unscheduledToken
     *
     * @return Response
     * @throws \Exception
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function initiatePaymentOrderUnscheduledPurchase($orderId, $unscheduledToken);

    /**
     * @param string $updateUrl
     * @param mixed $orderId
     *
     * @return Response
     * @throws Exception
     */
    public function updatePaymentOrder($updateUrl, $orderId);

    /**
     * Capture Checkout.
     *
     * @param mixed $orderId
     * @param \SwedbankPay\Core\OrderItem[] $items
     *
     * @return Response
     * @throws Exception
     */
    public function captureCheckout($orderId, array $items = []);

    /**
     * Cancel Checkout.
     *
     * @param mixed $orderId
     * @param int|float|null $amount
     * @param int|float $vatAmount
     *
     * @return Response
     * @throws Exception
     */
    public function cancelCheckout($orderId, $amount = null, $vatAmount = 0);

    /**
     * Refund Checkout.
     *
     * @param mixed $orderId
     * @param \SwedbankPay\Core\OrderItem[] $items
     *
     * @return Response
     * @throws Exception
     */
    public function refundCheckout($orderId, array $items = []);
}
