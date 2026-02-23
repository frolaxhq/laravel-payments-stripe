<?php

declare(strict_types=1);

namespace Frolax\PaymentStripe;

use Frolax\Payment\Contracts\GatewayAddonContract;
use Frolax\Payment\Contracts\SupportsHostedRedirect;
use Frolax\Payment\Contracts\SupportsRecurring;
use Frolax\Payment\Contracts\SupportsRefund;
use Frolax\Payment\Contracts\SupportsStatusQuery;
use Frolax\Payment\Contracts\SupportsTokenization;
use Frolax\Payment\Contracts\SupportsWebhookVerification;

class StripeGatewayAddon implements GatewayAddonContract
{
    public function gatewayKey(): string
    {
        return 'stripe';
    }

    public function displayName(): string
    {
        return 'Stripe';
    }

    public function driverClass(): string|callable
    {
        return StripeDriver::class;
    }

    public function capabilities(): array
    {
        return [
            SupportsHostedRedirect::class,
            SupportsWebhookVerification::class,
            SupportsRefund::class,
            SupportsStatusQuery::class,
            SupportsRecurring::class,
            SupportsTokenization::class,
        ];
    }

    public function credentialSchema(): array
    {
        return [
            'secret_key' => 'required',
            'publishable_key' => 'required',
            'webhook_secret' => 'required',
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'base_url' => 'https://api.stripe.com',
            'api_version' => '2024-12-18.acacia',
        ];
    }
}
