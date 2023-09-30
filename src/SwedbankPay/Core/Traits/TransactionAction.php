<?php

namespace SwedbankPay\Core\Traits;

use SwedbankPay\Core\Api\FinancialTransaction;

trait TransactionAction
{
    /**
     * Save Transaction Data.
     *
     * @param mixed $orderId
     * @param array $transactionData
     */
    public function saveFinancialTransaction($orderId, $transactionData = [])
    {
        if (is_object($transactionData) && method_exists($transactionData, 'toArray')) {
            $transactionData = $transactionData->toArray();
        }

        $this->adapter->saveFinancialTransaction($orderId, $transactionData);
    }

    /**
     * Save Transactions Data.
     *
     * @param mixed $orderId
     * @param array $transactions
     */
    public function saveFinancialTransactions($orderId, array $transactions)
    {
        foreach ($transactions as $transactionData) {
            if (is_object($transactionData) && method_exists($transactionData, 'toArray')) {
                $transactionData = $transactionData->toArray();
            }

            $this->adapter->saveFinancialTransaction($orderId, $transactionData);
        }
    }

    /**
     * Find Transaction.
     *
     * @param string $field
     * @param mixed $value
     *
     * @return bool|FinancialTransaction
     */
    public function findFinancialTransaction($field, $value)
    {
        $transaction = $this->adapter->findFinancialTransaction($field, $value);

        if (!$transaction) {
            return false;
        }

        return new FinancialTransaction($transaction);
    }
}
