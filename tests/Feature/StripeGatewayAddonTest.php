<?php

use Frolax\PaymentStripe\StripeDriver;
use Frolax\PaymentStripe\StripeGatewayAddon;
use Frolax\Payment\Contracts\SupportsHostedRedirect;
use Frolax\Payment\Contracts\SupportsRecurring;
use Frolax\Payment\Contracts\SupportsRefund;
use Frolax\Payment\Contracts\SupportsStatusQuery;
use Frolax\Payment\Contracts\SupportsTokenization;
use Frolax\Payment\Contracts\SupportsWebhookVerification;

test('addon returns correct gateway key', function () {
    $addon = new StripeGatewayAddon;
    expect($addon->gatewayKey())->toBe('stripe');
});

test('addon returns correct display name', function () {
    $addon = new StripeGatewayAddon;
    expect($addon->displayName())->toBe('Stripe');
});

test('addon returns correct driver class', function () {
    $addon = new StripeGatewayAddon;
    expect($addon->driverClass())->toBe(StripeDriver::class);
});

test('addon returns all supported capabilities', function () {
    $addon = new StripeGatewayAddon;
    expect($addon->capabilities())->toBe([
        SupportsHostedRedirect::class,
        SupportsWebhookVerification::class,
        SupportsRefund::class,
        SupportsStatusQuery::class,
        SupportsRecurring::class,
        SupportsTokenization::class,
    ]);
});

test('addon returns correct credential schema', function () {
    $addon = new StripeGatewayAddon;
    expect($addon->credentialSchema())->toBe([
        'secret_key' => 'required',
        'publishable_key' => 'required',
        'webhook_secret' => 'required',
    ]);
});

test('addon returns correct default config', function () {
    $addon = new StripeGatewayAddon;
    expect($addon->defaultConfig())->toBe([
        'base_url' => 'https://api.stripe.com',
        'api_version' => '2024-12-18.acacia',
    ]);
});
