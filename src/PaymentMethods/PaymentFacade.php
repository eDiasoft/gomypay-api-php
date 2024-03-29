<?php

namespace eDiasoft\Gomypay\PaymentMethods;

use eDiasoft\Gomypay\Config\Config;
use eDiasoft\Gomypay\Exceptions\GomypayException;
use eDiasoft\Gomypay\HttpAdapter\HttpAdapterInterface;
use eDiasoft\Gomypay\HttpAdapter\HttpAdapterPicker;
use eDiasoft\Gomypay\PaymentMethods\Creditcard\Creditcard;
use eDiasoft\Gomypay\PaymentMethods\LinePay\LinePay;
use eDiasoft\Gomypay\PaymentMethods\RegularDeduction\RegularDeduction;
use eDiasoft\Gomypay\PaymentMethods\Supermarket\Barcode;
use eDiasoft\Gomypay\PaymentMethods\Supermarket\Code;
use eDiasoft\Gomypay\PaymentMethods\UnionPay\UnionPay;
use eDiasoft\Gomypay\PaymentMethods\VirtualAccount\VirtualAccount;
use eDiasoft\Gomypay\PaymentMethods\WebATM\WebATM;
use eDiasoft\Gomypay\Response\Transaction;
use eDiasoft\Gomypay\Types\Http;
use eDiasoft\Gomypay\Types\PaymentMethods;
use eDiasoft\Gomypay\Types\Response;

class PaymentFacade
{
    const LIVE_URL = 'https://n.gomypay.asia/ShuntClass.aspx';
    const TEST_URL = 'https://n.gomypay.asia/TestShuntClass.aspx';

    private Config $config;
    private HttpAdapterInterface $httpClient;
    private iPaymentMethod $paymentMethod;

    public function __construct(Config $config, string $method)
    {
        $this->config = $config;

        $this->httpClient = (new HttpAdapterPicker())->pickHttpAdapter();

        $this->setPaymentMethod($method);
    }

    public function __call(string $name , array $arguments)
    {
        if($name == 'create')
        {
            $this->paymentMethod->create($arguments[0] ?? []);
        }

        return $this;
    }

    public function execute(string $responseType = 'default'): Transaction
    {
        $url = ($this->config->isLiveMode())? self::LIVE_URL : self::TEST_URL;

        $response = $this->httpClient->send(
            Http::POST, $url,
            ['Content-Type'  => 'multipart/form-data'],
            $this->collectQueries($responseType),
            responseClass: Transaction::class
        );

        if($responseType == Response::JSON && $response->get('result') == '1' && !$this->responseIsValid($response))
        {
            throw new GomypayException('Response is not valid, wrong encryption. Please check your credentials.');
        }

        if($response->get('result') == '0')
        {
            throw new GomypayException($response->returnMessage());
        }

        return $response;
    }

    public function responseIsValid(Transaction $response): bool
    {
        return md5($response->get('result') . $response->get('e_orderno') . $this->config->storeId() . $response->get('e_money') . $response->get('OrderID') . $this->config->secretKey()) == $response->get('str_check');
    }

    private function collectQueries($response): array
    {
        $queries = array_merge($this->paymentMethod->getPayload(), [
            'CustomerId'    => $this->config->customerId(),
            'Send_Type'     => $this->paymentMethod->sendType()
        ]);

        $queries['Return_url'] = $queries['Return_url'] ?? $this->config->returnUrl();
        $queries['Callback_Url'] = $queries['Callback_Url'] ?? $this->config->callbackUrl();

        if($response == Response::JSON)
        {
            $queries['e_return'] = 1;
            $queries['Str_Check'] = $this->config->secretKey();
        }

        return $queries;
    }

    private function setPaymentMethod(string $method): iPaymentMethod
    {
        switch ($method){
            case PaymentMethods::CREDITCARD:
                return $this->paymentMethod = new Creditcard;
            case PaymentMethods::UNIONPAY:
                return $this->paymentMethod = new UnionPay;
            case PaymentMethods::SPMBARCODE:
                return $this->paymentMethod = new Barcode;
            case PaymentMethods::WEBATM:
                return $this->paymentMethod = new WebATM;
            case PaymentMethods::VIRTUALACCOUNT:
                return $this->paymentMethod = new VirtualAccount;
            case PaymentMethods::REGULARDEDUCTION:
                return $this->paymentMethod = new RegularDeduction;
            case PaymentMethods::SPMCODE:
                return $this->paymentMethod = new Code;
            case PaymentMethods::LINEPAY:
                return $this->paymentMethod = new LinePay;
        }

        throw new GomypayException("Given payment method is not known. Please read the documentation about the available payment methods.");
    }
}