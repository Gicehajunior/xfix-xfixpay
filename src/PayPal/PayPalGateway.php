<?php

namespace Xfix\XfixPay\PayPal;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Xfix\XfixPay\Exception\PayPalException;
use Xfix\XfixPay\GatewayInterface;

class PayPalGateway implements GatewayInterface
{
    private PayPalConfig $config;
    private PayPalAuth $auth;
    private Client $httpClient;
    private LoggerInterface $logger;

    /* --------------------------------------------------------------------- */
    /* Setup                                                                  */
    /* --------------------------------------------------------------------- */

    public function setConfig(array $config): self
    {
        $this->config = new PayPalConfig($config);
        $this->auth   = new PayPalAuth($this->config);

        $this->httpClient = new Client([
            'base_uri'        => $this->config->getBaseUrl() . '/',
            'timeout'         => 30,
            'connect_timeout' => 10,
        ]);

        $this->logger = (isset($config['logger']) && $config['logger'] instanceof LoggerInterface)
            ? $config['logger']
            : new NullLogger();

        return $this;
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function authenticate(): array
    {
        return ['access_token' => $this->auth->getAccessToken()];
    }

    /* --------------------------------------------------------------------- */
    /* Transport                                                              */
    /* --------------------------------------------------------------------- */

    public function request(string $method, string $endpoint, array $data = []): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->auth->getAccessToken(),
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ],
        ];

        if (!empty($data)) {
            $options['json'] = $data;
        }

        try {
            $response = $this->httpClient->request($method, $endpoint, $options);
            $body     = $response->getBody()->getContents();

            return $body === '' ? [] : (json_decode($body, true) ?: []);
        } catch (GuzzleException $e) {
            $this->logger->error('PayPal API request failed', [
                'method'   => $method,
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);

            throw new PayPalException(
                'PayPal API request failed: ' . $e->getMessage(),
                $e->getCode() ?: 0,
                $e
            );
        }
    }

    /* --------------------------------------------------------------------- */
    /* Orders                                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * Create a PayPal order.
     *
     * @param array $data
     *   - amount       float   (required)
     *   - currency     string  (default USD)
     *   - description  string
     *   - reference_id string
     *   - intent       'CAPTURE'|'AUTHORIZE' (default CAPTURE)
     *   - return_url   string
     *   - cancel_url   string
     *   - landing_page 'LOGIN'|'BILLING'|'NO_PREFERENCE'
     *   - user_action  'CONTINUE'|'PAY_NOW'
     *   - items        array of item arrays (name, quantity, unit_amount, currency)
     *
     * @return array
     */
    public function createOrder(array $data): array
    {
        $amount      = (float) ($data['amount'] ?? 0);
        $currency    = strtoupper($data['currency'] ?? 'USD');
        $description = $data['description'] ?? 'Payment';

        if ($amount <= 0) {
            throw new PayPalException('Amount must be greater than zero.');
        }

        $purchaseUnit = [
            'amount' => [
                'currency_code' => $currency,
                'value'         => number_format($amount, 2, '.', ''),
            ],
            'description' => $description,
        ];

        if (!empty($data['reference_id'])) {
            $purchaseUnit['reference_id'] = $data['reference_id'];
        }

        if (!empty($data['items']) && is_array($data['items'])) {
            $items = [];
            foreach ($data['items'] as $item) {
                $items[] = [
                    'name'        => (string) ($item['name'] ?? 'Item'),
                    'quantity'    => (string) ($item['quantity'] ?? '1'),
                    'unit_amount' => [
                        'currency_code' => $currency,
                        'value'         => number_format((float) ($item['unit_amount'] ?? 0), 2, '.', ''),
                    ],
                ];
            }
            $purchaseUnit['items'] = $items;
        }

        $payload = [
            'intent'         => strtoupper($data['intent'] ?? 'CAPTURE'),
            'purchase_units' => [$purchaseUnit],
        ];

        $context = array_filter([
            'return_url'   => $data['return_url']   ?? null,
            'cancel_url'   => $data['cancel_url']   ?? null,
            'landing_page' => $data['landing_page'] ?? 'BILLING',
            'user_action'  => $data['user_action']  ?? 'PAY_NOW',
        ], static fn ($v) => $v !== null && $v !== '');

        if (!empty($context)) {
            $payload['application_context'] = $context;
        }

        $response = $this->request('POST', 'v2/checkout/orders', $payload);

        if (empty($response['id'])) {
            throw new PayPalException('PayPal order creation did not return an order ID.');
        }

        $approvalUrl = $this->findLink($response['links'] ?? [], 'approve');

        $this->logger->info('PayPal order created', [
            'order_id' => $response['id'],
            'status'   => $response['status'] ?? null,
        ]);

        return [
            'order_id'      => $response['id'],
            'status'        => $response['status'] ?? 'CREATED',
            'approval_url'  => $approvalUrl,
            'links'         => $response['links'] ?? [],
            'full_response' => $response,
        ];
    }

    /**
     * Backwards-compatible alias for createOrder().
     */
    public function initiatePayment(array $data): array
    {
        return $this->createOrder($data);
    }

    /**
     * Fetch an existing order by ID.
     */
    public function getOrder(string $orderId): array
    {
        if ($orderId === '') {
            throw new PayPalException('orderId is required.');
        }

        return $this->request('GET', 'v2/checkout/orders/' . rawurlencode($orderId));
    }

    /**
     * Fetch the status of a PayPal transaction (order or capture) by ID.
     *
     * The response is normalized to match the shape expected by the
     * GatewayCallbackController:
     *   error                => null or ['message','code','error_type']
     *   payment_status_description => 'COMPLETED'|'CREATED'|'APPROVED'|...
     *   status_code          => 1 for completed, 0 otherwise
     *   payment_method       => 'paypal'
     *   confirmation_code    => capture id (or order id if no capture)
     *   amount               => float
     *   currency             => string (e.g. 'USD')
     *   created_date         => ISO-8601 string
     *   payment_account      => payer email (or null)
     *   account_number       => payer email (or null)
     *   merchant_reference   => purchase unit reference_id (or null)
     *
     * @param string $transactionId PayPal order ID or capture ID
     * @return array
     * @throws PayPalException
     */
    public function getTransactionStatus(string $transactionId): array
    {
        if ($transactionId === '') {
            throw new PayPalException('transactionId is required.');
        }

        // Try fetching as an order first.
        $order = $this->getOrder($transactionId);

        $status          = strtoupper((string) ($order['status'] ?? ''));
        $purchaseUnits   = $order['purchase_units'] ?? [];
        $firstUnit       = $purchaseUnits[0] ?? [];
        $amountObj       = $firstUnit['amount'] ?? [];
        $amount          = (float) ($amountObj['value'] ?? 0);
        $currency        = strtoupper((string) ($amountObj['currency_code'] ?? 'USD'));
        $merchantRef     = $firstUnit['reference_id'] ?? null;
        $createdDate     = $order['create_time'] ?? null;
        $isCompleted     = $status === 'COMPLETED';

        $confirmationCode = null;
        $payerEmail       = null;

        if ($isCompleted) {
            // Try to extract capture details from the order's payments array.
            $payments = $firstUnit['payments'] ?? [];
            $captures = $payments['captures'] ?? [];
            if (!empty($captures)) {
                $capture = $captures[0];
                $confirmationCode = $capture['id'] ?? null;
                // Fall back to order-level payer info for email.
            }

            // If we still don't have a confirmation code, try fetching the capture directly
            // in case $transactionId was actually a capture ID.
            if ($confirmationCode === null) {
                try {
                    $capture = $this->request('GET', 'v2/payments/captures/' . rawurlencode($transactionId));
                    $confirmationCode = $capture['id'] ?? $transactionId;
                    $payerEmail       = $capture['payer']['email_address'] ?? null;
                    $amount           = (float) ($capture['amount']['value'] ?? $amount);
                    $currency         = strtoupper((string) ($capture['amount']['currency_code'] ?? $currency));
                    $createdDate      = $capture['create_time'] ?? $createdDate;
                } catch (PayPalException $e) {
                    // Not a capture ID; fall back to order-level data.
                }
            }
        }

        // Extract payer email from order-level payer info if not yet set.
        if ($payerEmail === null) {
            $payerEmail = $order['payer']['email_address'] ?? null;
        }

        $statusDesc = $isCompleted ? 'COMPLETED' : $status;
        $statusCode = $isCompleted ? 1 : 0;

        $this->logger->info('PayPal transaction status fetched', [
            'transaction_id' => $transactionId,
            'status'         => $statusDesc,
            'amount'         => $amount,
            'currency'       => $currency,
        ]);

        return [
            'error'                     => [],
            'payment_status_description'=> $statusDesc,
            'status_code'               => $statusCode,
            'payment_method'            => 'paypal',
            'confirmation_code'         => $confirmationCode ?: $transactionId,
            'amount'                    => $amount,
            'currency'                  => $currency,
            'created_date'              => $createdDate,
            'payment_account'           => $payerEmail,
            'account_number'            => $payerEmail,
            'merchant_reference'        => $merchantRef,
            'full_response'             => $order,
        ];
    }

    /**
     * Capture an approved order (intent = CAPTURE).
     */
    public function captureOrder(string $orderId): array
    {
        if ($orderId === '') {
            throw new PayPalException('orderId is required to capture an order.');
        }

        $response = $this->request('POST', 'v2/checkout/orders/' . rawurlencode($orderId) . '/capture');

        $captureId = $response['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;

        $this->logger->info('PayPal order captured', [
            'order_id'   => $response['id'] ?? $orderId,
            'capture_id' => $captureId,
            'status'     => $response['status'] ?? null,
        ]);

        return [
            'order_id'      => $response['id'] ?? $orderId,
            'status'        => $response['status'] ?? null,
            'capture_id'    => $captureId,
            'full_response' => $response,
        ];
    }

    /**
     * Authorize an approved order (intent = AUTHORIZE).
     */
    public function authorizeOrder(string $orderId): array
    {
        if ($orderId === '') {
            throw new PayPalException('orderId is required to authorize an order.');
        }

        $response = $this->request('POST', 'v2/checkout/orders/' . rawurlencode($orderId) . '/authorize');

        $authorizationId = $response['purchase_units'][0]['payments']['authorizations'][0]['id'] ?? null;

        return [
            'order_id'         => $response['id'] ?? $orderId,
            'status'           => $response['status'] ?? null,
            'authorization_id' => $authorizationId,
            'full_response'    => $response,
        ];
    }

    /* --------------------------------------------------------------------- */
    /* Captures & Refunds                                                     */
    /* --------------------------------------------------------------------- */

    public function getCapture(string $captureId): array
    {
        if ($captureId === '') {
            throw new PayPalException('captureId is required.');
        }

        return $this->request('GET', 'v2/payments/captures/' . rawurlencode($captureId));
    }

    /**
     * Refund a capture (full or partial).
     *
     * @param array $data
     *   - capture_id    string  (required)
     *   - amount        float   (omit for full refund)
     *   - currency      string  (default USD)
     *   - note_to_payer string
     *   - invoice_id    string
     */
    public function refund(array $data): array
    {
        $captureId = $data['capture_id'] ?? '';
        if ($captureId === '') {
            throw new PayPalException('capture_id is required to issue a refund.');
        }

        $payload = [];

        if (isset($data['amount'])) {
            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                throw new PayPalException('Refund amount must be greater than zero.');
            }
            $payload['amount'] = [
                'value'         => number_format($amount, 2, '.', ''),
                'currency_code' => strtoupper($data['currency'] ?? 'USD'),
            ];
        }

        if (!empty($data['note_to_payer'])) {
            $payload['note_to_payer'] = $data['note_to_payer'];
        }
        if (!empty($data['invoice_id'])) {
            $payload['invoice_id'] = $data['invoice_id'];
        }

        $response = $this->request(
            'POST',
            'v2/payments/captures/' . rawurlencode($captureId) . '/refund',
            $payload
        );

        return [
            'refund_id'     => $response['id'] ?? null,
            'status'        => $response['status'] ?? null,
            'full_response' => $response,
        ];
    }

    public function getRefund(string $refundId): array
    {
        if ($refundId === '') {
            throw new PayPalException('refundId is required.');
        }

        return $this->request('GET', 'v2/payments/refunds/' . rawurlencode($refundId));
    }

    /* --------------------------------------------------------------------- */
    /* Subscriptions                                                          */
    /* --------------------------------------------------------------------- */

    public function createSubscription(array $data): array
    {
        $planId = $data['plan_id'] ?? '';
        if ($planId === '') {
            throw new PayPalException('plan_id is required to create a subscription.');
        }

        $payload = ['plan_id' => $planId];

        if (!empty($data['subscriber'])) {
            $payload['subscriber'] = $data['subscriber'];
        }
        if (!empty($data['application_context'])) {
            $payload['application_context'] = $data['application_context'];
        }
        if (!empty($data['custom_id'])) {
            $payload['custom_id'] = $data['custom_id'];
        }
        if (isset($data['quantity'])) {
            $payload['quantity'] = (string) $data['quantity'];
        }

        $response = $this->request('POST', 'v1/billing/subscriptions', $payload);

        if (empty($response['id'])) {
            throw new PayPalException('PayPal subscription creation did not return an ID.');
        }

        return [
            'subscription_id' => $response['id'],
            'status'          => $response['status'] ?? null,
            'approval_url'    => $this->findLink($response['links'] ?? [], 'approve'),
            'full_response'   => $response,
        ];
    }

    public function getSubscription(string $subscriptionId): array
    {
        if ($subscriptionId === '') {
            throw new PayPalException('subscriptionId is required.');
        }

        return $this->request('GET', 'v1/billing/subscriptions/' . rawurlencode($subscriptionId));
    }

    public function cancelSubscription(string $subscriptionId, string $reason = ''): bool
    {
        if ($subscriptionId === '') {
            throw new PayPalException('subscriptionId is required to cancel a subscription.');
        }

        $this->request(
            'POST',
            'v1/billing/subscriptions/' . rawurlencode($subscriptionId) . '/cancel',
            ['reason' => $reason !== '' ? $reason : 'Cancelled by merchant']
        );

        $this->logger->info('PayPal subscription cancelled', ['subscription_id' => $subscriptionId]);

        return true;
    }

    /* --------------------------------------------------------------------- */
    /* Webhooks                                                               */
    /* --------------------------------------------------------------------- */

    public function registerWebhook(string $webhookUrl, array $eventTypes = []): array
    {
        if ($webhookUrl === '') {
            throw new PayPalException('webhookUrl is required to register a PayPal webhook.');
        }

        if (empty($eventTypes)) {
            $eventTypes = [
                'PAYMENT.CAPTURE.COMPLETED',
                'PAYMENT.CAPTURE.DENIED',
                'PAYMENT.CAPTURE.REFUNDED',
                'BILLING.SUBSCRIPTION.ACTIVATED',
                'BILLING.SUBSCRIPTION.CANCELLED',
            ];
        }

        $eventTypeArray = array_map(static fn ($name) => ['name' => $name], $eventTypes);

        $this->logger->info('Registering PayPal webhook', [
            'url'    => $webhookUrl,
            'events' => $eventTypes,
        ]);

        $response = $this->request('POST', 'v1/notifications/webhooks', [
            'url'         => $webhookUrl,
            'event_types' => $eventTypeArray,
        ]);

        if (empty($response['id'])) {
            throw new PayPalException('PayPal webhook registration response did not contain a webhook id.');
        }

        $this->logger->info('PayPal webhook registered successfully', [
            'webhook_id' => $response['id'],
        ]);

        return $response;
    }

    /**
     * Verify a PayPal webhook signature.
     *
     * @param array  $headers   Request headers (case-insensitive)
     * @param string $body      Raw request body
     * @param string $webhookId Webhook ID returned by registerWebhook()
     */
    public function verifyWebhookSignature(array $headers, string $body, string $webhookId): bool
    {
        if ($webhookId === '') {
            throw new PayPalException('webhookId is required to verify a webhook signature.');
        }

        $event = json_decode($body, true);
        if (!is_array($event)) {
            throw new PayPalException('Invalid PayPal webhook payload (not valid JSON).');
        }

        $payload = [
            'auth_algo'         => $this->headerValue($headers, 'PAYPAL-AUTH-ALGO'),
            'cert_url'          => $this->headerValue($headers, 'PAYPAL-CERT-URL'),
            'transmission_id'   => $this->headerValue($headers, 'PAYPAL-TRANSMISSION-ID'),
            'transmission_sig'  => $this->headerValue($headers, 'PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $this->headerValue($headers, 'PAYPAL-TRANSMISSION-TIME'),
            'webhook_id'        => $webhookId,
            'webhook_event'     => $event,
        ];

        $response = $this->request('POST', 'v1/notifications/verify-webhook-signature', $payload);

        return ($response['verification_status'] ?? '') === 'SUCCESS';
    }

    /**
     * Verify the signature and return the decoded event, or throw.
     *
     * @return array The decoded PayPal webhook event
     */
    public function handleWebhook(array $headers, string $body, string $webhookId): array
    {
        if (!$this->verifyWebhookSignature($headers, $body, $webhookId)) {
            throw new PayPalException('PayPal webhook signature verification failed.');
        }

        $event = json_decode($body, true);

        $this->logger->info('PayPal webhook received', [
            'event_type' => $event['event_type'] ?? null,
            'event_id'   => $event['id'] ?? null,
            'resource'   => $event['resource']['id'] ?? null,
        ]);

        return $event;
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Return the href of the first link matching $rel, or null.
     */
    private function findLink(array $links, string $rel): ?string
    {
        foreach ($links as $link) {
            if (($link['rel'] ?? '') === $rel) {
                return $link['href'] ?? null;
            }
        }

        return null;
    }

    /**
     * Case-insensitive header lookup; supports PSR-7 style (array of values).
     */
    private function headerValue(array $headers, string $name): string
    {
        $needle = strtolower($name);

        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $needle) {
                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return '';
    }
}