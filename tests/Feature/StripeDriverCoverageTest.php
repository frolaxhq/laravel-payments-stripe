<?php

use Frolax\Payment\DTOs\CanonicalRefundPayload;
use Frolax\Payment\DTOs\CanonicalStatusPayload;
use Frolax\Payment\DTOs\CanonicalSubscriptionPayload;
use Frolax\Payment\DTOs\CredentialsDTO;
use Frolax\Payment\DTOs\GatewayResult;
use Frolax\Payment\DTOs\MoneyDTO;
use Frolax\Payment\Enums\PaymentStatus;
use Frolax\PaymentStripe\StripeDriver;
use Frolax\PaymentStripe\StripeGatewayAddon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->driver = new StripeDriver;
    $this->addon = new StripeGatewayAddon;
    $this->credentials = new CredentialsDTO(
        gateway: 'stripe',
        profile: 'test',
        credentials: [
            'secret_key' => 'sk_test_fake123',
            'publishable_key' => 'pk_test_fake123',
            'webhook_secret' => 'whsec_test_fake123',
        ],
    );
});

// Add extra tests to StripeDriverTest here

test('stripe driver sets credentials', function () {
    $driver = new StripeDriver;
    $result = $driver->setCredentials($this->credentials);

    expect($result)->toBeInstanceOf(StripeDriver::class);
});

test('stripe driver handles verify with missing payment intent in session', function () {
    Http::fake([
        '*/v1/checkout/sessions/cs_test_abc123' => Http::response([
            'id' => 'cs_test_abc123',
            'status' => 'expired',
        ]),
    ]);

    $request = Request::create('/return', 'GET', ['session_id' => 'cs_test_abc123']);
    $result = $this->driver->verify($request, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Expired)
        ->and($result->gatewayReference)->toBe('cs_test_abc123');
});

test('stripe driver verify handles other mapped status', function () {
    Http::fake([
        '*/v1/checkout/sessions/cs_test_abc123' => Http::response([
            'id' => 'cs_test_abc123',
            'status' => 'unknown_status',
        ]),
    ]);

    $request = Request::create('/return', 'GET', ['session_id' => 'cs_test_abc123']);
    $result = $this->driver->verify($request, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Failed);
});

test('stripe driver supports hosted redirect missing url', function () {
    $result = new GatewayResult(
        status: PaymentStatus::Pending,
        gatewayReference: 'abc',
    );
    expect($this->driver->getRedirectUrl($result))->toBeNull();
});

test('stripe driver verify webhook signature fails missing secret or signature', function () {
    $request = Request::create('/webhook', 'POST');
    expect($this->driver->verifyWebhookSignature($request, $this->credentials))->toBeFalse();
});

test('stripe driver verify webhook signature rejects old timestamp', function () {
    $payload = json_encode(['type' => 'payment_intent.succeeded']);
    $timestamp = time() - 3600; // 1 hour ago
    $secret = 'whsec_test_fake123';
    $signedPayload = "$timestamp.$payload";
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    $request = Request::create('/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t=$timestamp,v1=$signature",
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    expect($this->driver->verifyWebhookSignature($request, $this->credentials))->toBeFalse();
});

test('stripe driver parse webhook gateway reference handles session', function () {
    $request = Request::create('/webhook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_123']]]));

    expect($this->driver->parseWebhookGatewayReference($request))->toBe('cs_test_123');
});

test('stripe driver parses webhook reference without pi prefix', function () {
    $request = Request::create('/webhook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_123', 'payment_intent' => 'pi_test_123']]]));

    expect($this->driver->parseWebhookGatewayReference($request))->toBe('pi_test_123');
});

test('stripe driver supports status query for plain session', function () {
    Http::fake([
        '*/v1/checkout/sessions/cs_test_abc123' => Http::response([
            'id' => 'cs_test_abc123',
            'status' => 'open',
        ]),
    ]);

    $payload = new CanonicalStatusPayload(
        paymentId: 'internal-id',
        gatewayReference: 'cs_test_abc123',
    );

    $result = $this->driver->status($payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->gatewayReference)->toBe('cs_test_abc123');
});

