<?php

use SwedbankPay\Core\Core;
use SwedbankPay\Core\OrderInterface;
use SwedbankPay\Core\Exception;

class OrderActionTest extends TestCase
{
    public function testUpdateStatus()
    {
        $result = $this->core->canUpdateOrderStatus(1, OrderInterface::STATUS_FAILED, 123);
        $this->assertEquals(true, $result);
    }

    public function testCapture()
    {
        $this->expectException(Exception::class);
        $this->core->capture(1, 125, 25);
    }

    public function testCancel()
    {
        $this->expectException(Exception::class);
        $this->core->cancel(1, 125, 25);
    }

    public function testRefund()
    {
        $this->expectException(Exception::class);
        $this->core->refund(1, 125, 25);
    }

    public function testAbort()
    {
        $this->expectException(Exception::class);
        $this->core->abort(1);
    }

    public function testProcessTransaction() {

        $data = '
{
    "id": "/psp/creditcard/payments/b8482777-37af-46b0-b362-08d9318e0436/transactions/690b850e-31a0-4e70-4ca4-08d9318d4d54",
    "created": "2021-06-20T05:10:45.6929716Z",
    "updated": "2021-06-20T05:10:49.0272698Z",
    "type": "Authorization",
    "state": "Failed",
    "number": 10426933627,
    "amount": 79600,
    "vatAmount": 8529,
    "description": "Order #292862",
    "payeeReference": "292862xblpwh",
    "failedReason": "ExternalResponseError",
    "failedActivityName": "Authorize",
    "failedErrorCode": "AUTHENTICATION_REQUIRED",
    "failedErrorDescription": "Acquirer soft-decline, 3-D Secure authentication required, response-code: O5",
    "isOperational": false,
    "problem": {
        "type": "https://api.payex.com/psp/errordetail/creditcard/authenticationrequired",
        "title": "Operation failed",
        "status": 403,
        "detail": "Unable to complete Authorization transaction, look at problem node!",
        "problems": [{
            "name": "ExternalResponse",
            "description": "AUTHENTICATION_REQUIRED-Acquirer soft-decline, 3-D Secure authentication required, response-code: O5"
        }]
    }
}
        ';

        $transaction = json_decode($data, true);
        $result = $this->core->processFinancialTransaction(1, $transaction);
        $this->assertEquals(null, $result);
    }

    public function testCreateCreditMemo()
    {
        $result = $this->core->createCreditMemo(1, 123, 123, 'Test refund');
        $this->assertEquals(null, $result);
    }
}
