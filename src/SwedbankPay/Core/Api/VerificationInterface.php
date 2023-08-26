<?php

namespace SwedbankPay\Core\Api;

interface VerificationInterface
{
    const PAYMENT_TOKEN = 'paymentToken';
    const RECURRENCE_TOKEN = 'recurrenceToken';
    const CARD_BRAND = 'cardBrand';
    const MASKED_PAN = 'maskedPan';
    const EXPIRY_DATE = 'expiryDate';
    const TRANSACTION = 'transaction';
    const DIRECT = 'direct';
    const CARD_TYPE = 'cardType';
    const PAN_TOKEN = 'panToken';
    const PAN_ENROLLED = 'panEnrolled';
    const AUTHENTICATION_STATUS = 'authenticationStatus';
    const ISSUER_AUTH_CODE = 'issuerAuthorizationApprovalCode';
    const ACQUIRER_TRANSACTION_TYPE = 'acquirerTransactionType';
    const ACQUIRER_STAN = 'acquirerStan';
    const ACQUIRER_TERMINAL_ID = 'acquirerTerminalId';
    const ACQUIRER_TRANSACTION_TIME = 'acquirerTransactionTime';
    const TRANSACTION_INITIATOR = 'transactionInitiator';

    /**
     * Get Payment Token.
     *
     * @return string
     */
    public function getPaymentToken();

    /**
     * Get Recurrence Token.
     *
     * @return array
     */
    public function getRecurrenceToken();

    /**
     * Get Masked Pan.
     *
     * @return array
     */
    public function getMaskedPan();

    /**
     * Get Card Brand.
     *
     * @return array
     */
    public function getCardBrand();

    /**
     * Get Expire Date.
     *
     * @return array
     */
    public function getExpireDate();

    /**
     * Get Direct.
     *
     * @return bool|null
     */
    public function getDirect();

    /**
     * Get Card Type: Debit or Credit.
     *
     * @return string
     */
    public function getCardType();

    /**
     * Get Pan Token.
     *
     * @return string
     */
    public function getPanToken();

    /**
     * Get Is Pan Enrolled.
     *
     * @return bool
     */
    public function isPanEnrolled();

    /**
     * Get Authentication Status.
     *
     * @return string
     */
    public function getAuthenticationStatus();

    /**
     * Get Issuer Auth Code.
     *
     * @return string
     */
    public function getIssuerAuthCode();

    /**
     * Get Acquirer Transaction Type.
     *
     * @return string
     */
    public function getAcquirerTransactionType();

    /**
     * Get Acquirer Stan.
     *
     * @return string
     */
    public function getAcquirerStan();

    /**
     * Get Acquirer Terminal Id.
     *
     * @return string
     */
    public function getAcquirerTerminalId();

    /**
     * Get Acquirer Transaction Time.
     *
     * @return string
     */
    public function getAcquirerTransactionTime();

    /**
     * Get Transaction Initiator.
     *
     * @return string
     */
    public function getTransactionInitiator();

    /**
     * Get Transaction data.
     *
     * @return FinancialTransaction
     */
    public function getTransaction();
}
