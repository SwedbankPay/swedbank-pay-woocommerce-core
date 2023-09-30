<?php

namespace SwedbankPay\Core\Traits;

use InvalidArgumentException;
use SwedbankPay\Core\Api\FinancialTransaction;
use SwedbankPay\Core\Api\FinancialTransactionInterface;
use SwedbankPay\Core\Exception;
use SwedbankPay\Core\Log\LogLevel;
use SwedbankPay\Core\Order;
use SwedbankPay\Core\OrderInterface;

trait OrderAction
{
    /**
     * Can Capture.
     *
     * @param mixed $orderId
     * @param float|int|null $amount
     *
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function canCapture($orderId, $amount = null)
    {
        // Check the payment state online
        $order = $this->getOrder($orderId);

        try {
            $result = $this->fetchPaymentInfo($order->getPaymentOrderId());
        } catch (\Exception $e) {
            // Request failed
            return false;
        }

        return isset($result['paymentOrder']['remainingCaptureAmount'])
               && (float)$result['paymentOrder']['remainingCaptureAmount'] > 0.1;
    }

    /**
     * Can Cancel.
     *
     * @param mixed $orderId
     * @param float|int|null $amount
     *
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function canCancel($orderId, $amount = null)
    {
        // Check the payment state online
        $order = $this->getOrder($orderId);

        // Fetch payment info
        try {
            $result = $this->fetchPaymentInfo($order->getPaymentOrderId());
        } catch (\Exception $e) {
            // Request failed
            return false;
        }

        return isset($result['paymentOrder']['remainingCancellationAmount'])
               && (float)$result['paymentOrder']['remainingCancellationAmount'] > 0.1;
    }

    /**
     * Can Refund.
     *
     * @param mixed $orderId
     * @param float|int|null $amount
     *
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function canRefund($orderId, $amount = null)
    {
        $order = $this->getOrder($orderId);

        // Fetch payment info
        try {
            $result = $this->fetchPaymentInfo($order->getPaymentOrderId());
        } catch (\Exception $e) {
            // Request failed
            return false;
        }

        return isset($result['paymentOrder']['remainingReversalAmount'])
               && (float)$result['paymentOrder']['remainingReversalAmount'] > 0.1;
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
        return $this->adapter->canUpdateOrderStatus($orderId, $status, $transactionNumber);
    }

    /**
     * Get Order Status.
     *
     * @param mixed $orderId
     *
     * @return string
     * @throws Exception
     */
    public function getOrderStatus($orderId)
    {
        return $this->adapter->getOrderStatus($orderId);
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
        $this->adapter->setPaymentOrderId($orderId, $paymentOrderId);
    }

    /**
     * Update Order Status.
     *
     * @param mixed $orderId
     * @param string $status
     * @param string|null $message
     * @param string|null $transactionNumber
     */
    public function updateOrderStatus($orderId, $status, $message = null, $transactionNumber = null)
    {
        if ($this->canUpdateOrderStatus($orderId, $status)) {
            $this->adapter->updateOrderStatus($orderId, $status, $message, $transactionNumber);
        }
    }

    /**
     * Add Order Note.
     *
     * @param mixed $orderId
     * @param string $message
     */
    public function addOrderNote($orderId, $message)
    {
        $this->adapter->addOrderNote($orderId, $message);
    }

    /**
     * Analyze the transaction and update the related order.
     *
     * @param $orderId
     * @param FinancialTransaction|array $transaction
     *
     * @throws Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function processFinancialTransaction($orderId, $transaction)
    {
        $this->log(
            LogLevel::DEBUG,
            sprintf('Process transaction: %s', json_encode($transaction, JSON_PRETTY_PRINT))
        );

        /** @var Order $order */
        $order = $this->getOrder($orderId);

        if (is_array($transaction)) {
            $transaction = new FinancialTransaction($transaction);
        } elseif (!$transaction instanceof FinancialTransaction) {
            throw new InvalidArgumentException('Invalid a transaction parameter');
        }

        // Fetch payment info
        $paymentInfo = $this->fetchPaymentInfo($order->getPaymentOrderId());
        $paymentBody = $paymentInfo['paymentOrder'];

        // Apply action
        switch ($transaction->getType()) {
            case FinancialTransactionInterface::TYPE_VERIFICATION:
                break;
            case FinancialTransactionInterface::TYPE_AUTHORIZATION:
                // Don't change the order status if it was captured before
                if ($order->getStatus() === OrderInterface::STATUS_CAPTURED) {
                    $this->addOrderNote(
                        $orderId,
                        sprintf('Payment has been authorized. Transaction: %s', $transaction->getNumber())
                    );
                } else {
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_AUTHORIZED,
                        sprintf('Payment has been authorized. Transaction: %s', $transaction->getNumber()),
                        $transaction->getNumber()
                    );
                }

