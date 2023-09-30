<?php

namespace SwedbankPay\Core\Traits;

use SwedbankPay\Core\Api\Authorization;
use SwedbankPay\Core\Api\Response;
use SwedbankPay\Core\Api\FinancialTransaction;
use SwedbankPay\Core\Api\Verification;
use SwedbankPay\Core\Exception;
use SwedbankPay\Core\Log\LogLevel;

trait PaymentInfo
{
    /**
     * Do API Request
     *
     * @param       $method
     * @param       $url
     * @param array $params
     *
     * @return Response
     * @throws Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function request($method, $url, $params = [])
    {
        // Get rid of full url. There's should be an endpoint only.
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $parsed = parse_url($url);
            $url = $parsed['path'];
            if (!empty($parsed['query'])) {
                $url .= '?' . $parsed['query'];
            }
        }

        if (empty($url)) {
            throw new Exception('Invalid url');
        }

        // Process params
        array_walk_recursive($params, function (&$input) {
            if (is_object($input) && method_exists($input, 'toArray')) {
                $input = $input->toArray();
            }
        });

        $start = microtime(true);
        $this->log(
            LogLevel::DEBUG,
            sprintf('Request: %s %s %s', $method, $url, json_encode($params, JSON_PRETTY_PRINT))
        );

        try {
            /** @var \SwedbankPay\Api\Response $response */
            $client = $this->getClient()->request($method, $url, $params);

            //$codeClass = (int)($this->client->getResponseCode() / 100);
            $responseBody = $client->getResponseBody();
            $result = json_decode($responseBody, true);
            $time = microtime(true) - $start;
            $this->log(
                LogLevel::DEBUG,
                sprintf('[%.4F] Response: %s', $time, $responseBody)
            );

            return new Response($result);
        } catch (\SwedbankPay\Api\Client\Exception $e) {
            $httpCode = (int) $this->client->getResponseCode();

            $time = microtime(true) - $start;
            $this->log(
                LogLevel::DEBUG,
                sprintf('[%.4F] Client Exception. Check debug info: %s', $time, $this->client->getDebugInfo())
            );

            // https://tools.ietf.org/html/rfc7807
            $data = json_decode($this->client->getResponseBody(), true);
            if (json_last_error() === JSON_ERROR_NONE &&
                isset($data['title']) &&
                isset($data['detail'])
            ) {
                // Format error message
                $message = sprintf('%s. %s', $data['title'], $data['detail']);

                // Get details
                if (isset($data['problems'])) {
                    $detailed = '';
                    $problems = $data['problems'];
                    foreach ($problems as $problem) {
                        $detailed .= sprintf('%s: %s', $problem['name'], $problem['description']) . "\r\n";
                    }

                    if (!empty($detailed)) {
                        $message .= "\r\n" . $detailed;
                    }
                }

                throw new Exception($message, $httpCode, null, $data['problems']);
            }

            throw new Exception('API Exception. Please check logs');
        }
    }

    /**
     * Fetch Payment Info.
     *
     * @param string $paymentIdUrl
     * @param string|null $expand
     *
     * @return Response
     * @throws Exception
     */
    public function fetchPaymentInfo($paymentIdUrl, $expand = null)
    {
        if ($expand) {
            $paymentIdUrl .= '?$expand=' . $expand;
        }

        try {
            $result = $this->request('GET', $paymentIdUrl);
        } catch (\Exception $e) {
            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($e->getMessage());
        }

        return $result;
    }

    /**
     * Fetch Financial Transaction List.
     *
     * @param $paymentOrderIdUrl
     * @param $expand
     *
     * @return FinancialTransaction[]
     */
    public function fetchFinancialTransactionsList($paymentOrderIdUrl, $expand = null)
    {
        $paymentOrderIdUrl .= '/financialtransactions';

        if ($expand) {
            $paymentOrderIdUrl .= '?$expand=' . $expand;
        }

        try {
            $result = $this->request('GET', $paymentOrderIdUrl);
        } catch (\Exception $e) {
            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            return [];
        }

        $transactionList = $result['financialTransactions']['financialTransactionsList'];

        // Sort by "created" field using array_multisort
        $sortingFlow = array();
        foreach ($transactionList as $id => $transaction) {
            $sortingFlow[$id] = strtotime($transaction['created']);
        }

        // Sort
        array_multisort($sortingFlow, SORT_ASC, SORT_NUMERIC, $transactionList);
        unset($sortingFlow);

        $transactions = [];
        foreach ($transactionList as $transaction) {
            $transactions[] = new FinancialTransaction($transaction);
        }

        return $transactions;
    }

    /**
     * Fetch Verification List.
     *
     * @param string $paymentIdUrl
     * @param string|null $expand
     *
     * @return Verification[]
     * @throws Exception
     */
    public function fetchVerificationList($paymentIdUrl, $expand = null)
    {
        $paymentIdUrl .= '/verifications';

        if ($expand) {
            $paymentIdUrl .= '?$expand=' . $expand;
        }

        try {
            $result = $this->request('GET', $paymentIdUrl);
        } catch (\Exception $e) {
            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($e->getMessage());
        }

        $verifications = [];
        foreach ($result['verifications']['verificationList'] as $verification) {
            $verifications[] = new Verification($verification);
        }

        return $verifications;
    }

    /**
     * Fetch Authorization List.
     *
     * @param string $paymentIdUrl
     * @param string|null $expand
     *
     * @return Authorization[]
     * @throws Exception
     */
    public function fetchAuthorizationList($paymentIdUrl, $expand = null)
    {
        $paymentIdUrl .= '/authorizations';

        if ($expand) {
            $paymentIdUrl .= '?$expand=' . $expand;
        }

        try {
            $result = $this->request('GET', $paymentIdUrl);
        } catch (\Exception $e) {
            $this->log(
                LogLevel::DEBUG,
                sprintf('%s: API Exception: %s', __METHOD__, $e->getMessage())
            );

            throw new Exception($e->getMessage());
        }

        $authorizations = [];
        foreach ($result['authorizations']['authorizationList'] as $authorization) {
            $authorizations[] = new Authorization($authorization);
        }

        return $authorizations;
    }
}
