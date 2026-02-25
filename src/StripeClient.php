<?php

declare(strict_types=1);

namespace Frolax\PaymentStripe;

use Frolax\Payment\Data\Credentials;
use Frolax\Payment\Exceptions\GatewayRequestFailedException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class StripeClient
{
    protected string $baseUrl;

    protected string $secretKey;

    protected string $apiVersion;

    public function __construct(
        protected Credentials $credentials,
    ) {
        $this->baseUrl = config('payments.gateways.stripe.base_url', 'https://api.stripe.com');
        $this->secretKey = $credentials->get('secret_key', '');
        $this->apiVersion = config('payments.gateways.stripe.api_version', '2024-12-18.acacia');
    }

    // ─── Checkout Sessions ───────────────────────────────────────────

    public function createCheckoutSession(array $params): array
    {
        return $this->post('/v1/checkout/sessions', $params);
    }

    public function retrieveSession(string $sessionId): array
    {
        return $this->get("/v1/checkout/sessions/{$sessionId}");
    }

    // ─── Payment Intents ─────────────────────────────────────────────

    public function createPaymentIntent(array $params): array
    {
        return $this->post('/v1/payment_intents', $params);
    }

    public function retrievePaymentIntent(string $intentId): array
    {
        return $this->get("/v1/payment_intents/{$intentId}");
    }

    public function confirmPaymentIntent(string $intentId, array $params = []): array
    {
        return $this->post("/v1/payment_intents/{$intentId}/confirm", $params);
    }

    // ─── Refunds ─────────────────────────────────────────────────────

    public function createRefund(array $params): array
    {
        return $this->post('/v1/refunds', $params);
    }

    // ─── Customers ───────────────────────────────────────────────────

    public function createCustomer(array $params): array
    {
        return $this->post('/v1/customers', $params);
    }

    // ─── Subscriptions ───────────────────────────────────────────────

    public function createSubscription(array $params): array
    {
        return $this->post('/v1/subscriptions', $params);
    }

    public function retrieveSubscription(string $subscriptionId): array
    {
        return $this->get("/v1/subscriptions/{$subscriptionId}");
    }

    public function updateSubscription(string $subscriptionId, array $params): array
    {
        return $this->post("/v1/subscriptions/{$subscriptionId}", $params);
    }

    public function cancelSubscription(string $subscriptionId, array $params = []): array
    {
        return $this->delete("/v1/subscriptions/{$subscriptionId}", $params);
    }

    // ─── Setup Intents (Tokenization) ────────────────────────────────

    public function createSetupIntent(array $params): array
    {
        return $this->post('/v1/setup_intents', $params);
    }

    public function retrieveSetupIntent(string $intentId): array
    {
        return $this->get("/v1/setup_intents/{$intentId}");
    }

    // ─── Payment Methods ─────────────────────────────────────────────

    public function listPaymentMethods(string $customerId, string $type = 'card'): array
    {
        return $this->get('/v1/payment_methods', [
            'customer' => $customerId,
            'type' => $type,
        ]);
    }

    public function detachPaymentMethod(string $paymentMethodId): array
    {
        return $this->post("/v1/payment_methods/{$paymentMethodId}/detach");
    }

    // ─── Payouts ─────────────────────────────────────────────────────

    public function createPayout(array $params): array
    {
        return $this->post('/v1/payouts', $params);
    }

    public function retrievePayout(string $payoutId): array
    {
        return $this->get("/v1/payouts/{$payoutId}");
    }

    public function createTransfer(array $params): array
    {
        return $this->post('/v1/transfers', $params);
    }

    // ─── Billing Portal ─────────────────────────────────────────────

    public function createBillingPortalSession(array $params): array
    {
        return $this->post('/v1/billing_portal/sessions', $params);
    }

    // ─── Products & Prices (for Subscriptions) ───────────────────────

    public function createPrice(array $params): array
    {
        return $this->post('/v1/prices', $params);
    }

    // ─── HTTP Helpers ────────────────────────────────────────────────

    protected function request(): PendingRequest
    {
        return Http::withBasicAuth($this->secretKey, '')
            ->asForm()
            ->acceptJson()
            ->withHeaders([
                'Stripe-Version' => $this->apiVersion,
            ])
            ->timeout(30);
    }

    protected function post(string $path, array $params = []): array
    {
        $response = $this->request()->post("{$this->baseUrl}{$path}", $params);

        if ($response->failed()) {
            $error = $response->json('error') ?? [];
            throw new GatewayRequestFailedException(
                gateway: 'stripe',
                message: $error['message'] ?? "Stripe API error ({$response->status()})",
                response: $response->json() ?? [],
            );
        }

        return $response->json();
    }

    protected function get(string $path, array $query = []): array
    {
        $response = $this->request()->get("{$this->baseUrl}{$path}", $query);

        if ($response->failed()) {
            $error = $response->json('error') ?? [];
            throw new GatewayRequestFailedException(
                gateway: 'stripe',
                message: $error['message'] ?? "Stripe API error ({$response->status()})",
                response: $response->json() ?? [],
            );
        }

        return $response->json();
    }

    protected function delete(string $path, array $params = []): array
    {
        $response = $this->request()->delete("{$this->baseUrl}{$path}", $params);

        if ($response->failed()) {
            $error = $response->json('error') ?? [];
            throw new GatewayRequestFailedException(
                gateway: 'stripe',
                message: $error['message'] ?? "Stripe API error ({$response->status()})",
                response: $response->json() ?? [],
            );
        }

        return $response->json();
    }
}
