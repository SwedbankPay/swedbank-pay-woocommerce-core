<?php

use PHPUnit\Framework\MockObject\MockBuilder;
use SwedbankPay\Api\Client\Client;
use SwedbankPay\Core\Core;
use SwedbankPay\Core\Configuration;
use SwedbankPay\Core\Adapter\PaymentAdapterInterface;

class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Gateway
     */
    protected $gateway;

    /**
     * @var Adapter
     */
    protected $adapter;

    /**
     * @var Core
     */
    protected $core;

    /**
     * @var Core&\PHPUnit\Framework\MockObject\MockObject|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $coreMock;

    /**
     * @var Client&\PHPUnit\Framework\MockObject\MockObject|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $clientMock;

    /**
     * @var Configuration&\PHPUnit\Framework\MockObject\MockObject|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $configurationMock;

    /**
     * @var PaymentAdapterInterface&\PHPUnit\Framework\MockObject\MockObject|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $adapterMock;

    /**
     * @var string
     */
    protected $paymentOrderResponse;

    protected function setUp(): void
    {
        if (!defined('ACCESS_TOKEN') ||
            ACCESS_TOKEN === '<access_token>') {
            $this->fail('ACCESS_TOKEN not configured in INI file or environment variable.');
        }

        if (!defined('PAYEE_ID') ||
            PAYEE_ID === '<payee_id>') {
            $this->fail('PAYEE_ID not configured in INI file or environment variable.');
        }

        $this->gateway = new Gateway();
        $this->adapter = new Adapter($this->gateway);
        $this->core = new Core($this->adapter);

        // Init mocks
        $this->coreMock = clone $this->core;

        $this->adapterMock = $this->getMockBuilder(PaymentAdapterInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->adapterMock->expects($this->any())
            ->method('getOrderData')
            ->willReturn($this->adapter->getOrderData(1));

        $this->adapterMock->expects($this->any())
            ->method('getPlatformUrls')
            ->willReturn($this->adapter->getPlatformUrls(1));

        $this->adapterMock->expects($this->any())
            ->method('getPayeeInfo')
            ->willReturn($this->adapter->getPayeeInfo(1));

        $this->adapterMock->expects($this->any())
            ->method('processPaymentObject')
            ->willReturnCallback(function ($paymentObject, $orderId) {
                return $this->adapter->processPaymentObject($paymentObject, $orderId);
            });

        $reflection = new \ReflectionClass($this->coreMock);
        $adapterProp = $reflection->getProperty('adapter');
        $adapterProp->setAccessible(true);
        $adapterProp->setValue(
            $this->coreMock,
            $this->adapterMock
        );

        $this->clientMock = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->clientMock->expects($this->any())
            ->method('getAccessToken')
            ->willReturn(ACCESS_TOKEN);

        $this->clientMock->expects($this->any())
            ->method('getPayeeId')
            ->willReturn(PAYEE_ID);

        $this->clientMock->expects($this->any())
            ->method('request')
            ->willReturn($this->clientMock);

        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue(
            $this->coreMock,
            $this->clientMock
        );

        $this->configurationMock = $this->getMockBuilder(Configuration::class)
            ->disableOriginalConstructor()
            ->getMock();

        $map = [
            'getSubsite' => 'subsite',
            'getPayeeId' => PAYEE_ID,
            'getPayeeName' => 'payee-name',
            'getAccessToken' => ACCESS_TOKEN,
            'getDebug' => false,
        ];

        $this->configurationMock
            ->expects($this->any())
            ->method('__call')
            ->willReturnCallback(function ($key) use ($map) {
                if (isset($map[$key])) {
                    return $map[$key];
                }

                return false;
            });

        $configurationProp = $reflection->getProperty('configuration');
        $configurationProp->setAccessible(true);
        $configurationProp->setValue(
            $this->coreMock,
            $this->configurationMock
        );

        $this->paymentOrderResponse = <<<RESPONSE
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
        "method": "POST",
        "href": "https://api.externalintegration.payex.com/psp/paymentorders/eca83607-0e04-415d-c913-08db05db225f/reversals",
        "rel": "create-paymentorder-reversal",
        "contentType": "application/json"
    }, {
        "method": "POST",
        "href": "https://api.externalintegration.payex.com/psp/creditcard/payments/ac44c139-99d7-4f02-a10f-08db0362a155/reversals",
        "rel": "create-reversal",
        "contentType": "application/json"
    }, {
        "method": "GET",
        "href": "https://api.externalintegration.payex.com/psp/paymentorders/eca83607-0e04-415d-c913-08db05db225f/paid",
        "rel": "paid-paymentorder",
        "contentType": "application/json"
    }]
}
RESPONSE;
    }

    protected function tearDown(): void
    {
        $this->gateway = null;
        $this->adapter = null;
        $this->core = null;
        $this->coreMock = null;
        $this->adapterMock = null;
        $this->clientMock = null;
        $this->configurationMock = null;
    }

    /**
     * Returns a builder object to create mock objects using a fluent interface.
     *
     * @param string|string[] $className
     */
    public function getMockBuilder($className): MockBuilder
    {
        return new MockBuilder($this, $className);
    }
}