                break;
            case FinancialTransactionInterface::TYPE_CAPTURE:
            case FinancialTransactionInterface::TYPE_SALE:
                // Check if the payment was captured fully
                // `remainingCaptureAmount` is missing if the payment was captured fully
                $isFullCapture = false;
                if (!isset($paymentBody['remainingCaptureAmount'])) {
                    $isFullCapture = true;
                }

                // Update order status
                if ($isFullCapture) {
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_CAPTURED,
                        sprintf(
                            'Payment has been captured. Transaction: %s. Amount: %s',
                            $transaction->getNumber(),
                            $transaction->getAmount() / 100
                        ),
                        $transaction->getNumber()
                    );
                } else {
                    $this->addOrderNote(
                        $orderId,
                        sprintf(
                            'Payment has been partially captured: Transaction: %s. Amount: %s',
                            $transaction->getNumber(),
                            $transaction->getAmount() / 100
                        )
                    );
                }

                break;
            case FinancialTransactionInterface::TYPE_CANCELLATION:
                $this->updateOrderStatus(
                    $orderId,
                    OrderInterface::STATUS_CANCELLED,
                    sprintf('Payment has been cancelled. Transaction: %s', $transaction->getNumber()),
                    $transaction->getNumber()
                );

                break;
            case FinancialTransactionInterface::TYPE_REVERSAL:
                // Create Credit Memo
                $this->createCreditMemo(
                    $orderId,
                    $transaction->getAmount() / 100,
                    $transaction->getNumber(),
                    $transaction->getDescription()
                );

                // Check if the payment was refunded fully
                // `remainingReversalAmount` is missing if the payment was refunded fully
                $isFullRefund = false;
                if (!isset($paymentBody['remainingReversalAmount'])) {
                    $isFullRefund = true;
                }

                // Update order status
                if ($isFullRefund) {
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_REFUNDED,
                        sprintf(
                            'Payment has been refunded. Transaction: %s. Amount: %s',
                            $transaction->getNumber(),
                            $transaction->getAmount() / 100
                        ),
                        $transaction->getNumber()
                    );
                } else {
                    $this->addOrderNote(
                        $orderId,
                        sprintf(
                            'Payment has been partially refunded: Transaction: %s. Amount: %s',
                            $transaction->getNumber(),
                            $transaction->getAmount() / 100
                        )
                    );
                }

                break;
            default:
                throw new Exception(sprintf('Error: Unknown type %s', $transaction->getType()));
        }
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
        // Use the reference from the adapter if exists
        return $this->adapter->generatePayeeReference($orderId);
    }

    /**
     * Create Credit Memo.
     *
     * @param mixed $orderId
     * @param float $amount
     * @param mixed $transactionId
     * @param string $description
     */
    public function createCreditMemo($orderId, $amount, $transactionId, $description)
    {
        // Check if a credit memo was created before
        if ($this->isCreditMemoExist($transactionId)) {
            return;
        }

        try {
            $this->adapter->createCreditMemo($orderId, $amount, $transactionId, $description);
        } catch (Exception $e) {
            $this->addOrderNote(
                $orderId,
                sprintf(
                    'Unable to create credit memo. %s',
                    $e->getMessage()
                )
            );
        }
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
        return $this->adapter->isCreditMemoExist($transactionId);
    }

    /**
     * Finalize Payment Order.
     *
     * @param $paymentOrderIdUrl
     *
     * @return void
     * @throws Exception
     */
    public function finalizePaymentOrder($paymentOrderIdUrl)
    {
        $response = $this->fetchPaymentInfo($paymentOrderIdUrl . '/paid');
        $transactionNumber = $response['paid']['number'];
        $orderId = $response['paid']['orderReference'];

        $transactions = $this->fetchFinancialTransactionsList($paymentOrderIdUrl);
        if (count($transactions) > 0) {
            // Process transactions
            foreach ($transactions as $transaction) {
                if ($transaction->getNumber() == $transactionNumber) {
                    $this->processFinancialTransaction($orderId, $transaction);
                }
            }

            return;
        }

        // Financial transaction list is empty, initiate workaround / failback
        $transaction = new FinancialTransaction();
        $transaction->setId($paymentOrderIdUrl . '/financialtransactions/' . uniqid('fake'))
            ->setCreated(date('Y-m-d H:i:s'))
            ->setUpdated(date('Y-m-d H:i:s'))
            ->setType($response['paid']['transactionType'])
            ->setNumber($transactionNumber)
            ->setAmount($response['paid']['amount'])
            ->setVatAmount(0)
            ->setDescription($response['paid']['id'])
            ->setPayeeReference($response['paid']['payeeReference']);

        $this->processFinancialTransaction($orderId, $transaction);
    }
}
