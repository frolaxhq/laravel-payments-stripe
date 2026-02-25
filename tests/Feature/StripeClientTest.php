<?php

use Frolax\Payment\Data\Credentials;
use Frolax\Payment\Exceptions\GatewayRequestFailedException;
use Frolax\PaymentStripe\StripeClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->credentials = new Credentials(
        gateway: 'stripe',
        profile: 'test',
        credentials: [
            'secret_key' => 'sk_test_fake123',
            'publishable_key' => 'pk_test_fake123',
            'webhook_secret' => 'whsec_test_fake123',
        ],
    );

    $this->apiClient = new StripeClient($this->credentials);
});

test('client can confirm payment intent', function () {
    Http::fake([
        '*/v1/payment_intents/*/confirm' => Http::response(['id' => 'pi_123', 'status' => 'succeeded']),
    ]);

    $result = $this->apiClient->confirmPaymentIntent('pi_123', ['payment_method' => 'pm_123']);
    expect($result['id'])->toBe('pi_123');
});

test('client can retrieve setup intent', function () {
    Http::fake([
        '*/v1/setup_intents/*' => Http::response(['id' => 'seti_123']),
    ]);

    $result = $this->apiClient->retrieveSetupIntent('seti_123');
    expect($result['id'])->toBe('seti_123');
});

test('client can create payout', function () {
    Http::fake([
        '*/v1/payouts' => Http::response(['id' => 'po_123']),
    ]);

    $result = $this->apiClient->createPayout(['amount' => 1000, 'currency' => 'usd']);
    expect($result['id'])->toBe('po_123');
});

test('client can retrieve payout', function () {
    Http::fake([
        '*/v1/payouts/*' => Http::response(['id' => 'po_123']),
    ]);

    $result = $this->apiClient->retrievePayout('po_123');
    expect($result['id'])->toBe('po_123');
});

test('client can create transfer', function () {
    Http::fake([
        '*/v1/transfers' => Http::response(['id' => 'tr_123']),
    ]);

    $result = $this->apiClient->createTransfer(['amount' => 1000, 'currency' => 'usd', 'destination' => 'acct_123']);
    expect($result['id'])->toBe('tr_123');
});

test('client handles gateway request failed exception on get', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'Not found']], 404),
    ]);

    expect(fn () => $this->apiClient->retrieveSession('cs_invalid'))
        ->toThrow(GatewayRequestFailedException::class, 'Not found');
});

test('client handles gateway request failed exception on post', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'Bad request']], 400),
    ]);

    expect(fn () => $this->apiClient->createCheckoutSession([]))
        ->toThrow(GatewayRequestFailedException::class, 'Bad request');
});

test('client handles gateway request failed exception on delete', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'Cannot delete']], 403),
    ]);

    expect(fn () => $this->apiClient->cancelSubscription('sub_invalid'))
        ->toThrow(GatewayRequestFailedException::class, 'Cannot delete');
});

test('client can retrieve payment intent', function () {
    Http::fake([
        '*/v1/payment_intents/*' => Http::response(['id' => 'pi_123']),
    ]);

    $result = $this->apiClient->retrievePaymentIntent('pi_123');
    expect($result['id'])->toBe('pi_123');
});

test('client can create customer', function () {
    Http::fake([
        '*/v1/customers' => Http::response(['id' => 'cus_123']),
    ]);

    $result = $this->apiClient->createCustomer(['email' => 'test@example.com']);
    expect($result['id'])->toBe('cus_123');
});

test('client can retrieve subscription', function () {
    Http::fake([
        '*/v1/subscriptions/*' => Http::response(['id' => 'sub_123']),
    ]);

    $result = $this->apiClient->retrieveSubscription('sub_123');
    expect($result['id'])->toBe('sub_123');
});

test('client can update subscription', function () {
    Http::fake([
        '*/v1/subscriptions/*' => Http::response(['id' => 'sub_123']),
    ]);

    $result = $this->apiClient->updateSubscription('sub_123', ['metadata' => ['key' => 'value']]);
    expect($result['id'])->toBe('sub_123');
});

test('client can create setup intent', function () {
    Http::fake([
        '*/v1/setup_intents' => Http::response(['id' => 'seti_123']),
    ]);

    $result = $this->apiClient->createSetupIntent(['customer' => 'cus_123']);
    expect($result['id'])->toBe('seti_123');
});

test('client can list payment methods', function () {
    Http::fake([
        '*/v1/payment_methods*' => Http::response(['data' => [['id' => 'pm_123']]]),
    ]);

    $result = $this->apiClient->listPaymentMethods('cus_123');
    expect($result['data'][0]['id'])->toBe('pm_123');
});

test('client can create price', function () {
    Http::fake([
        '*/v1/prices' => Http::response(['id' => 'price_123']),
    ]);

    $result = $this->apiClient->createPrice(['unit_amount' => 1000]);
    expect($result['id'])->toBe('price_123');
});

test('client can create subscription', function () {
    Http::fake([
        '*/v1/subscriptions' => Http::response(['id' => 'sub_123']),
    ]);

    $result = $this->apiClient->createSubscription(['customer' => 'cus_123']);
    expect($result['id'])->toBe('sub_123');
});
