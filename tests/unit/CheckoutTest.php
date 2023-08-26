<?php

use SwedbankPay\Core\Api\Response;

class CheckoutTest extends TestCase
{
    public function testInitiatePaymentOrderPurchase()
    {
        // Test with mock
        $response = <<<END
{
    "paymentOrder": {
        "id": "/psp/paymentorders/b77594ee-9b9e-47fa-d13d-08db0362a6d0",
        "created": "2023-02-01T17:31:53.7200918Z",
        "updated": "2023-02-01T17:31:53.8703409Z",
        "operation": "Purchase",
        "state": "Ready",
        "currency": "SEK",
        "amount": 61600,
        "vatAmount": 12320,
        "orderItems": {
            "id": "/psp/paymentorders/b77594ee-9b9e-47fa-d13d-08db0362a6d0/orderitems"
        },
        "description": "Order #10926",
        "initiatingSystemUserAgent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36",
        "userAgent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36",
        "language": "sv-SE",
        "availableInstruments": ["CreditCard", "Invoice-PayExFinancingSe", "Swish", "CreditAccount-CreditAccountSe"],
        "integration": "",
        "urls": {
            "id": "/psp/paymentorders/b77594ee-9b9e-47fa-d13d-08db0362a6d0/urls"
        },
        "payeeInfo": {
            "id": "/psp/paymentorders/b77594ee-9b9e-47fa-d13d-08db0362a6d0/payeeInfo"
        },
        "metadata": {
            "id": "/psp/paymentorders/b77594ee-9b9e-47fa-d13d-08db0362a6d0/metadata"
        },
        "payer": {
            "id": "/psp/paymentorders/b77594ee-9b9e-47fa-d13d-08db0362a6d0/payers"
        },
        "payments": {
            "id": "/psp/paymentorders/b77594ee-9b9e-47fa-d13d-08db0362a6d0/payments"
        },
        "currentPayment": {
            "id": "/psp/paymentorders/b77594ee-9b9e-47fa-d13d-08db0362a6d0/currentpayment"
        },
        "items": [{
            "creditCard": {
                "cardBrands": ["MasterCard", "Visa"]
            }
        }]
    },
    "operations": [{
        "method": "PATCH",
        "href": "https://api.externalintegration.payex.com/psp/paymentorders/b77594ee-9b9e-47fa-d13d-08db0362a6d0",
        "rel": "update-paymentorder-updateorder",
        "contentType": "application/json"
    }, {
        "method": "PATCH",
        "href": "https://api.externalintegration.payex.com/psp/paymentorders/b77594ee-9b9e-47fa-d13d-08db0362a6d0",
        "rel": "update-paymentorder-abort",
        "contentType": "application/json"
    }, {
        "method": "GET",
        "href": "https://ecom.externalintegration.payex.com/paymentmenu/13f388fd92efa8d726c6be2be2cecbf3b10b8fbe5be363e9726d9b9b5fa43f22",
        "rel": "redirect-paymentorder",
        "contentType": "text/html"
    }, {
        "method": "GET",
        "href": "https://ecom.externalintegration.payex.com/paymentmenu/core/client/paymentmenu/13f388fd92efa8d726c6be2be2cecbf3b10b8fbe5be363e9726d9b9b5fa43f22?culture=sv-SE",
        "rel": "view-paymentorder",
        "contentType": "application/javascript"
    }]
}
END;

        $this->clientMock->expects($this->once())
            ->method('getResponseBody')
            ->willReturn($response);

        $this->clientMock->expects($this->any())
            ->method('getResponseCode')
            ->willReturn(200);

        $result = $this->coreMock->initiatePaymentOrderPurchase(1, null, false);
        $this->assertInstanceOf(Response::class, $result);

        // Test initialization
        $result = $this->core->initiatePaymentOrderPurchase(1, null);
        $this->assertInstanceOf(Response::class, $result);
        $this->assertArrayHasKey('payment_order', $result);
        $this->assertArrayHasKey('operations', $result);
        $this->assertIsArray($result['payment_order']);
        $this->assertArrayHasKey('id', $result['payment_order']);
        $this->assertArrayHasKey('operation', $result['payment_order']);
        $this->assertArrayHasKey('state', $result['payment_order']);
        $this->assertArrayHasKey('items', $result['payment_order']);
        $this->assertIsString($result->getOperationByRel('update-paymentorder-updateorder'));
        $this->assertIsString($result->getOperationByRel('update-paymentorder-abort'));
        $this->assertIsString($result->getOperationByRel('redirect-paymentorder'));
        $this->assertIsString($result->getOperationByRel('view-paymentorder'));

        return $result;
    }

