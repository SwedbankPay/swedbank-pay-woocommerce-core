<?php

use SwedbankPay\Core\Core;

class CoreTest extends TestCase
{
    public function testCoreTest()
    {
        $this->gateway = new Gateway();
        $this->adapter = new Adapter($this->gateway);
        $this->core = new Core($this->adapter);

        $this->core->log('debug', 'Hello, world', [time()]);
        $this->assertEquals(true, file_exists(sys_get_temp_dir() . '/swedbankpay.log'));
    }

    public function testFormatErrorMessage()
    {
        $this->gateway = new Gateway();
        $this->adapter = new Adapter($this->gateway);
        $this->core = new Core($this->adapter);

        $responseBody = <<<DATA
{
    "type": "https://api.payex.com/psp/errordetail/inputerror",
    "title": "Error in input data",
    "status": 400,
    "instance": "https://api.payex.com/psp/payment/creditcard/00-d39554113af841da9ad82dc0f0292533-aacccdee47d4b554-01",
    "detail": "Input validation failed, error description in problems node!",
    "problems": [{
        "name": "Payment.Cardholder.Msisdn",
        "description": "The field Msisdn must match the regular expression '^[+][0-9]+$'"
    }, {
        "name": "Payment.Cardholder.HomePhoneNumber",
        "description": "The field HomePhoneNumber must match the regular expression '^[+][0-9]+$'"
    }, {
        "name": "Payment.Cardholder.WorkPhoneNumber",
        "description": "The field WorkPhoneNumber must match the regular expression '^[+][0-9]+$'"
    }, {
        "name": "Payment.Cardholder.BillingAddress.Msisdn",
        "description": "The field Msisdn must match the regular expression '^[+][0-9]+$'"
    }, {
        "name": "Payment.Cardholder.ShippingAddress.Msisdn",
        "description": "The field Msisdn must match the regular expression '^[+][0-9]+$'"
    }]
}
DATA;

        $result = $this->core->formatErrorMessage($responseBody);
        $this->assertEquals(
            'Your phone number format is wrong. Please input with country code, for example like this +46707777777',
            $result
        );
    }
}