test('stripe driver update subscription returns correctly', function () {
    Http::fake([
        '*/v1/subscriptions/sub_test_789' => Http::response([
            'id' => 'sub_test_789',
            'status' => 'active',
        ]),
    ]);

    $result = $this->driver->updateSubscription('sub_test_789', ['metadata' => ['key' => 'value']], $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Completed)
        ->and($result->gatewayReference)->toBe('sub_test_789');
});

test('stripe driver retrieves subscription status', function () {
    Http::fake([
        '*/v1/subscriptions/sub_test_789' => Http::response([
            'id' => 'sub_test_789',
            'status' => 'past_due',
        ]),
    ]);

    $result = $this->driver->getSubscriptionStatus('sub_test_789', $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Processing)
        ->and($result->gatewayReference)->toBe('sub_test_789');
});

test('stripe driver list tokens', function () {
    Http::fake([
        '*/v1/payment_methods*' => Http::response([
            'data' => [
                ['id' => 'pm_123'],
                ['id' => 'pm_456'],
            ],
        ]),
    ]);

    $result = $this->driver->listTokens('cus_test_123', $this->credentials);
    expect($result)->toHaveCount(2)
        ->and($result[0]['id'])->toBe('pm_123');
});

test('stripe driver handles zero decimal currency', function () {
    $driver = new StripeDriver;

    // Use reflection to test the protected toStripeAmount method
    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('toStripeAmount');

    // JPY is zero decimal
    $amount = $method->invokeArgs($driver, [100.0, 'JPY']);
    expect($amount)->toBe(100);

    // USD is normal
    $amountUsd = $method->invokeArgs($driver, [10.50, 'USD']);
    expect($amountUsd)->toBe(1050);
});

test('stripe driver handles map payment intent status', function () {
    $driver = new StripeDriver;
    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('mapPaymentIntentStatus');

    expect($method->invokeArgs($driver, ['requires_action']))->toBe(PaymentStatus::Processing)
        ->and($method->invokeArgs($driver, ['canceled']))->toBe(PaymentStatus::Cancelled)
        ->and($method->invokeArgs($driver, ['unknown']))->toBe(PaymentStatus::Failed);
});

test('stripe driver handles map subscription status mapping past due', function () {
    $driver = new StripeDriver;
    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('mapSubscriptionStatus');

    expect($method->invokeArgs($driver, ['past_due']))->toBe(PaymentStatus::Processing)
        ->and($method->invokeArgs($driver, ['unpaid']))->toBe(PaymentStatus::Cancelled)
        ->and($method->invokeArgs($driver, ['trialing']))->toBe(PaymentStatus::Completed)
        ->and($method->invokeArgs($driver, ['incomplete_expired']))->toBe(PaymentStatus::Expired)
        ->and($method->invokeArgs($driver, ['unknown']))->toBe(PaymentStatus::Failed);
});

test('stripe driver handles map interval mapping', function () {
    $driver = new StripeDriver;
    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('mapInterval');

    expect($method->invokeArgs($driver, ['daily']))->toBe('day')
        ->and($method->invokeArgs($driver, ['weekly']))->toBe('week')
        ->and($method->invokeArgs($driver, ['monthly']))->toBe('month')
        ->and($method->invokeArgs($driver, ['annual']))->toBe('year')
        ->and($method->invokeArgs($driver, ['custom']))->toBe('custom');
});

test('stripe driver status handles session without payment intent', function () {
    Http::fake([
        '*/v1/checkout/sessions/cs_test_abc123' => Http::response([
            'id' => 'cs_test_abc123',
            'status' => 'expired',
        ]),
    ]);

    $payload = new CanonicalStatusPayload(
        paymentId: 'internal-id',
        gatewayReference: 'cs_test_abc123',
    );

    $result = $this->driver->status($payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Expired)
        ->and($result->gatewayReference)->toBe('cs_test_abc123');
});