    /**
     * @depends CheckoutTest::testInitiatePaymentOrderPurchase
     * @param Response $response
     */
    public function testUpdatePaymentOrder(Response $response)
    {
        $result = $this->core->updatePaymentOrder(
            $response->getOperationByRel('update-paymentorder-updateorder'),
            1
        );

        $this->assertIsString($result->getOperationByRel('update-paymentorder-updateorder'));
        $this->assertIsString($result->getOperationByRel('update-paymentorder-abort'));
        $this->assertIsString($result->getOperationByRel('redirect-paymentorder'));
        $this->assertIsString($result->getOperationByRel('view-paymentorder'));
    }

    /**
     * @depends CheckoutTest::testInitiatePaymentOrderPurchase
     */
    public function testAbort(Response $response)
    {
        // Test abort
        $result = $this->core->request(
            'PATCH',
            $response->getOperationByRel('update-paymentorder-abort'),
            [
                'paymentorder' => [
                    'operation' => 'Abort',
                    'abortReason' => 'CancelledByConsumer'
                ]
            ]
        );

        $this->assertInstanceOf(Response::class, $result);
        $this->assertArrayHasKey('state', $result['paymentOrder']);
        $this->assertEquals('Aborted', $result['paymentOrder']['state']);
        $this->assertEquals('CancelledByConsumer', $result['paymentOrder']['stateReason']);
    }

    public function testInitiatePaymentOrderVerify()
    {
        $response = <<<RESPONSE
{
    "paymentOrder": {
        "id": "/psp/paymentorders/1893556a-aac8-498b-d008-08db05db225f",
        "created": "2023-02-06T05:41:00.7965012Z",
        "updated": "2023-02-06T05:41:00.8410496Z",
        "operation": "Verify",
        "state": "Ready",
        "currency": "EUR",
        "amount": 0,
        "vatAmount": 0,
        "description": "Verification of Credit Card",
        "initiatingSystemUserAgent": "Swedbank Pay PHP SDK/5.4.1 PHP/7.3.33 Mac OS X swedbankpay-woocommerce-checkout/6.4.0Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36",
        "userAgent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36",
        "language": "en-US",
        "availableInstruments": ["CreditCard"],
        "integration": "",
        "urls": {
            "id": "/psp/paymentorders/1893556a-aac8-498b-d008-08db05db225f/urls"
        },
        "payeeInfo": {
            "id": "/psp/paymentorders/1893556a-aac8-498b-d008-08db05db225f/payeeInfo"
        },
        "metadata": {
            "id": "/psp/paymentorders/1893556a-aac8-498b-d008-08db05db225f/metadata"
        },
        "payer": {
            "id": "/psp/paymentorders/1893556a-aac8-498b-d008-08db05db225f/payers"
        },
        "payments": {
            "id": "/psp/paymentorders/1893556a-aac8-498b-d008-08db05db225f/payments"
        },
        "currentPayment": {
            "id": "/psp/paymentorders/1893556a-aac8-498b-d008-08db05db225f/currentpayment"
        },
        "items": [{
            "creditCard": {
                "cardBrands": ["MasterCard", "Visa", "Amex", "Maestro"]
            }
        }]
    },
    "operations": [{
        "method": "PATCH",
        "href": "https://api.externalintegration.payex.com/psp/paymentorders/1893556a-aac8-498b-d008-08db05db225f",
        "rel": "update-paymentorder-updateorder",
        "contentType": "application/json"
    }, {
        "method": "PATCH",
        "href": "https://api.externalintegration.payex.com/psp/paymentorders/1893556a-aac8-498b-d008-08db05db225f",
        "rel": "update-paymentorder-abort",
        "contentType": "application/json"
    }, {
        "method": "GET",
        "href": "https://ecom.externalintegration.payex.com/paymentmenu/e9ade2b21f467c46d070dd4ff371912c222719d37450125519f51dc50a7272f9",
        "rel": "redirect-paymentorder",
        "contentType": "text/html"
    }, {
        "method": "GET",
        "href": "https://ecom.externalintegration.payex.com/paymentmenu/core/client/paymentmenu/e9ade2b21f467c46d070dd4ff371912c222719d37450125519f51dc50a7272f9?culture=en-US",
        "rel": "view-paymentorder",
        "contentType": "application/javascript"
    }]
}
RESPONSE;

        $this->clientMock->expects($this->once())
                         ->method('getResponseBody')
                         ->willReturn($response);

        $this->clientMock->expects($this->any())
                         ->method('getResponseCode')
                         ->willReturn(201);

        $result = $this->coreMock->initiatePaymentOrderVerify(1);

        $this->assertInstanceOf(Response::class, $result);
    }

