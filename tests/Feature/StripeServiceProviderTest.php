<?php

use Frolax\PaymentStripe\StripeGatewayAddon;
use Frolax\PaymentStripe\StripeServiceProvider;

test('service provider returns correct gateway addon instance', function () {
    // The constructor is protected on ServiceProvider, create a mock or extend it
    $app = app();
    $provider = new class($app) extends StripeServiceProvider {
        public function getAddon() {
            return $this->gatewayAddon();
        }
    };

    expect($provider->getAddon())->toBeInstanceOf(StripeGatewayAddon::class);
});

test('service provider registers config', function () {
    $app = app();
    $provider = new StripeServiceProvider($app);
    $provider->register();

    expect(config('payments.gateways.stripe.base_url'))->toBe('https://api.stripe.com');
});
