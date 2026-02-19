<?php

uses(Tests\TestCase::class);

use Frolax\Payment\DTOs\CanonicalPayload;
use Frolax\Payment\DTOs\CanonicalRefundPayload;
use Frolax\Payment\DTOs\CanonicalStatusPayload;
use Frolax\Payment\DTOs\CanonicalSubscriptionPayload;
use Frolax\Payment\DTOs\CredentialsDTO;
use Frolax\Payment\DTOs\GatewayResult;
use Frolax\Payment\DTOs\MoneyDTO;
use Frolax\Payment\Enums\PaymentStatus;
use Frolax\PaymentStripe\StripeDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->driver = new StripeDriver;
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

// ─── Core ────────────────────────────────────────────────────────────

test('stripe driver returns correct name', function () {
    expect($this->driver->name())->toBe('stripe');
});

test('stripe driver reports all capabilities', function () {
    expect($this->driver->capabilities())
        ->toBeArray()
        ->toContain('redirect', 'webhook', 'refund', 'status_query', 'recurring', 'tokenization', 'payout', 'three_d_secure', 'wallets', 'bank_transfer', 'buy_now_pay_later');
});

// ─── Create Payment ──────────────────────────────────────────────────

test('stripe driver creates a checkout session and returns redirect url', function () {
    Http::fake([
        '*/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_abc123',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc123',
            'payment_intent' => 'pi_test_def456',
            'status' => 'open',
        ]),
    ]);

    $payload = CanonicalPayload::fromArray([
        'idempotency_key' => 'test-key-001',
        'order' => ['id' => 'ORD-123', 'description' => 'Premium Plan'],
        'money' => ['amount' => 29.99, 'currency' => 'USD'],
        'customer' => ['email' => 'john@example.com'],
        'urls' => [
            'return' => 'https://example.com/return',
            'cancel' => 'https://example.com/cancel',
        ],
    ]);

    $result = $this->driver->create($payload, $this->credentials);

    expect($result)
        ->toBeInstanceOf(GatewayResult::class)
        ->and($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->gatewayReference)->toBe('cs_test_abc123')
        ->and($result->redirectUrl)->toStartWith('https://checkout.stripe.com/')
        ->and($result->requiresRedirect())->toBeTrue();
});

// ─── Verify Payment ─────────────────────────────────────────────────

test('stripe driver verifies a successful payment from callback', function () {
    Http::fake([
        '*/v1/checkout/sessions/cs_test_abc123' => Http::response([
            'id' => 'cs_test_abc123',
            'payment_intent' => 'pi_test_def456',
            'status' => 'complete',
        ]),
        '*/v1/payment_intents/pi_test_def456' => Http::response([
            'id' => 'pi_test_def456',
            'status' => 'succeeded',
            'amount' => 2999,
            'currency' => 'usd',
        ]),
    ]);

    $request = Request::create('/return', 'GET', ['session_id' => 'cs_test_abc123']);
    $result = $this->driver->verify($request, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Completed)
        ->and($result->gatewayReference)->toBe('pi_test_def456')
        ->and($result->isSuccessful())->toBeTrue();
});

test('stripe driver handles missing session_id in verify', function () {
    $request = Request::create('/return', 'GET');
    $result = $this->driver->verify($request, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->errorMessage)->toContain('Missing session_id');
});

// ─── Refund ──────────────────────────────────────────────────────────

test('stripe driver can process a refund', function () {
    Http::fake([
        '*/v1/refunds' => Http::response([
            'id' => 're_test_xyz789',
            'object' => 'refund',
            'status' => 'succeeded',
            'amount' => 2999,
            'payment_intent' => 'pi_test_def456',
        ]),
    ]);

    $payload = new CanonicalRefundPayload(
        paymentId: 'pi_test_def456',
        money: new MoneyDTO(29.99, 'USD'),
        reason: 'Customer requested refund',
    );

    $result = $this->driver->refund($payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Refunded)
        ->and($result->gatewayReference)->toBe('re_test_xyz789');
});

