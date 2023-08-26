<?php

namespace SwedbankPay\Core\Api;

use SwedbankPay\Core\Data;

/**
 * Class Verification
 * @package SwedbankPay\Core\Api
 * @todo Add more methods
 */
class Verification extends Data implements VerificationInterface
{
    /**
     * Verification constructor.
     *
     * @param array $data
     */
    public function __construct(array $data = [])
    {
        $this->setData($data);
    }

    /**
     * Get Payment Token.
     *
     * @return string
     */
    public function getPaymentToken()
    {
        return $this->getData(self::PAYMENT_TOKEN);
    }

    /**
     * Get Recurrence Token.
     *
     * @return string
     */
    public function getRecurrenceToken()
    {
        return $this->getData(self::RECURRENCE_TOKEN);
    }

    /**
     * Get Masked Pan.
     *
     * @return string
     */
    public function getMaskedPan()
    {
        return $this->getData(self::MASKED_PAN);
    }

    /**
     * Get Card Brand.
     *
     * @return string
     */
    public function getCardBrand()
    {
        return $this->getData(self::CARD_BRAND);
    }

    /**
     * Get Expire Date.
     *
     * @return string
     */
    public function getExpireDate()
    {
        return $this->getData(self::EXPIRY_DATE);
    }

    /**
     * Get Direct.
     *
     * @return bool|null
     */
    public function getDirect()
    {
        return $this->getData(self::DIRECT);
    }

    /**
     * Get Card Type: Debit or Credit.
     *
     * @return string
     */
    public function getCardType()
    {
        return $this->getData(self::CARD_TYPE);
    }

    /**
     * Get Pan Token.
     *
     * @return string
     */
    public function getPanToken()
    {
        return $this->getData(self::PAN_TOKEN);
    }

    /**
     * Get Is Pan Enrolled.
     *
     * @return bool
     */
    public function isPanEnrolled()
    {
        return $this->getData(self::PAN_ENROLLED);
    }

    /**
     * Get Authentication Status.
     *
     * @return string
     */
    public function getAuthenticationStatus()
    {
        return $this->getData(self::AUTHENTICATION_STATUS);
    }

    /**
     * Get Issuer Auth Code.
     *
     * @return string
     */
    public function getIssuerAuthCode()
    {
        return $this->getData(self::ISSUER_AUTH_CODE);
    }

    /**
     * Get Acquirer Transaction Type.
     *
     * @return string
     */
    public function getAcquirerTransactionType()
    {
        return $this->getData(self::ACQUIRER_TRANSACTION_TYPE);
    }

    /**
     * Get Acquirer Stan.
     *
     * @return string
     */
    public function getAcquirerStan()
    {
        return $this->getData(self::ACQUIRER_STAN);
    }

    /**
     * Get Acquirer Terminal Id.
     *
     * @return string
     */
    public function getAcquirerTerminalId()
    {
        return $this->getData(self::ACQUIRER_TERMINAL_ID);
    }

    /**
     * Get Acquirer Transaction Time.
     *
     * @return string
     */
    public function getAcquirerTransactionTime()
    {
        return $this->getData(self::ACQUIRER_TRANSACTION_TIME);
    }

    /**
     * Get Transaction Initiator.
     *
     * @return string
     */
    public function getTransactionInitiator()
    {
        return $this->getData(self::TRANSACTION_INITIATOR);
    }

    /**
     * Get Transaction data.
     *
     * @return FinancialTransaction
     */
    public function getTransaction()
    {
        return new FinancialTransaction($this->getData(self::TRANSACTION));
    }
}