    public function testInitiateCreditCardUnscheduledPurchase()
    {
        $response = <<<RESPONSE
{
    "paymentOrder": {
        "id": "/psp/paymentorders/eca83607-0e04-415d-c913-08db05db225f",
        "created": "2023-02-05T05:40:10.1930162Z",
        "updated": "2023-02-05T05:40:12.0663127Z",
        "operation": "UnscheduledPurchase",
        "state": "Ready",
        "currency": "EUR",
        "amount": 28125,
        "vatAmount": 5625,
        "remainingCaptureAmount": 28125,
        "remainingCancellationAmount": 28125,
        "orderItems": {
            "id": "/psp/paymentorders/eca83607-0e04-415d-c913-08db05db225f/orderitems"
        },
        "description": "Order #33995",
        "initiatingSystemUserAgent": "Swedbank Pay PHP SDK/5.4.1 PHP/7.3.33 Mac OS X swedbankpay-woocommerce-checkout/6.4.0Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36",
        "userAgent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36",
        "language": "en-US",
        "nonPaymentToken": "ed4683a8-6d2a-4a14-b065-746a41316b8f",
        "integration": "Direct",
        "urls": {
            "id": "/psp/paymentorders/eca83607-0e04-415d-c913-08db05db225f/urls"
        },
        "payeeInfo": {
            "id": "/psp/paymentorders/eca83607-0e04-415d-c913-08db05db225f/payeeInfo"
        },
        "metadata": {
            "id": "/psp/paymentorders/eca83607-0e04-415d-c913-08db05db225f/metadata"
        },
        "payments": {
            "id": "/psp/paymentorders/eca83607-0e04-415d-c913-08db05db225f/payments"
        },
        "currentPayment": {
            "id": "/psp/paymentorders/eca83607-0e04-415d-c913-08db05db225f/currentpayment"
        },
        "items": [{
            "creditCard": {
                "cardBrands": ["Visa", "MasterCard", "Amex", "Maestro"]
            }
        }]
    },
    "operations": [{
        "method": "POST",
        "href": "https://api.externalintegration.payex.com/psp/paymentorders/eca83607-0e04-415d-c913-08db05db225f/cancellations",
        "rel": "create-paymentorder-cancel",
        "contentType": "application/json"
    }, {
        "method": "POST",
        "href": "https://api.externalintegration.payex.com/psp/paymentorders/eca83607-0e04-415d-c913-08db05db225f/captures",
        "rel": "create-paymentorder-capture",
        "contentType": "application/json"
    }, {
        "method": "POST",
        "href": "https://api.externalintegration.payex.com/psp/creditcard/payments/ac44c139-99d7-4f02-a10f-08db0362a155/cancellations",
        "rel": "create-cancellation",
        "contentType": "application/json"
    }, {
        "method": "POST",
        "href": "https://api.externalintegration.payex.com/psp/creditcard/payments/ac44c139-99d7-4f02-a10f-08db0362a155/captures",
        "rel": "create-capture",
        "contentType": "application/json"
    }, {
        "method": "GET",
        "href": "https://api.externalintegration.payex.com/psp/paymentorders/eca83607-0e04-415d-c913-08db05db225f/paid",
        "rel": "paid-paymentorder",
        "contentType": "application/json"
    }]
}
RESPONSE;

        $this->clientMock->expects($this->once())
                         ->method('getResponseBody')
                         ->willReturn($response);

        $this->clientMock->expects($this->any())
                         ->method('getResponseCode')
                         ->willReturn(201);

        $result = $this->coreMock->initiatePaymentOrderUnscheduledPurchase(33995, 'c58a9aad-4b82-43d1-ba3a-fca014747e72');

        $this->assertInstanceOf(Response::class, $result);
    }

    public function testCaptureCheckout()
    {
        $this->clientMock->expects($this->at(5))
            ->method('getResponseBody')
            ->willReturn($this->paymentOrderResponse);

         $response = <<<RESPONSE
{
    "payment": "/psp/creditcard/payments/ac44c139-99d7-4f02-a10f-08db0362a155",
    "capture": {
        "id": "/psp/creditcard/payments/ac44c139-99d7-4f02-a10f-08db0362a155/captures/40e97bac-9387-42a9-b87e-08db03630610",
        "transaction": {
            "id": "/psp/creditcard/payments/ac44c139-99d7-4f02-a10f-08db0362a155/transactions/40e97bac-9387-42a9-b87e-08db03630610",
            "created": "2023-02-06T06:07:55.8622161Z",
            "updated": "2023-02-06T06:07:56.4637213Z",
            "type": "Capture",
            "state": "Completed",
            "number": 40120453342,
            "amount": 28125,
            "vatAmount": 5625,
            "description": "Capture for Order #33995",
            "payeeReference": "33995xkzqci",
            "isOperational": false,
            "operations": []
        }
    }
}
RESPONSE;

        $this->clientMock->expects($this->at(7))
            ->method('getResponseBody')
            ->willReturn($response);

        $this->clientMock->expects($this->at(10))
            ->method('getResponseBody')
            ->willReturn($response);

        $this->clientMock->expects($this->any())
            ->method('getResponseCode')
            ->willReturn(201);

        $result = $this->coreMock->captureCheckout(
            33995,
            [
                [
                    'amount' => 12500,
                    'class' => 'ProductGroup1',
                    'description' => 'Test',
                    'imageUrl' => 'http:\/\/example.com\/wooshop3\/wp-content\/uploads\/woocommerce-placeholder.png',
                    'itemUrl' => 'http:\/\/example.com\/wooshop3\/product\/test\/',
                    'name' => 'Test',
                    'quantity' => 1,
                    'quantityUnit' => 'pcs',
                    'reference' => 'TEST',
                    'restrictedToInstruments' => null,
                    'type' => 'PRODUCT',
                    'unitPrice' => 12500,
                    'vatAmount' => 2500,
                    'vatPercent' => 2500
                ],
                [
                    'amount' => 15625,
                    'class' => 'ProductGroup1',
                    'description' => null,
                    'imageUrl' => null,
                    'itemUrl' => null,
                    'name' => 'Flat rate',
                    'quantity' => 1,
                    'quantityUnit' => 'pcs',
                    'reference' => 'shipping',
                    'restrictedToInstruments' => null,
                    'type' => 'SHIPPING_FEE',
                    'unitPrice' => 15625,
                    'vatAmount' => 3125,
                    'vatPercent' => 2500
                ]
            ]
        );

        $this->assertInstanceOf(Response::class, $result);
    }

