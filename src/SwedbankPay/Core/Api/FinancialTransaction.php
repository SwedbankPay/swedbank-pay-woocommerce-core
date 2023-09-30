<?php

namespace SwedbankPay\Core\Api;

use SwedbankPay\Core\Data;

/**
 * Class FinancialTransaction
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
 * @method string getDescription()
 * @method $this setDescription($value)
 */
class FinancialTransaction extends Data implements FinancialTransactionInterface
{
    /**
     * Transaction constructor.
     *
     * @param array $data
     */
    public function __construct(array $data = [])
    {
        $this->setData($data);
    }

    /**
     * Set VAT amount.
     *
     * @param mixed $vatAmount
     *
     * @return $this
     */
    public function setVatAmount($vatAmount)
    {
        return $this->setData(self::VAT_AMOUNT, $vatAmount);
    }

    /**
     * Get VAT amount.
     *
     * @return string
     */
    public function getVatAmount()
    {
        return $this->getData(self::VAT_AMOUNT);
    }

    /**
     * Set Payee Reference.
     *
     * @param string $payeeReference
     *
     * @return $this
     */
    public function setPayeeReference($payeeReference)
    {
        return $this->setData(self::PAYEE_REFERENCE, $payeeReference);
    }

    /**
     * Get Payee Reference.
     *
     * @return string
     */
    public function getPayeeReference()
    {
        return $this->getData(self::PAYEE_REFERENCE);
    }

    /**
     * Set Receipt Reference.
     * A unique reference to the transaction, provided by the merchant.
     * Can be used as an invoice or receipt number as a supplement to `payeeReference`.
     *
     * @param string $reference
     *
     * @return $this
     */
    public function setReceiptReference($reference)
    {
        return $this->setData(self::RECEIPT_REFERENCE, $reference);
    }

    /**
     * Get Receipt Reference.
     *
     * @return string
     */
    public function getReceiptReference()
    {
        return $this->getData(self::RECEIPT_REFERENCE);
    }

    /**
     * @return bool
     */
    public function isAuthorization()
    {
        return self::TYPE_AUTHORIZATION === $this->getType();
    }

    /**
     * @return bool
     */
    public function isVerification()
    {
        return self::TYPE_VERIFICATION === $this->getType();
    }

    /**
     * @return bool
     */
    public function isCapture()
    {
        return in_array($this->getType(), [self::TYPE_CAPTURE, self::TYPE_SALE]);
    }
}