// ─── Status Query ────────────────────────────────────────────────────

test('stripe driver can query payment intent status', function () {
    Http::fake([
        '*/v1/payment_intents/pi_test_def456' => Http::response([
            'id' => 'pi_test_def456',
            'status' => 'succeeded',
            'amount' => 2999,
        ]),
    ]);

    $payload = new CanonicalStatusPayload(
        paymentId: 'internal-id',
        gatewayReference: 'pi_test_def456',
    );

    $result = $this->driver->status($payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Completed)
        ->and($result->gatewayReference)->toBe('pi_test_def456');
});

test('stripe driver can query checkout session status', function () {
    Http::fake([
        '*/v1/checkout/sessions/cs_test_abc123' => Http::response([
            'id' => 'cs_test_abc123',
            'payment_intent' => 'pi_test_def456',
            'status' => 'complete',
        ]),
        '*/v1/payment_intents/pi_test_def456' => Http::response([
            'id' => 'pi_test_def456',
            'status' => 'succeeded',
        ]),
    ]);

    $payload = new CanonicalStatusPayload(
        paymentId: 'internal-id',
        gatewayReference: 'cs_test_abc123',
    );

    $result = $this->driver->status($payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Completed)
        ->and($result->gatewayReference)->toBe('pi_test_def456');
});

// ─── Webhook ─────────────────────────────────────────────────────────

test('stripe driver verifies valid webhook signature', function () {
    $payload = json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_test_def456']]]);
    $timestamp = time();
    $secret = 'whsec_test_fake123';
    $signedPayload = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    $request = Request::create('/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    expect($this->driver->verifyWebhookSignature($request, $this->credentials))->toBeTrue();
});

test('stripe driver rejects invalid webhook signature', function () {
    $payload = json_encode(['type' => 'payment_intent.succeeded']);
    $timestamp = time();

    $request = Request::create('/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=invalid_signature",
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    expect($this->driver->verifyWebhookSignature($request, $this->credentials))->toBeFalse();
});

test('stripe driver parses webhook event type', function () {
    $request = Request::create('/webhook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_test_def456']]]));

    expect($this->driver->parseWebhookEventType($request))->toBe('payment_intent.succeeded')
        ->and($this->driver->parseWebhookGatewayReference($request))->toBe('pi_test_def456');
});

// ─── Subscriptions ───────────────────────────────────────────────────

test('stripe driver creates a subscription', function () {
    Http::fake([
        '*/v1/customers' => Http::response(['id' => 'cus_test_123']),
        '*/v1/prices' => Http::response(['id' => 'price_test_456']),
        '*/v1/subscriptions' => Http::response([
            'id' => 'sub_test_789',
            'status' => 'active',
            'latest_invoice' => [
                'payment_intent' => ['client_secret' => 'pi_secret_test'],
            ],
        ]),
    ]);

    $payload = CanonicalSubscriptionPayload::fromArray([
        'plan' => [
            'id' => 'plan_pro',
            'name' => 'Pro Plan',
            'money' => ['amount' => 49.99, 'currency' => 'USD'],
            'interval' => 'monthly',
            'trial_days' => 14,
        ],
        'customer' => ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
        'urls' => ['return' => 'https://example.com/billing'],
    ]);

    $result = $this->driver->createSubscription($payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Completed)
        ->and($result->gatewayReference)->toBe('sub_test_789')
        ->and($result->metadata['customer_id'])->toBe('cus_test_123');
});

test('stripe driver cancels a subscription', function () {
    Http::fake([
        '*/v1/subscriptions/sub_test_789' => Http::response([
            'id' => 'sub_test_789',
            'status' => 'canceled',
        ]),
    ]);

    $result = $this->driver->cancelSubscription('sub_test_789', $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Cancelled)
        ->and($result->gatewayReference)->toBe('sub_test_789');
});

test('stripe driver pauses a subscription', function () {
    Http::fake([
        '*/v1/subscriptions/sub_test_789' => Http::response([
            'id' => 'sub_test_789',
            'status' => 'active',
            'pause_collection' => ['behavior' => 'mark_uncollectible'],
        ]),
    ]);

    $result = $this->driver->pauseSubscription('sub_test_789', $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Processing)
        ->and($result->metadata['paused'])->toBeTrue();
});