test('stripe driver map session status handles unknown', function () {
    $driver = new StripeDriver;
    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('mapSessionStatus');

    expect($method->invokeArgs($driver, ['unknown']))->toBe(PaymentStatus::Failed);
});

test('stripe driver refund handles various statuses', function () {
    Http::fake([
        '*/v1/refunds' => Http::sequence()
            ->push(['id' => 're_1', 'status' => 'pending'])
            ->push(['id' => 're_2', 'status' => 'failed'])
            ->push(['id' => 're_3', 'status' => 'unknown']),
    ]);

    $payload = new CanonicalRefundPayload('pi_123', new MoneyDTO(10, 'USD'));

    $res1 = $this->driver->refund($payload, $this->credentials);
    expect($res1->status)->toBe(PaymentStatus::Processing);

    $res2 = $this->driver->refund($payload, $this->credentials);
    expect($res2->status)->toBe(PaymentStatus::Failed);

    $res3 = $this->driver->refund($payload, $this->credentials);
    expect($res3->status)->toBe(PaymentStatus::Failed);
});

test('stripe driver handles webhook with different event type', function () {
    $request = Request::create('/webhook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['type' => 'invoice.payment_succeeded', 'data' => ['object' => ['id' => 'in_123']]]));

    expect($this->driver->parseWebhookGatewayReference($request))->toBe('in_123');
});

test('stripe driver webhook verify returns false on empty header signature list', function () {
    // Tests line 160: empty parts array loop
    $request = Request::create('/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t=123", // Valid enough format to split by =, but no v1
        'CONTENT_TYPE' => 'application/json',
    ], 'payload');

    expect($this->driver->verifyWebhookSignature($request, $this->credentials))->toBeFalse();
});

test('stripe driver createSubscription handles coupon code', function () {
    // Tests line 321
    Http::fake([
        '*/v1/customers' => Http::response(['id' => 'cus_test_123']),
        '*/v1/prices' => Http::response(['id' => 'price_test_456']),
        '*/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_sub123',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_sub123',
        ]),
    ]);

    $payload = CanonicalSubscriptionPayload::fromArray([
        'plan' => [
            'id' => 'plan_pro',
            'name' => 'Pro Plan',
            'money' => ['amount' => 49.99, 'currency' => 'USD'],
            'interval' => 'monthly',
        ],
        'customer' => ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
        'urls' => ['return' => 'https://example.com/billing'],
        'coupon_code' => 'SAVE20',
    ]);

    $result = $this->driver->createSubscription($payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->gatewayReference)->toBe('cs_test_sub123');
});

test('stripe driver status mapping handles more edge cases', function () {
    $driver = new StripeDriver;
    $reflection = new ReflectionClass($driver);

    $methodPi = $reflection->getMethod('mapPaymentIntentStatus');
    expect($methodPi->invokeArgs($driver, ['requires_payment_method']))->toBe(PaymentStatus::Pending)
        ->and($methodPi->invokeArgs($driver, ['requires_capture']))->toBe(PaymentStatus::Processing);

    $methodSession = $reflection->getMethod('mapSessionStatus');
    expect($methodSession->invokeArgs($driver, ['complete']))->toBe(PaymentStatus::Completed)
        ->and($methodSession->invokeArgs($driver, ['expired']))->toBe(PaymentStatus::Expired);

    $methodSub = $reflection->getMethod('mapSubscriptionStatus');
    expect($methodSub->invokeArgs($driver, ['incomplete']))->toBe(PaymentStatus::Pending)
        ->and($methodSub->invokeArgs($driver, ['canceled']))->toBe(PaymentStatus::Cancelled)
        ->and($methodSub->invokeArgs($driver, ['paused']))->toBe(PaymentStatus::Processing);
});