    public function testRefundCheckout()
    {
        $this->clientMock->expects($this->at(5))
            ->method('getResponseBody')
            ->willReturn($this->paymentOrderResponse);

        $response = <<<RESPONSE
{
    "payment": "/psp/creditcard/payments/ac44c139-99d7-4f02-a10f-08db0362a155",
    "reversal": {
        "id": "/psp/creditcard/payments/ac44c139-99d7-4f02-a10f-08db0362a155/reversals/53b5df93-32a9-4ee4-b8b2-08db03630610",
        "transaction": {
            "id": "/psp/creditcard/payments/ac44c139-99d7-4f02-a10f-08db0362a155/transactions/53b5df93-32a9-4ee4-b8b2-08db03630610",
            "created": "2023-02-06T06:14:16.484325Z",
            "updated": "2023-02-06T06:14:17.4214253Z",
            "type": "Reversal",
            "state": "Completed",
            "number": 40120453503,
            "amount": 15725,
            "vatAmount": 5625,
            "description": "Refund for Order #33995. Amount: 157.25",
            "payeeReference": "33995xwlnmz",
            "isOperational": false,
            "operations": []
        }
    }
}
RESPONSE;

        $this->clientMock->expects($this->at(7))
            ->method('getResponseBody')
            ->willReturn($response);

        $this->clientMock->expects($this->at(10))
            ->method('getResponseBody')
            ->willReturn($response);

        $this->clientMock->expects($this->any())
            ->method('getResponseCode')
            ->willReturn(200);

        $result = $this->coreMock->refundCheckout(33995, []);
        $this->assertInstanceOf(Response::class, $result);
    }

    public function testCancelCheckout()
    {
        $this->clientMock->expects($this->at(5))
            ->method('getResponseBody')
            ->willReturn($this->paymentOrderResponse);

        $response = <<<RESPONSE
{
    "payment": "/psp/creditcard/payments/70abecfe-7ed3-47cb-a0ef-08db0362a155",
    "cancellation": {
        "id": "/psp/creditcard/payments/70abecfe-7ed3-47cb-a0ef-08db0362a155/cancellations/cd69e84f-7b13-4f17-b8b9-08db03630610",
        "transaction": {
            "id": "/psp/creditcard/payments/70abecfe-7ed3-47cb-a0ef-08db0362a155/transactions/cd69e84f-7b13-4f17-b8b9-08db03630610",
            "created": "2023-02-06T06:17:18.4406011Z",
            "updated": "2023-02-06T06:17:20.5759808Z",
            "type": "Cancellation",
            "state": "Completed",
            "number": 40120453529,
            "amount": 28125,
            "vatAmount": 0,
            "description": "Cancellation for Order #33994",
            "payeeReference": "33994xqvwab",
            "isOperational": false,
            "operations": []
        }
    }
}
RESPONSE;

        $this->clientMock->expects($this->at(7))
            ->method('getResponseBody')
            ->willReturn($response);

        $this->clientMock->expects($this->any())
                         ->method('getResponseCode')
                         ->willReturn(200);

        $result = $this->coreMock->cancelCheckout(33994, null, 0);
        $this->assertInstanceOf(Response::class, $result);
    }

    public function testDeletePaymentToken()
    {
        $this->clientMock->expects($this->once())
                         ->method('getResponseBody')
                         ->willReturn('{}');

        $this->coreMock->deletePaymentToken('payment-token');
    }
}
