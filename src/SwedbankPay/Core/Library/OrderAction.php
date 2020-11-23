<?php

namespace SwedbankPay\Core\Library;

use SwedbankPay\Core\Api\Response;
use SwedbankPay\Core\Api\Transaction;
use SwedbankPay\Core\Api\TransactionInterface;
use SwedbankPay\Core\Exception;
use SwedbankPay\Core\Log\LogLevel;
use SwedbankPay\Core\Order;
use SwedbankPay\Core\OrderInterface;
use SwedbankPay\Core\PaymentAdapterInterface;

trait OrderAction
{
    /**
     * Can Capture.
     *
     * @param mixed $orderId
     * @param float|int|null $amount
     *
     * @return bool
     */
    public function canCapture($orderId, $amount = null)
    {
        // Check the payment state online
        $order = $this->getOrder($orderId);

        // Fetch payment info
        try {
            $result = $this->fetchPaymentInfo($order->getPaymentId());
        } catch (\Exception $e) {
            // Request failed
            return false;
        }

        return isset($result['payment']['remainingCaptureAmount']) && (float)$result['payment']['remainingCaptureAmount'] > 0.1;
    }

    /**
     * Can Cancel.
     *
     * @param mixed $orderId
     * @param float|int|null $amount
     *
     * @return bool
     */
    public function canCancel($orderId, $amount = null)
    {
        // Check the payment state online
        $order = $this->getOrder($orderId);

        // Fetch payment info
        try {
            $result = $this->fetchPaymentInfo($order->getPaymentId());
        } catch (\Exception $e) {
            // Request failed
            return false;
        }

        return isset($result['payment']['remainingCancellationAmount']) && (float)$result['payment']['remainingCancellationAmount'] > 0.1;
    }

    /**
     * Can Refund.
     *
     * @param mixed $orderId
     * @param float|int|null $amount
     *
     * @return bool
     */
    public function canRefund($orderId, $amount = null)
    {
        $order = $this->getOrder($orderId);

        if (!$amount) {
            $amount = $order->getAmount();
        }

        // Should has payment id
        $paymentId = $order->getPaymentId();
        if (!$paymentId) {
            return false;
        }

        // Should be captured
        // @todo Check payment state

        // Check refund amount
        $result = $this->fetchTransactionsList($order->getPaymentId());

        $refunded = 0;
        foreach ($result['transactions']['transactionList'] as $key => $transaction) {
            if ($transaction['type'] === 'Reversal') {
                $refunded += ($transaction['amount'] / 100);
            }
        }

        $possibleToRefund = $order->getAmount() - $refunded;
        if ($amount > $possibleToRefund) {
            return false;
        }

        return true;
    }

    /**
     * Capture.
     *
     * @param mixed $orderId
     * @param mixed $amount
     * @param mixed $vatAmount
     *
     * @return Response
     * @throws Exception
     */
    public function capture($orderId, $amount = null, $vatAmount = 0)
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

        // Checkout method can use the Invoice method
        $info = $this->fetchPaymentInfo($paymentId);
        if ($info['payment']['instrument'] === 'Invoice') {
            // @todo Should we use different credentials?
            return $this->captureInvoice($orderId, $amount, $vatAmount);
        }

        $href = $info->getOperationByRel('create-capture');
        if (empty($href)) {
            throw new Exception('Capture is unavailable');
        }

        $params = [
            'transaction' => [
                'amount' => (int)bcmul(100, $amount),
                'vatAmount' => (int)bcmul(100, $vatAmount),
                'description' => sprintf('Capture for Order #%s', $order->getOrderId()),
                'payeeReference' => $this->generatePayeeReference($orderId)
            ]
        ];

        $result = $this->request('POST', $href, $params);

        // Save transaction
        $transaction = $result['capture']['transaction'];
        $this->saveTransaction($orderId, $transaction);

