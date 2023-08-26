<?php

namespace SwedbankPay\Core\Api;

/**
 * Interface FinancialTransactionInterface
 * @package SwedbankPay\Core\Api
 * @method string getId()
 * @method $this setId($value)
 * @method string getCreated()
 * @method $this setCreated($value)
 * @method string getUpdated()
 * @method $this setUpdated($value)
 * @method string getType()
 * @method $this setType($value)
 * @method int getNumber()
 * @method $this setNumber($value)
 * @method int getAmount()
 * @method $this setAmount($value)
 * @method $this setVatAmount($value)
 * @method string getDescription()
 * @method $this setDescription($value)
 * @method $this setPayeeReference($value)
 */
interface FinancialTransactionInterface
{
    const TYPE_VERIFICATION = 'Verification';
    const TYPE_AUTHORIZATION = 'Authorization';
    const TYPE_CAPTURE = 'Capture';
    const TYPE_SALE = 'Sale';
    const TYPE_CANCELLATION = 'Cancellation';
    const TYPE_REVERSAL = 'Reversal';

    const VAT_AMOUNT = 'vatAmount';
    const PAYEE_REFERENCE = 'payeeReference';
    const RECEIPT_REFERENCE  = 'receiptReference';

    /**
     * Get VAT amount.
     *
     * @return string
     */
    public function getVatAmount();

    /**
     * Get Payee Reference.
     *
     * @return string
     */
    public function getPayeeReference();
}
