<?php

use SwedbankPay\Core\Api\FinancialTransaction;
use SwedbankPay\Core\Api\FinancialTransactionInterface;
use SwedbankPay\Core\Api\Problem;
use SwedbankPay\Core\Api\ProblemInterface;

class TransactionTest extends TestCase
{
    public function testGetters()
    {
        $data = '{
    "id": "/psp/creditcard/payments/d0b69e11-a6b5-4ef7-8b95-08d94fff2cbf/transactions/dd08a774-34a0-40ee-bb7b-08d94fcc1da0",
    "created": "2021-08-02T05:55:05.4437298Z",
    "updated": "2021-08-02T05:55:11.9725364Z",
    "type": "Authorization",
    "state": "Completed",
    "number": 40108027689,
    "amount": 28125,
    "vatAmount": 5625,
    "description": "Order #33787",
    "payeeReference": "33787xljqrs",
    "isOperational": false,
    "operations": []
}';
        $data = json_decode($data, true);

        $transaction = new FinancialTransaction($data);
        $this->assertInstanceOf(FinancialTransactionInterface::class, $transaction);
        $this->assertEquals(
            '/psp/creditcard/payments/d0b69e11-a6b5-4ef7-8b95-08d94fff2cbf/transactions/dd08a774-34a0-40ee-bb7b-08d94fcc1da0',
            $transaction->getId()
        );
        $this->assertEquals('2021-08-02T05:55:05.4437298Z', $transaction->getCreated());
        $this->assertEquals('2021-08-02T05:55:11.9725364Z', $transaction->getUpdated());
        $this->assertEquals(FinancialTransactionInterface::TYPE_AUTHORIZATION, $transaction->getType());
        $this->assertEquals(40108027689, $transaction->getNumber());
        $this->assertEquals(28125, $transaction->getAmount());
        $this->assertEquals(5625, $transaction->getVatAmount());
        $this->assertEquals('33787xljqrs', $transaction->getPayeeReference());
        $this->assertEquals(false, $transaction->isInitialized());
        $this->assertEquals(false, $transaction->isAwaitingActivity());
        $this->assertEquals(false, $transaction->isPending());
        $this->assertEquals(true, $transaction->isCompleted());
        $this->assertEquals(false, $transaction->isFailed());
        $this->assertEquals(false, $transaction->isInitialized());
    }

    public function testSetters()
    {
        $transaction = new FinancialTransaction([]);
        $transaction->setId(
            '/psp/creditcard/payments/d0b69e11-a6b5-4ef7-8b95-08d94fff2cbf/transactions/dd08a774-34a0-40ee-bb7b-08d94fcc1da0'
        )
            ->setCreated('2021-08-02T05:55:05.4437298Z')
            ->setUpdated('2021-08-02T05:55:11.9725364Z')
            ->setType(FinancialTransactionInterface::TYPE_AUTHORIZATION)
            ->setNumber(40108027689)
            ->setAmount(28125)
            ->setVatAmount(5625)
            ->setDescription('Order #33787')
            ->setPayeeReference('33787xljqrs');

        $this->assertInstanceOf(FinancialTransactionInterface::class, $transaction);
        $this->assertEquals(
            '/psp/creditcard/payments/d0b69e11-a6b5-4ef7-8b95-08d94fff2cbf/transactions/dd08a774-34a0-40ee-bb7b-08d94fcc1da0',
            $transaction->getId()
        );
        $this->assertEquals('2021-08-02T05:55:05.4437298Z', $transaction->getCreated());
        $this->assertEquals('2021-08-02T05:55:11.9725364Z', $transaction->getUpdated());
        $this->assertEquals(FinancialTransactionInterface::TYPE_AUTHORIZATION, $transaction->getType());
        $this->assertEquals(40108027689, $transaction->getNumber());
        $this->assertEquals(28125, $transaction->getAmount());
        $this->assertEquals(5625, $transaction->getVatAmount());
        $this->assertEquals('33787xljqrs', $transaction->getPayeeReference());
        $this->assertEquals(false, $transaction->isInitialized());
        $this->assertEquals(false, $transaction->isAwaitingActivity());
        $this->assertEquals(false, $transaction->isPending());
        $this->assertEquals(true, $transaction->isCompleted());
        $this->assertEquals(false, $transaction->isFailed());
        $this->assertEquals(false, $transaction->isInitialized());
    }

    public function testProblem()
    {
        $data = '{
    "id": "/psp/creditcard/payments/dce2cabe-8382-46b8-58ae-08d94fcbe1c7/transactions/857f0f4a-511b-42da-f730-08d94fff3046",
    "created": "2021-07-31T07:48:26.9976381Z",
    "updated": "2021-07-31T07:50:12.3446716Z",
    "type": "Authorization",
    "state": "Failed",
    "number": 40108019523,
    "amount": 28125,
    "vatAmount": 5625,
    "description": "Order #33782",
    "payeeReference": "33782xktuyo",
    "failedReason": "PspProblemException",
    "failedActivityName": "VerifyAuthentication",
    "isOperational": false,
    "problem": {
        "type": "https://api.payex.com/psp/errordetail/creditcard/systemerror",
        "title": "Error in system",
        "status": 500,
        "detail": "Unable to complete operation, error calling 3rd party",
        "problems": [{
            "name": "CommunicationError",
            "description": "Unexpected communication behavior"
        }]
    }
}';
        $data = json_decode($data, true);

        $transaction = new FinancialTransaction($data);
        $this->assertInstanceOf(FinancialTransactionInterface::class, $transaction);
        $this->assertEquals(true, $transaction->isFailed());
        $this->assertInstanceOf(Problem::class, $transaction->getProblem());
        $this->assertInstanceOf(ProblemInterface::class, $transaction->getProblem());
        $this->assertEquals('PspProblemException', $transaction->getFailedReason());
        $this->assertEquals('VerifyAuthentication', $transaction->getData('failedActivityName'));
        $this->assertEquals('(CommunicationError) Unexpected communication behavior', $transaction->getProblem()->toString());
    }
}