        switch ($transaction['state']) {
            case 'Completed':
                $this->updateOrderStatus(
                    $orderId,
                    OrderInterface::STATUS_CAPTURED,
                    sprintf('Transaction is captured. Amount: %s', $amount),
                    $transaction['id']
                );
                break;
            case 'Initialized':
                $this->updateOrderStatus(
                    $orderId,
                    OrderInterface::STATUS_AUTHORIZED,
                    sprintf('Transaction capture status: %s. Amount: %s', $transaction['state'], $amount)
                );
                break;
            case 'Failed':
                $message = isset($transaction['failedReason']) ? $transaction['failedReason'] : 'Capture is failed.';
                throw new Exception($message);
            default:
                throw new Exception('Capture is failed.');
        }

        return $result;
    }

    /**
     * Cancel.
     *
     * @param mixed $orderId
     * @param mixed $amount
     * @param mixed $vatAmount
     *
     * @return Response
     * @throws Exception
     */
    public function cancel($orderId, $amount = null, $vatAmount = 0)
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

        // Checkout method can use the Invoice method
        $info = $this->fetchPaymentInfo($paymentId);
        if ($info['payment']['instrument'] === 'Invoice') {
            // @todo Should we use different credentials?
            return $this->cancelInvoice($orderId, $amount, $vatAmount);
        }

        $href = $info->getOperationByRel('create-cancellation');
        if (empty($href)) {
            throw new Exception('Cancellation is unavailable');
        }

        $params = [
            'transaction' => [
                'description' => sprintf('Cancellation for Order #%s', $order->getOrderId()),
                'payeeReference' => $this->generatePayeeReference($orderId)
            ],
        ];
        $result = $this->request('POST', $href, $params);

        // Save transaction
        $transaction = $result['cancellation']['transaction'];
        $this->saveTransaction($orderId, $transaction);

        switch ($transaction['state']) {
            case 'Completed':
                $this->updateOrderStatus(
                    $orderId,
                    OrderInterface::STATUS_CANCELLED,
                    'Transaction is cancelled.',
                    $transaction['id']
                );
                break;
            case 'Initialized':
            case 'AwaitingActivity':
                $this->updateOrderStatus(
                    $orderId,
                    OrderInterface::STATUS_CANCELLED,
                    sprintf('Transaction cancellation status: %s.', $transaction['state'])
                );
                break;
            case 'Failed':
                $message = isset($transaction['failedReason']) ? $transaction['failedReason'] : 'Cancellation is failed.';
                throw new Exception($message);
            default:
                throw new Exception('Capture is failed.');
        }

        return $result;
    }

    /**
     * Refund.
     *
     * @param mixed $orderId
     * @param mixed $amount
     * @param mixed $vatAmount
     *
     * @return Response
     * @throws Exception
     */
    public function refund($orderId, $amount = null, $vatAmount = 0)
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

        // Checkout method can use the Invoice method
        $info = $this->fetchPaymentInfo($paymentId);
        if ($info['payment']['instrument'] === 'Invoice') {
            // @todo Should we use different credentials?
            return $this->refundInvoice($orderId, $amount, $vatAmount);
        }

        $href = $info->getOperationByRel('create-reversal');
        if (empty($href)) {
            throw new Exception('Refund is unavailable');
        }

        $params = [
            'transaction' => [
                'amount' => (int)bcmul(100, $amount),
                'vatAmount' => (int)bcmul(100, $vatAmount),
                'description' => sprintf('Refund for Order #%s.', $order->getOrderId()),
                'payeeReference' => $this->generatePayeeReference($orderId)
            ]
        ];
        $result = $this->request('POST', $href, $params);

        // Save transaction
        $transaction = $result['reversal']['transaction'];
        $this->saveTransaction($orderId, $transaction);

        switch ($transaction['state']) {
            case 'Completed':
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
                        $transaction['id']
                    );
                } else {
                    $this->addOrderNote(
                        $orderId,
                        sprintf('Refunded: %s. Transaction state: %s', $amount, $transaction['state'])
                    );
                }

                break;
            case 'Initialized':
            case 'AwaitingActivity':
                $this->addOrderNote(
                    $orderId,
                    sprintf('Refunded: %s. Transaction state: %s', $amount, $transaction['state'])
                );

                break;
            case 'Failed':
                $message = isset($transaction['failedReason']) ? $transaction['failedReason'] : 'Refund is failed.';
                throw new Exception($message);
            default:
                throw new Exception('Refund is failed.');
        }

        return $result;
    }

    /**
     * Abort Payment.
     *
     * @param mixed $orderId
     *
     * @return Response
     * @throws Exception
     */
    public function abort($orderId)
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        $paymentId = $order->getPaymentId();
        if (empty($paymentId)) {
            throw new Exception('Unable to get payment ID');
        }

        // @todo Check if order has been paid
        $href = $this->fetchPaymentInfo($paymentId)->getOperationByRel('update-payment-abort');
        if (empty($href)) {
            throw new Exception('Abort is unavailable');
        }

        $params = [
            'payment' => [
                'operation' => 'Abort',
                'abortReason' => 'CancelledByConsumer'
            ]
        ];
        $result = $this->request('PATCH', $href, $params);

        if ($result['payment']['state'] === 'Aborted') {
            $this->updateOrderStatus(
                $orderId,
                OrderInterface::STATUS_CANCELLED,
                'Payment aborted'
            );
        } else {
            throw new Exception('Aborting is failed.');
        }

        return $result;
    }

    /**
     * Check if order status can be updated.
     *
     * @param mixed $orderId
     * @param string $status
     * @param string|null $transactionId
     * @return bool
     */
    public function canUpdateOrderStatus($orderId, $status, $transactionId = null) {
        return $this->adapter->canUpdateOrderStatus($orderId, $status, $transactionId);
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
     * Set Payment Id to Order.
     *
     * @param mixed $orderId
     * @param string $paymentId
     *
     * @return void
     */
    public function setPaymentId($orderId, $paymentId)
    {
        $this->adapter->setPaymentId($orderId, $paymentId);
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
     * @param string|null $transactionId
     */
    public function updateOrderStatus($orderId, $status, $message = null, $transactionId = null)
    {
        if ($this->canUpdateOrderStatus($orderId, $status)) {
            $this->adapter->updateOrderStatus($orderId, $status, $message, $transactionId);
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
     * Get Payment Method.
     *
     * @param mixed $orderId
     *
     * @return string|null Returns method or null if not exists
     */
    public function getPaymentMethod($orderId)
    {
        return $this->adapter->getPaymentMethod($orderId);
    }

    /**
     * Fetch Transactions related to specific order, process transactions and
     * update order status.
     *
     * @param mixed $orderId
     * @param string|null $transactionId
     * @throws Exception
     */
    public function fetchTransactionsAndUpdateOrder($orderId, $transactionId = null)
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        $paymentId = $order->getPaymentId();
        if (empty($paymentId)) {
            throw new Exception('Unable to get payment ID');
        }

        // Fetch transactions list
        $transactions = $this->fetchTransactionsList($paymentId);
        $this->saveTransactions($orderId, $transactions);

        // Extract transaction from list
        if ($transactionId) {
            $transaction = $this->findTransaction('number', $transactionId);
            if ( ! $transaction ) {
                throw new Exception(sprintf('Failed to fetch transaction number #%s', $transactionId));
            }

            $transactions = [ $transaction ];
        }

        // Process transactions
        foreach ($transactions as $transaction) {
            try {
                $this->processTransaction($orderId, $transaction);
            } catch (Exception $e) {
                $this->log(LogLevel::ERROR, sprintf('%s::%s: API Exception: %s', __CLASS__, __METHOD__, $e->getMessage()));

                continue;
            }
        }
    }

    /**
     * Analyze the transaction and update the related order.
     *
     * @param $orderId
     * @param Transaction|array $transaction
     *
     * @throws Exception
     */
    public function processTransaction($orderId, $transaction)
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        if (is_array($transaction)) {
            $transaction = new Transaction($transaction);
        } elseif (!$transaction instanceof Transaction) {
            throw new \InvalidArgumentException('Invalid a transaction parameter');
        }

        // Apply action
        switch ($transaction->getType()) {
            case TransactionInterface::TYPE_VERIFICATION:
                if ($transaction->isFailed()) {
                    $this->addOrderNote($orderId,
                        sprintf('Verification has been failed. Reason: %s.',
                            $transaction->getFailedDetails()
                        )
                    );

                    break;
                }

                if ($transaction->isPending()) {
                    $this->addOrderNote(
                        $orderId,
                        'Verification transaction is pending.'
                    );

                    break;
                }

                // Save Payment Token
                $verifications = $this->fetchVerificationList($order->getPaymentId());
                foreach ($verifications as $verification) {
                    if ($verification->getPaymentToken() || $verification->getRecurrenceToken()) {
                        // Add payment token
                        $this->adapter->savePaymentToken(
                            $order->getCustomerId(),
                            $verification->getPaymentToken(),
                            $verification->getRecurrenceToken(),
                            $verification->getCardBrand(),
                            $verification->getMaskedPan(),
                            $verification->getExpireDate(),
                            $order->getOrderId()
                        );

                        $this->addOrderNote(
                            $orderId,
                            sprintf('Card %s has been saved.', $verification->getMaskedPan())
                        );

                        // Use the first item only
                        break;
                    }
                }

                break;
            case TransactionInterface::TYPE_AUTHORIZATION:
                if ($transaction->isFailed()) {
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_FAILED,
                        sprintf('Authorization has been failed. Reason: %s.', $transaction->getFailedDetails()),
                        $transaction->getId()
                    );

                    break;
                }

                if ($transaction->isPending()) {
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_AUTHORIZED,
                        'Authorization is pending.',
                        $transaction->getId()
                    );

                    break;
                }

                $this->updateOrderStatus(
                    $orderId,
                    OrderInterface::STATUS_AUTHORIZED,
                    'Payment has been authorized.',
                    $transaction->getId()
                );

                // Save Payment Token
                if ($order->needsSaveToken()) {
                    $authorizations = $this->fetchAuthorizationList($order->getPaymentId());
                    foreach ($authorizations as $authorization) {
                        if ($authorization->getPaymentToken() || $authorization->getRecurrenceToken()) {
                            // Add payment token
                            $this->adapter->savePaymentToken(
                                $order->getCustomerId(),
                                $authorization->getPaymentToken(),
                                $authorization->getRecurrenceToken(),
                                $authorization->getCardBrand(),
                                $authorization->getMaskedPan(),
                                $authorization->getExpireDate(),
                                $order->getOrderId()
                            );

                            $this->addOrderNote(
                                $orderId,
                                sprintf('Card %s has been saved.', $authorization->getMaskedPan())
                            );

                            // Use the first item only
                            break;
                        }
                    }
                }

                break;
            case TransactionInterface::TYPE_CAPTURE:
            case TransactionInterface::TYPE_SALE:
                if ($transaction->isFailed()) {
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_FAILED,
                        sprintf('Capture has been failed. Reason: %s.', $transaction->getFailedDetails()),
                        $transaction->getId()
                    );

                    break;
                }

                if ($transaction->isPending()) {
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_AUTHORIZED,
                        'Capture is pending.',
                        $transaction->getId()
                    );

                    break;
                }

                $this->updateOrderStatus(
                    $orderId,
                    OrderInterface::STATUS_CAPTURED,
                    'Payment has been captured.',
                    $transaction->getId()
                );
                break;
            case TransactionInterface::TYPE_CANCELLATION:
                if ($transaction->isFailed()) {
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_FAILED,
                        sprintf('Cancellation has been failed. Reason: %s.', $transaction->getFailedDetails()),
                        $transaction->getId()
                    );

                    break;
                }

                if ($transaction->isPending()) {
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_CANCELLED,
                        'Cancellation is pending.',
                        $transaction->getId()
                    );

                    break;
                }

                $this->updateOrderStatus(
                    $orderId,
                    OrderInterface::STATUS_CAPTURED,
                    'Payment has been cancelled.',
                    $transaction->getId()
                );
                break;
            case TransactionInterface::TYPE_REVERSAL:
                if ($transaction->isFailed()) {
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_FAILED,
                        sprintf('Reversal has been failed. Reason: %s.', $transaction->getFailedDetails()),
                        $transaction->getId()
                    );

                    break;
                }

                if ($transaction->isPending()) {
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_REFUNDED,
                        'Reversal is pending.',
                        $transaction->getId()
                    );

                    break;
                }

                // Check if the payment was refund fully
                $isFullRefund = false;
                $info = $this->fetchPaymentInfo($order->getPaymentId());
                if (!isset($info['payment']['remainingReversalAmount'])) {
                    // Failback if `remainingReversalAmount` is missing
                    if (bccomp($order->getAmount(), $transaction->getAmount() / 100, 2) === 0) {
                        $isFullRefund = true;
                    }
                } elseif ((int) $info['payment']['remainingReversalAmount'] === 0) {
                    $isFullRefund = true;
                }

                // Update order status
                if ($isFullRefund) {
                    $this->updateOrderStatus(
                        $orderId,
                        OrderInterface::STATUS_REFUNDED,
                        'Payment has been refunded.',
                        $transaction->getId()
                    );
                } else {
                    $this->addOrderNote(
                        $orderId,
                        sprintf('Refunded: %s. ', $transaction->getAmount() / 100)
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
        if (method_exists($this->adapter, 'generatePayeeReference')) {
            return $this->adapter->generatePayeeReference($orderId);
        }

        $arr = range('a', 'z');
        shuffle($arr);

        return $orderId . 'x' . substr(implode('', $arr), 0, 5);
    }

    /**
     * @param mixed $orderId
     * @return void
     */
    public function updateTransactionsOnFailure($orderId)
    {
        /** @var OrderInterface $order */
        $order = $this->getOrder($orderId);

        if (OrderInterface::STATUS_FAILED === $order->getStatus()) {
            // Wait for "Completed" transaction state
            // Current payment can be changed
            $attempts = 0;
            while (true) {
                sleep(1);
                $attempts++;
                if ($attempts > 60) {
                    break;
                }

                // Get Payment ID
                if ($order->getPaymentMethod() === PaymentAdapterInterface::METHOD_CHECKOUT) {
                    $paymentId = $this->getPaymentIdByPaymentOrder($order->getPaymentOrderId());
                } else {
                    $paymentId = $order->getPaymentId();
                }

                $transactions = $this->fetchTransactionsList($paymentId);
                foreach ($transactions as $transaction) {
                    /** @var Transaction $transaction */
                    if (in_array($transaction->getType(), [
                        TransactionInterface::TYPE_AUTHORIZATION,
                        TransactionInterface::TYPE_SALE
                    ])) {
                        switch ($transaction->getState()) {
                            case TransactionInterface::STATE_COMPLETED:
                                // Transaction has found: update the order state
                                if ($order->getPaymentMethod() === PaymentAdapterInterface::METHOD_CHECKOUT) {
                                    $this->setPaymentId($orderId, $paymentId);
                                }

                                $this->fetchTransactionsAndUpdateOrder($orderId, $transaction->getId());
                                break 3;
                            case TransactionInterface::STATE_FAILED:
                                // Log failed transaction
                                $this->log(
                                    LogLevel::WARNING,
                                    sprintf('Failed transaction: (%s), (%s), (%s), (%s)',
                                        $orderId,
                                        $paymentId,
                                        $transaction->getId(),
                                        var_export($transaction->getData(), true)
                                    )
                                );

                                break;
                        }
                    }
                }
            }
        }
    }
}