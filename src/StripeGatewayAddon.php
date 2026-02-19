<?php

declare(strict_types=1);

namespace Frolax\PaymentStripe;

use Frolax\Payment\Contracts\GatewayAddonContract;

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
            'redirect',
            'webhook',
            'refund',
            'status_query',
            'recurring',
            'tokenization',
            'payout',
            'three_d_secure',
            'wallets',
            'bank_transfer',
            'buy_now_pay_later',
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
