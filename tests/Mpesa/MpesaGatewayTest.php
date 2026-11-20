<?php

namespace Xfix\XfixPay\Tests\Mpesa;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Xfix\XfixPay\Mpesa\MpesaGateway;

class MpesaGatewayTest extends TestCase
{
    private array $history = [];

    private ?string $certificatePath = null;

    protected function tearDown(): void
    {
        if ($this->certificatePath && file_exists($this->certificatePath)) {
            unlink($this->certificatePath);
        }

        parent::tearDown();
    }

    public function testDepositUsesStkPushAndNormalizesThePhoneNumber(): void
    {
        $gateway = $this->makeGateway();

        $response = $gateway->deposit(
            '0712345678',
            125.0,
            'WALLET-17',
            'Wallet deposit',
            'https://merchant.example/mpesa/stk/callback'
        );

        $this->assertSame(['ResponseCode' => '0'], $response);
        $this->assertSame('/oauth/v1/generate', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('/mpesa/stkpush/v1/processrequest', $this->history[1]['request']->getUri()->getPath());

        $payload = $this->requestPayload(1);
        $this->assertSame(125, $payload['Amount']);
        $this->assertSame('254712345678', $payload['PartyA']);
        $this->assertSame('WALLET-17', $payload['AccountReference']);
        $this->assertSame('https://merchant.example/mpesa/stk/callback', $payload['CallBackURL']);
    }

    public function testTillDepositCanUseBuyGoodsStkType(): void
    {
        $gateway = $this->makeGateway();

        $gateway->deposit(
            '254712345678',
            125.0,
            'WALLET-17',
            'Wallet deposit',
            'https://merchant.example/mpesa/stk/callback',
            'CustomerBuyGoodsOnline'
        );

        $this->assertSame('CustomerBuyGoodsOnline', $this->requestPayload(1)['TransactionType']);
    }

    public function testWithdrawalUsesB2CAndConfiguredCallbacks(): void
    {
        $gateway = $this->makeGateway(true);

        $gateway->withdraw('254712345678', 300.0, 'Client withdrawal');

        $this->assertSame('/mpesa/b2c/v1/paymentrequest', $this->history[1]['request']->getUri()->getPath());
        $payload = $this->requestPayload(1);
        $this->assertSame('BusinessPayment', $payload['CommandID']);
        $this->assertSame('254712345678', $payload['PartyB']);
        $this->assertSame('https://merchant.example/mpesa/b2c/result', $payload['ResultURL']);
        $this->assertSame('https://merchant.example/mpesa/b2c/timeout', $payload['QueueTimeOutURL']);
        $this->assertNotSame('', $payload['SecurityCredential']);
    }

    public function testTransferUsesB2B(): void
    {
        $gateway = $this->makeGateway(true);

        $gateway->transfer('600001', 500.0, 'INV-204', 'Supplier transfer');

        $this->assertSame('/mpesa/b2b/v1/paymentrequest', $this->history[1]['request']->getUri()->getPath());
        $payload = $this->requestPayload(1);
        $this->assertSame('BusinessPayBill', $payload['CommandID']);
        $this->assertSame('600000', $payload['PartyA']);
        $this->assertSame('600001', $payload['PartyB']);
        $this->assertSame('INV-204', $payload['AccountReference']);
        $this->assertSame('https://merchant.example/mpesa/b2b/result', $payload['ResultURL']);
    }

    public function testReversalAndTransactionStatusUseTheirOwnEndpoints(): void
    {
        $gateway = $this->makeGateway(true, [
            new Response(200, [], '{"ResponseCode":"0"}'),
            new Response(200, [], '{"ResponseCode":"0"}'),
        ]);

        $gateway->reversal('QWE1234567', 100.0);
        $gateway->queryTransactionStatus('QWE1234567');

        $this->assertSame('/mpesa/reversal/v1/request', $this->history[1]['request']->getUri()->getPath());
        $this->assertSame('/mpesa/transactionstatus/v1/query', $this->history[2]['request']->getUri()->getPath());
        $this->assertSame('TransactionReversal', $this->requestPayload(1)['CommandID']);
        $this->assertSame('TransactionStatusQuery', $this->requestPayload(2)['CommandID']);
        $this->assertSame('https://merchant.example/mpesa/reversal/result', $this->requestPayload(1)['ResultURL']);
        $this->assertSame('https://merchant.example/mpesa/transaction-status/result', $this->requestPayload(2)['ResultURL']);
    }

    public function testInvalidAmountsAndMissingCallbackUrlsAreRejectedBeforeRequests(): void
    {
        $gateway = $this->makeGateway(false, [], false);

        try {
            $gateway->deposit(
                '0712345678',
                10.5,
                'WALLET-17',
                'Wallet deposit',
                'https://merchant.example/mpesa/stk/callback'
            );
            $this->fail('Fractional M-Pesa amounts should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('whole number', $exception->getMessage());
        }

        try {
            $gateway->sendB2C('0712345678', 10.0);
            $this->fail('B2C callbacks must be configured or provided.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Callback URL is required', $exception->getMessage());
        }

        $this->assertCount(0, $this->history);
    }

    private function makeGateway(
        bool $withCertificate = false,
        array $apiResponses = [],
        bool $withCallbacks = true
    ): MpesaGateway
    {
        $this->history = [];

        $responses = [
            new Response(200, [], '{"access_token":"test-token","expires_in":"3600"}'),
        ];
        $responses = array_merge($responses, $apiResponses ?: [
            new Response(200, [], '{"ResponseCode":"0"}'),
        ]);

        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($this->history));

        $client = new Client([
            'base_uri' => 'https://sandbox.safaricom.co.ke',
            'handler'  => $handler,
        ]);

        $config = [
            'consumer_key'     => 'test-key',
            'consumer_secret'  => 'test-secret',
            'passkey'          => 'test-passkey',
            'shortcode'        => '600000',
            'initiator'        => 'test-initiator',
            'initiator_password' => 'test-password',
            'environment'      => 'sandbox',
        ];

        if ($withCallbacks) {
            $config = array_merge($config, [
                'b2c_result_url' => 'https://merchant.example/mpesa/b2c/result',
                'b2c_timeout_url' => 'https://merchant.example/mpesa/b2c/timeout',
                'b2b_result_url' => 'https://merchant.example/mpesa/b2b/result',
                'b2b_timeout_url' => 'https://merchant.example/mpesa/b2b/timeout',
                'reversal_result_url' => 'https://merchant.example/mpesa/reversal/result',
                'reversal_timeout_url' => 'https://merchant.example/mpesa/reversal/timeout',
                'transaction_status_result_url' => 'https://merchant.example/mpesa/transaction-status/result',
                'transaction_status_timeout_url' => 'https://merchant.example/mpesa/transaction-status/timeout',
            ]);
        }

        if ($withCertificate) {
            $this->certificatePath = tempnam(sys_get_temp_dir(), 'xfixpay-mpesa-test-');
            file_put_contents($this->certificatePath, <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsKd4oz0c42u95estH+yk
Skc4e5MeNcFbD3hxsj2yDciBFSwgw7xun54dYXhmsQmR8UR/vJj4/88UGh0JORla
UvU0gTXrdVe5G/6mq1I92uabIkJsTjzDPNaaFfyap3Geh2QP8PhPeu8COrqK6dyO
DcIZi+VWVSkZ1a9rl6UUbEt0diEhMQ+opBqjacgpZX90nOVDANC2P5MI/SNOYdtO
mHcWzuVFNHbJcFTkODz32JYEGyTngG+/M0kx8g8/feHI54qTo3F2FBE9RjQkvy8H
Zz8JoWUhD3BvjExC6je0/kJi3gJNryTuJug3o/BhT/X2mHBh1otQUCv5pHOhoUyg
mQIDAQAB
-----END PUBLIC KEY-----
PEM
            );
            $config['cert_path'] = $this->certificatePath;
        }

        return (new MpesaGateway($client))->setConfig($config);
    }

    private function requestPayload(int $historyIndex): array
    {
        return json_decode(
            (string) $this->history[$historyIndex]['request']->getBody(),
            true
        );
    }
}
