<?php

namespace SwedbankPay\Core;

/**
 * Interface ConfigurationInterface
 * @package SwedbankPay\Core
 * @method bool getDebug()
 * @method string getAccessToken()
 * @method string getPayeeId()
 * @method string getPayeeName()
 * @method string getSubsite()
 * @method string getLanguage()
 * @method string getTermsUrl()
 * @method string getLogoUrl()
 * @method bool getUsePayerInfo()
 */
interface ConfigurationInterface
{
    const ACCESS_TOKEN = 'access_token';
    const PAYEE_ID = 'payee_id';
    const PAYEE_NAME = 'payee_name';
    const MODE = 'mode';
    const SUBSITE = 'subsite';
    const DEBUG = 'debug';
    const LANGUAGE = 'language';
    const TERMS_URL = 'terms_url';
    const LOGO_URL = 'logo_url';
    const USE_PAYER_INFO = 'use_payer_info';
}
