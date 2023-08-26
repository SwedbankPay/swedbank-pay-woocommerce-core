<?php

namespace SwedbankPay\Core;

/**
 * Class Configuration
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
class Configuration extends Data implements ConfigurationInterface
{
    /**
     * Configuration constructor.
     *
     * @param array $data
     */
    public function __construct(array $data = [])
    {
        $this->setData($data);
    }
}