test('stripe driver resumes a subscription', function () {
    Http::fake([
        '*/v1/subscriptions/sub_test_789' => Http::response([
            'id' => 'sub_test_789',
            'status' => 'active',
        ]),
    ]);

    $result = $this->driver->resumeSubscription('sub_test_789', $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Completed)
        ->and($result->metadata['paused'])->toBeFalse();
});

// ─── Tokenization ────────────────────────────────────────────────────

test('stripe driver creates a setup intent for tokenization', function () {
    Http::fake([
        '*/v1/customers' => Http::response(['id' => 'cus_test_123']),
        '*/v1/setup_intents' => Http::response([
            'id' => 'seti_test_abc',
            'client_secret' => 'seti_test_abc_secret_xyz',
            'status' => 'requires_payment_method',
        ]),
    ]);

    $payload = CanonicalPayload::fromArray([
        'idempotency_key' => 'token-key-001',
        'order' => ['id' => 'ORD-TOKEN-001'],
        'money' => ['amount' => 0, 'currency' => 'USD'],
        'customer' => ['email' => 'john@example.com'],
    ]);

    $result = $this->driver->tokenize($payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->gatewayReference)->toBe('seti_test_abc')
        ->and($result->metadata['client_secret'])->toBe('seti_test_abc_secret_xyz');
});

test('stripe driver charges a saved token', function () {
    Http::fake([
        '*/v1/payment_intents' => Http::response([
            'id' => 'pi_test_charged',
            'status' => 'succeeded',
            'amount' => 1500,
        ]),
    ]);

    $payload = CanonicalPayload::fromArray([
        'idempotency_key' => 'charge-key-001',
        'order' => ['id' => 'ORD-CHARGE-001'],
        'money' => ['amount' => 15.00, 'currency' => 'USD'],
        'extra' => ['customer_id' => 'cus_test_123'],
    ]);

    $result = $this->driver->chargeToken('pm_test_card', $payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Completed)
        ->and($result->gatewayReference)->toBe('pi_test_charged');
});

test('stripe driver deletes a payment method', function () {
    Http::fake([
        '*/v1/payment_methods/pm_test_card/detach' => Http::response([
            'id' => 'pm_test_card',
            'object' => 'payment_method',
        ]),
    ]);

    $result = $this->driver->deleteToken('pm_test_card', $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Completed);
});

// ─── Payout ──────────────────────────────────────────────────────────

test('stripe driver creates a payout', function () {
    Http::fake([
        '*/v1/payouts' => Http::response([
            'id' => 'po_test_abc',
            'status' => 'paid',
            'amount' => 10000,
        ]),
    ]);

    $result = $this->driver->createPayout([
        'amount' => 10000,
        'currency' => 'usd',
    ], $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Completed)
        ->and($result->gatewayReference)->toBe('po_test_abc');
});

test('stripe driver creates a transfer to connected account', function () {
    Http::fake([
        '*/v1/transfers' => Http::response([
            'id' => 'tr_test_xyz',
            'object' => 'transfer',
            'amount' => 5000,
        ]),
    ]);

    $result = $this->driver->createPayout([
        'amount' => 5000,
        'currency' => 'usd',
        'destination' => 'acct_connected_123',
    ], $this->credentials);

    expect($result->gatewayReference)->toBe('tr_test_xyz');
});

// ─── 3D Secure ───────────────────────────────────────────────────────

