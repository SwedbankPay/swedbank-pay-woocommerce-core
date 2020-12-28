<?php

namespace SwedbankPay\Core\Library\Methods;

use SwedbankPay\Core\Api\Response;
use SwedbankPay\Core\Exception;
use SwedbankPay\Core\Log\LogLevel;
use SwedbankPay\Core\Order;

use SwedbankPay\Api\Service\Payment\Resource\Collection\PricesCollection;
use SwedbankPay\Api\Service\Payment\Resource\Collection\Item\PriceItem;
use SwedbankPay\Api\Service\Payment\Resource\Request\Metadata;
use SwedbankPay\Api\Service\Trustly\Request\Purchase;
use SwedbankPay\Api\Service\Trustly\Resource\Request\PaymentPayeeInfo;
use SwedbankPay\Api\Service\Trustly\Resource\Request\PaymentPrefillInfo;
use SwedbankPay\Api\Service\Trustly\Resource\Request\PaymentUrl;
use SwedbankPay\Api\Service\Trustly\Resource\Request\Payment;
use SwedbankPay\Api\Service\Trustly\Resource\Request\PaymentObject;
use SwedbankPay\Api\Service\Data\ResponseInterface as ResponseServiceInterface;

trait Trustly
{
    /**
     * Initiate Trustly Payment
     *
     * @param mixed $orderId
     *
     * @return Response
     * @throws Exception
     */
    public function initiateTrustlyPayment($orderId)
    {
        /** @var Order $order */
        $order = $this->getOrder($orderId);

        $urls = $this->getPlatformUrls($orderId);

        $url = new PaymentUrl();
        $url->setCompleteUrl($urls->getCompleteUrl())
            ->setCancelUrl($urls->getCancelUrl())
            ->setCallbackUrl($urls->getCallbackUrl())
            ->setLogoUrl($urls->getLogoUrl())
            ->setTermsOfService($urls->getTermsUrl())
            ->setHostUrls($urls->getHostUrls());

        $payeeInfo = new PaymentPayeeInfo($this->getPayeeInfo($orderId)->toArray());

        $prefillInfo = new PaymentPrefillInfo([
            'firstName' => $order->getBillingFirstName(),
            'lastName' => $order->getBillingLastName()
        ]);

        //$prefillInfo->setFirstName($order->getBillingFirstName())
        //    ->setLatName($order->getBillingLastName());

        $price = new PriceItem();
        $price->setType(TrustlyInterface::PRICE_TYPE_TRUSTLY)
            ->setAmount($order->getAmountInCents())
            ->setVatAmount($order->getVatAmountInCents());

        $prices = new PricesCollection();
        $prices->addItem($price);

        $metadata = new Metadata();
        $metadata->setData('order_id', $order->getOrderId());

        $payment = new Payment();
        $payment->setOperation(self::OPERATION_PURCHASE)
            ->setIntent(self::INTENT_SALE)
            ->setCurrency($order->getCurrency())
            ->setDescription($order->getDescription())
            ->setUserAgent($order->getHttpUserAgent())
            ->setLanguage($order->getLanguage())
            ->setUrls($url)
            ->setPayeeInfo($payeeInfo)
            ->setPrefillInfo($prefillInfo)
            ->setPrices($prices)
            ->setMetadata($metadata);

        $paymentObject = new PaymentObject();
        $paymentObject->setPayment($payment);

        $purchaseRequest = new Purchase($paymentObject);
        $purchaseRequest->setClient($this->client);

        try {
            /** @var ResponseServiceInterface $responseService */
            $responseService = $purchaseRequest->send();

            return new Response($responseService->getResponseData());
        } catch (\Exception $e) {
            $this->log(
                LogLevel::DEBUG,
                sprintf('%s::%s: API Exception: %s', __CLASS__, __METHOD__, $e->getMessage())
            );

            throw new Exception($e->getMessage());
        }
    }
}
