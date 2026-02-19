<?php

declare(strict_types=1);

namespace Frolax\PaymentStripe;

use Frolax\Payment\Contracts\GatewayAddonContract;
use Frolax\Payment\Discovery\GatewayAddonServiceProvider;

class StripeServiceProvider extends GatewayAddonServiceProvider
{
    public function gatewayAddon(): GatewayAddonContract
    {
        return new StripeGatewayAddon;
    }

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(
            __DIR__.'/../config/payment-stripe.php',
            'payments.gateways.stripe',
        );
    }
}