test('stripe driver initiates 3DS payment', function () {
    Http::fake([
        '*/v1/payment_intents' => Http::response([
            'id' => 'pi_test_3ds',
            'status' => 'requires_action',
            'client_secret' => 'pi_test_3ds_secret',
            'next_action' => [
                'redirect_to_url' => ['url' => 'https://stripe.com/3ds-redirect'],
            ],
        ]),
    ]);

    $payload = CanonicalPayload::fromArray([
        'idempotency_key' => '3ds-key',
        'order' => ['id' => 'ORD-3DS-001'],
        'money' => ['amount' => 100, 'currency' => 'USD'],
        'urls' => ['return' => 'https://example.com/return'],
    ]);

    $result = $this->driver->initiate3DS($payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Processing)
        ->and($result->redirectUrl)->toBe('https://stripe.com/3ds-redirect')
        ->and($result->metadata['client_secret'])->toBe('pi_test_3ds_secret');
});

// ─── Wallets ─────────────────────────────────────────────────────────

test('stripe driver creates a wallet charge session', function () {
    Http::fake([
        '*/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_wallet',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_wallet',
            'status' => 'open',
        ]),
    ]);

    $payload = CanonicalPayload::fromArray([
        'idempotency_key' => 'wallet-key',
        'order' => ['id' => 'ORD-WALLET-001', 'description' => 'Wallet Payment'],
        'money' => ['amount' => 50, 'currency' => 'USD'],
        'urls' => ['return' => 'https://example.com/return', 'cancel' => 'https://example.com/cancel'],
        'extra' => ['wallet_types' => ['card', 'apple_pay', 'google_pay']],
    ]);

    $result = $this->driver->createWalletCharge($payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->gatewayReference)->toBe('cs_test_wallet')
        ->and($result->redirectUrl)->toStartWith('https://checkout.stripe.com/');
});

// ─── Bank Transfer ───────────────────────────────────────────────────

test('stripe driver initiates a bank transfer', function () {
    Http::fake([
        '*/v1/customers' => Http::response(['id' => 'cus_test_bt']),
        '*/v1/payment_intents' => Http::response([
            'id' => 'pi_test_bt',
            'status' => 'requires_action',
            'next_action' => [
                'display_bank_transfer_instructions' => [
                    'type' => 'us_bank_transfer',
                    'reference' => 'REF123',
                ],
            ],
        ]),
    ]);

    $payload = CanonicalPayload::fromArray([
        'idempotency_key' => 'bt-key',
        'order' => ['id' => 'ORD-BT-001'],
        'money' => ['amount' => 200, 'currency' => 'USD'],
        'customer' => ['email' => 'john@example.com'],
    ]);

    $result = $this->driver->initiateBankTransfer($payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Processing)
        ->and($result->gatewayReference)->toBe('pi_test_bt')
        ->and($result->metadata['bank_instructions'])->not->toBeNull();
});

// ─── Buy Now Pay Later ──────────────────────────────────────────────

test('stripe driver creates a BNPL session', function () {
    Http::fake([
        '*/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_bnpl',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_bnpl',
            'status' => 'open',
        ]),
    ]);

    $payload = CanonicalPayload::fromArray([
        'idempotency_key' => 'bnpl-key',
        'order' => ['id' => 'ORD-BNPL-001', 'description' => 'BNPL Order'],
        'money' => ['amount' => 150, 'currency' => 'USD'],
        'urls' => ['return' => 'https://example.com/return', 'cancel' => 'https://example.com/cancel'],
        'extra' => ['bnpl_types' => ['klarna', 'afterpay_clearpay']],
    ]);

    $result = $this->driver->createBNPLSession($payload, $this->credentials);

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->gatewayReference)->toBe('cs_test_bnpl')
        ->and($result->redirectUrl)->toStartWith('https://checkout.stripe.com/');
});

test('stripe driver returns available BNPL plans', function () {
    $payload = CanonicalPayload::fromArray([
        'idempotency_key' => 'plans-key',
        'order' => ['id' => 'ORD-PLANS'],
        'money' => ['amount' => 100, 'currency' => 'USD'],
    ]);

    $plans = $this->driver->getBNPLPlans($payload, $this->credentials);

    expect($plans)->toBeArray()
        ->and(count($plans))->toBeGreaterThanOrEqual(1);

    $ids = array_column($plans, 'id');
    expect($ids)->toContain('klarna');
    expect($ids)->toContain('afterpay_clearpay');
});
