<?php

declare(strict_types=1);

namespace Frolax\PaymentStripe;

use Frolax\Payment\Contracts\GatewayDriverContract;
use Frolax\Payment\Contracts\SupportsBillingPortal;
use Frolax\Payment\Contracts\SupportsHostedRedirect;
use Frolax\Payment\Contracts\SupportsRecurring;
use Frolax\Payment\Contracts\SupportsRefund;
use Frolax\Payment\Contracts\SupportsStatusQuery;
use Frolax\Payment\Contracts\SupportsTokenization;
use Frolax\Payment\Contracts\SupportsWebhookVerification;
use Frolax\Payment\Data\Money;
use Frolax\Payment\Data\SubscriptionPayload;
use Frolax\Payment\Data\Credentials;
use Frolax\Payment\Data\GatewayResult;
use Frolax\Payment\Data\Payload;
use Frolax\Payment\Data\RefundPayload;
use Frolax\Payment\Data\StatusPayload;
use Frolax\Payment\Data\WebhookData;
use Frolax\Payment\Enums\PaymentStatus;
use Frolax\Payment\Enums\SubscriptionStatus;
use Frolax\Payment\Enums\WebhookEventType;
use Illuminate\Http\Request;

class StripeDriver implements GatewayDriverContract, SupportsBillingPortal, SupportsHostedRedirect, SupportsRecurring, SupportsRefund, SupportsStatusQuery, SupportsTokenization, SupportsWebhookVerification
{
    protected ?Credentials $credentials = null;

    /**
     * Get the name of the gateway.
     */
    public function name(): string
    {
        return 'stripe';
    }

    /**
     * Set the credentials to be used.
     *
     * @return StripeDriver
     */
    public function setCredentials(Credentials $credentials): static
    {
        $this->credentials = $credentials;

        return $this;
    }

    /**
     * Create a payment using Stripe Checkout Sessions.
     */
    public function create(Payload $payload, Credentials $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        $params = [
            'mode' => 'payment',
            'success_url' => $payload->urls?->return ?? url('/payments/stripe/return'),
            'cancel_url' => $payload->urls?->cancel ?? url('/payments/stripe/cancel'),
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => strtolower($payload->money->currency),
                        'unit_amount' => $this->toStripeAmount($payload->money->amount, $payload->money->currency),
                        'product_data' => [
                            'name' => $payload->order->description ?? $payload->order->id,
                        ],
                    ],
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'order_id' => $payload->order->id,
                'idempotency_key' => $payload->idempotencyKey,
            ],
        ];

        if ($payload->customer?->email) {
            $params['customer_email'] = $payload->customer->email;
        }

        $session = $client->createCheckoutSession($params);

        return new GatewayResult(
            status: PaymentStatus::Pending,
            gatewayReference: $session['id'],
            redirectUrl: $session['url'] ?? null,
            gatewayResponse: $session,
            metadata: [
                'payment_intent' => $session['payment_intent'] ?? null,
            ],
        );
    }

    /**
     * Verify a payment from a Stripe callback/return.
     * Retrieves the Checkout Session and its PaymentIntent to determine status.
     */
    public function verify(Request $request, Credentials $credentials): GatewayResult
    {
        $sessionId = $request->query('session_id');

        if (! $sessionId) {
            return new GatewayResult(
                status: PaymentStatus::Failed,
                errorMessage: 'Missing session_id in return URL',
            );
        }

        $client = $this->makeClient($credentials);

        return $this->retrieveSessionOrPaymentIntent($client, $sessionId);
    }

    /**
     * Get the redirect URL for hosted redirect payments.
     */
    public function getRedirectUrl(GatewayResult $result): ?string
    {
        return $result->redirectUrl;
    }

    /**
     * Verify Stripe webhook signature using HMAC-SHA256.
     *
     * @see https://stripe.com/docs/webhooks/signatures
     */
    public function verifyWebhookSignature(Request $request, Credentials $credentials): bool
    {
        $signature = $request->header('Stripe-Signature');
        $payload = $request->getContent();
        $secret = $credentials->get('webhook_secret');

        if (! $signature || ! $secret) {
            return false;
        }

        // Parse the Stripe-Signature header
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = explode('=', trim($part), 2);
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? null;
        $expectedSignature = $parts['v1'] ?? null;

        if (! $timestamp || ! $expectedSignature) {
            return false;
        }

        // Reject signatures older than 5 minutes
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $signedPayload = "{$timestamp}.{$payload}";
        $computedSignature = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($computedSignature, $expectedSignature);
    }

    /**
     * Parse the webhook event type from a request.
     */
    public function parseWebhookEventType(Request $request): ?string
    {
        return $request->json('type');
    }

    /**
     * Parse the gateway reference from a webhook request.
     */
    public function parseWebhookGatewayReference(Request $request): ?string
    {
        $data = $request->json('data.object');

        // For payment_intent events, the PI ID is the reference
        if (isset($data['id']) && str_starts_with($data['id'], 'pi_')) {
            return $data['id'];
        }

        // For checkout.session events, return the payment_intent
        return $data['payment_intent'] ?? $data['id'] ?? null;
    }

    /**
     * Parse the webhook request into a canonical WebhookData DTO.
     */
    public function parseWebhookData(Request $request): WebhookData
    {
        $type = $request->json('type');
        $object = $request->json('data.object') ?? [];

        $canonicalEvent = match ($type) {
            'payment_intent.succeeded' => WebhookEventType::PaymentCompleted,
            'payment_intent.payment_failed' => WebhookEventType::PaymentFailed,
            'payment_intent.created', 'payment_intent.processing' => WebhookEventType::PaymentPending,
            'checkout.session.completed' => WebhookEventType::PaymentCompleted,
            'checkout.session.expired' => WebhookEventType::PaymentExpired,
            'charge.refunded' => WebhookEventType::PaymentRefunded,
            'customer.subscription.created' => WebhookEventType::SubscriptionCreated,
            'customer.subscription.updated' => $this->mapStripeSubscriptionUpdate($object),
            'customer.subscription.deleted' => WebhookEventType::SubscriptionCancelled,
            'customer.subscription.paused' => WebhookEventType::SubscriptionPaused,
            'customer.subscription.resumed' => WebhookEventType::SubscriptionResumed,
            'customer.subscription.trial_will_end' => WebhookEventType::SubscriptionTrialEnding,
            'invoice.paid' => WebhookEventType::InvoicePaid,
            'invoice.payment_failed' => WebhookEventType::InvoiceFailed,
            'charge.dispute.created' => WebhookEventType::DisputeCreated,
            default => WebhookEventType::Unknown,
        };

        // Extract amount from object
        $amount = null;
        if (isset($object['amount'])) {
            $currency = strtoupper($object['currency'] ?? 'USD');
            $amount = new Money(
                amount: $this->fromStripeAmount((int) $object['amount'], $currency),
                currency: $currency,
            );
        } elseif (isset($object['amount_total'])) {
            $currency = strtoupper($object['currency'] ?? 'USD');
            $amount = new Money(
                amount: $this->fromStripeAmount((int) $object['amount_total'], $currency),
                currency: $currency,
            );
        }

        // Map subscription status if applicable
        $subscriptionStatus = null;
        if (isset($object['status']) && str_starts_with($type ?? '', 'customer.subscription.')) {
            $subscriptionStatus = match ($object['status']) {
                'active' => SubscriptionStatus::Active,
                'trialing' => SubscriptionStatus::Trialing,
                'past_due' => SubscriptionStatus::PastDue,
                'paused' => SubscriptionStatus::Paused,
                'canceled', 'cancelled' => SubscriptionStatus::Cancelled,
                'incomplete' => SubscriptionStatus::Incomplete,
                'incomplete_expired' => SubscriptionStatus::Expired,
                default => null,
            };
        }

        return new WebhookData(
            canonicalEvent: $canonicalEvent,
            gateway: 'stripe',
            gatewayEventType: $type,
            gatewayReference: $object['id'] ?? null,
            paymentId: $object['payment_intent'] ?? (str_starts_with($object['id'] ?? '', 'pi_') ? $object['id'] : null),
            subscriptionId: str_starts_with($object['id'] ?? '', 'sub_') ? $object['id'] : ($object['subscription'] ?? null),
            customerId: $object['customer'] ?? null,
            invoiceId: str_starts_with($object['id'] ?? '', 'in_') ? $object['id'] : ($object['invoice'] ?? null),
            refundId: str_starts_with($object['id'] ?? '', 're_') ? $object['id'] : null,
            amount: $amount,
            paymentStatus: $canonicalEvent->isPaymentEvent() ? $this->mapPaymentIntentStatus($object['status'] ?? '') : null,
            subscriptionStatus: $subscriptionStatus,
            metadata: $object['metadata'] ?? [],
            rawPayload: $request->all(),
        );
    }

    /**
     * Map a Stripe subscription update event to a canonical event type.
     */
    protected function mapStripeSubscriptionUpdate(array $object): WebhookEventType
    {
        return match ($object['status'] ?? '') {
            'active' => WebhookEventType::SubscriptionActivated,
            'canceled' => WebhookEventType::SubscriptionCancelled,
            'paused' => WebhookEventType::SubscriptionPaused,
            default => WebhookEventType::SubscriptionActivated,
        };
    }

    /**
     * Convert from Stripe's smallest currency unit back to decimal.
     */
    protected function fromStripeAmount(int $amount, string $currency): float
    {
        $zeroDecimalCurrencies = [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ];

        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            return (float) $amount;
        }

        return $amount / 100;
    }

    /**
     * Create a billing portal session for a customer.
     */
    public function createBillingPortalSession(
        string $customerId,
        Credentials $credentials,
        ?string $returnUrl = null,
    ): GatewayResult {
        $client = $this->makeClient($credentials);

        $params = [
            'customer' => $customerId,
        ];

        if ($returnUrl) {
            $params['return_url'] = $returnUrl;
        }

        $session = $client->createBillingPortalSession($params);

        return new GatewayResult(
            status: PaymentStatus::Completed,
            gatewayReference: $session['id'] ?? null,
            redirectUrl: $session['url'] ?? null,
            gatewayResponse: $session,
        );
    }

    /**
     * Process a refund for a specific payment.
     */
    public function refund(RefundPayload $payload, Credentials $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        $params = [
            'payment_intent' => $payload->paymentId,
            'amount' => $this->toStripeAmount($payload->money->amount, $payload->money->currency),
        ];

        if ($payload->reason) {
            $params['reason'] = 'requested_by_customer';
            $params['metadata'] = ['reason' => $payload->reason];
        }

        $refund = $client->createRefund($params);

        $status = match ($refund['status'] ?? '') {
            'succeeded' => PaymentStatus::Refunded,
            'pending' => PaymentStatus::Processing,
            'failed' => PaymentStatus::Failed,
            default => PaymentStatus::Failed,
        };

        return new GatewayResult(
            status: $status,
            gatewayReference: $refund['id'] ?? null,
            gatewayResponse: $refund,
        );
    }

    /**
     * Query the status of a specific payment.
     */
    public function status(StatusPayload $payload, Credentials $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        $reference = $payload->gatewayReference ?? $payload->paymentId;

        // Determine if it's a session or payment intent
        if (str_starts_with($reference, 'cs_')) {
            return $this->retrieveSessionOrPaymentIntent($client, $reference);
        }

        $intent = $client->retrievePaymentIntent($reference);

        return new GatewayResult(
            status: $this->mapPaymentIntentStatus($intent['status'] ?? ''),
            gatewayReference: $reference,
            gatewayResponse: $intent,
        );
    }

    /**
     * Create a new subscription for a customer.
     */
    public function createSubscription(SubscriptionPayload $payload, Credentials $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        // Create or reference customer
        $customerParams = [];
        if ($payload->customer?->email) {
            $customerParams['email'] = $payload->customer->email;
        }
        if ($payload->customer?->name) {
            $customerParams['name'] = $payload->customer->name;
        }

        $customer = $client->createCustomer($customerParams);

        // Create a price for the plan (or use an existing price ID if applicable)
        $price = $client->createPrice([
            'unit_amount' => $this->toStripeAmount($payload->plan->money->amount, $payload->plan->money->currency),
            'currency' => strtolower($payload->plan->money->currency),
            'recurring' => [
                'interval' => $this->mapInterval($payload->plan->interval),
                'interval_count' => $payload->plan->intervalCount,
            ],
            'product_data' => [
                'name' => $payload->plan->name,
            ],
        ]);

        $params = [
            'mode' => 'subscription',
            'customer' => $customer['id'],
            'line_items' => [
                [
                    'price' => $price['id'],
                    'quantity' => $payload->quantity ?? 1,
                ],
            ],
            'success_url' => $payload->urls?->return ?? url('/dashboard'),
            'cancel_url' => $payload->urls?->cancel ?? url('/pricing'),
            'metadata' => [
                'plan_id' => $payload->plan->priceId,
                'idempotency_key' => $payload->idempotencyKey,
            ],
            'subscription_data' => [
                'metadata' => [
                    'plan_id' => $payload->plan->priceId,
                    'idempotency_key' => $payload->idempotencyKey,
                ],
            ],
        ];

        if ($payload->trialDays && $payload->trialDays > 0) {
            $params['subscription_data']['trial_period_days'] = $payload->trialDays;
        }

        if ($payload->couponCode) {
            $params['discounts'] = [['coupon' => $payload->couponCode]];
        }

        $session = $client->createCheckoutSession($params);

        return new GatewayResult(
            status: PaymentStatus::Pending,
            gatewayReference: $session['id'],
            redirectUrl: $session['url'] ?? null,
            gatewayResponse: $session,
            metadata: [
                'customer_id' => $customer['id'],
            ],
        );
    }

    /**
     * Cancel an active subscription.
     */
    public function cancelSubscription(string $subscriptionId, Credentials $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);
        $subscription = $client->cancelSubscription($subscriptionId);

        return new GatewayResult(
            status: PaymentStatus::Cancelled,
            gatewayReference: $subscriptionId,
            gatewayResponse: $subscription,
        );
    }

    /**
     * Pause an active subscription collection.
     */
    public function pauseSubscription(string $subscriptionId, Credentials $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);
        $subscription = $client->updateSubscription($subscriptionId, [
            'pause_collection' => ['behavior' => 'mark_uncollectible'],
        ]);

        return new GatewayResult(
            status: PaymentStatus::Processing,
            gatewayReference: $subscriptionId,
            gatewayResponse: $subscription,
            metadata: ['paused' => true],
        );
    }

    /**
     * Resume a paused subscription.
     */
    public function resumeSubscription(string $subscriptionId, Credentials $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);
        $subscription = $client->updateSubscription($subscriptionId, [
            'pause_collection' => '',
        ]);

        return new GatewayResult(
            status: $this->mapSubscriptionStatus($subscription['status'] ?? ''),
            gatewayReference: $subscriptionId,
            gatewayResponse: $subscription,
            metadata: ['paused' => false],
        );
    }

    /**
     * Update an active subscription's properties.
     */
    public function updateSubscription(string $subscriptionId, array $changes, Credentials $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);
        $subscription = $client->updateSubscription($subscriptionId, $changes);

        return new GatewayResult(
            status: $this->mapSubscriptionStatus($subscription['status'] ?? ''),
            gatewayReference: $subscriptionId,
            gatewayResponse: $subscription,
        );
    }

    /**
     * Retrieve the current status of a subscription.
     */
    public function getSubscriptionStatus(string $subscriptionId, Credentials $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);
        $subscription = $client->retrieveSubscription($subscriptionId);

        return new GatewayResult(
            status: $this->mapSubscriptionStatus($subscription['status'] ?? ''),
            gatewayReference: $subscriptionId,
            gatewayResponse: $subscription,
        );
    }

    /**
     * Tokenize a payment method for off-session usage via a SetupIntent.
     */
    public function tokenize(Payload $payload, Credentials $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        $params = [
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
            'metadata' => [
                'order_id' => $payload->order->id,
            ],
        ];

        if ($payload->customer?->email) {
            $customer = $client->createCustomer([
                'email' => $payload->customer->email,
                'name' => $payload->customer?->name,
            ]);
            $params['customer'] = $customer['id'];
        }

        $setupIntent = $client->createSetupIntent($params);

        return new GatewayResult(
            status: PaymentStatus::Pending,
            gatewayReference: $setupIntent['id'],
            gatewayResponse: $setupIntent,
            metadata: [
                'client_secret' => $setupIntent['client_secret'] ?? null,
            ],
        );
    }

    /**
     * Charge a previously tokenized payment method.
     */
    public function chargeToken(string $token, Payload $payload, Credentials $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        $params = [
            'amount' => $this->toStripeAmount($payload->money->amount, $payload->money->currency),
            'currency' => strtolower($payload->money->currency),
            'payment_method' => $token,
            'confirm' => 'true',
            'off_session' => 'true',
            'metadata' => [
                'order_id' => $payload->order->id,
            ],
        ];

        // Look for customer ID in extra or metadata
        $customerId = $payload->extra['customer_id'] ?? $payload->metadata['customer_id'] ?? null;
        if ($customerId) {
            $params['customer'] = $customerId;
        }

        $intent = $client->createPaymentIntent($params);

        return new GatewayResult(
            status: $this->mapPaymentIntentStatus($intent['status'] ?? ''),
            gatewayReference: $intent['id'],
            gatewayResponse: $intent,
        );
    }

    /**
     * Remove a saved payment method token.
     */
    public function deleteToken(string $token, Credentials $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);
        $result = $client->detachPaymentMethod($token);

        return new GatewayResult(
            status: PaymentStatus::Completed,
            gatewayReference: $token,
            gatewayResponse: $result,
        );
    }

    /**
     * List all payment methods saved for a specific customer.
     */
    public function listTokens(string $customerId, Credentials $credentials): array
    {
        $client = $this->makeClient($credentials);

        return $client->listPaymentMethods($customerId)['data'] ?? [];
    }

    /**
     * Create a new StripeClient instance.
     */
    protected function makeClient(Credentials $credentials): StripeClient
    {
        return new StripeClient($credentials);
    }

    /**
     * Convert a decimal amount to Stripe's smallest currency unit (e.g. cents).
     */
    protected function toStripeAmount(float|int $amount, string $currency): int
    {
        $zeroDecimalCurrencies = [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ];

        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            return (int) $amount;
        }

        return (int) round($amount * 100);
    }

    /**
     * Map a Stripe PaymentIntent status to our internal PaymentStatus enum.
     */
    protected function mapPaymentIntentStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'succeeded' => PaymentStatus::Completed,
            'requires_payment_method', 'requires_confirmation' => PaymentStatus::Pending,
            'requires_action', 'processing', 'requires_capture' => PaymentStatus::Processing,
            'canceled' => PaymentStatus::Cancelled,
            default => PaymentStatus::Failed,
        };
    }

    /**
     * Map a Stripe Checkout Session status to our internal PaymentStatus enum.
     */
    protected function mapSessionStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'complete' => PaymentStatus::Completed,
            'open' => PaymentStatus::Pending,
            'expired' => PaymentStatus::Expired,
            default => PaymentStatus::Failed,
        };
    }

    /**
     * Map a Stripe Subscription status to our internal PaymentStatus enum.
     */
    protected function mapSubscriptionStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'active', 'trialing' => PaymentStatus::Completed,
            'incomplete' => PaymentStatus::Pending,
            'incomplete_expired' => PaymentStatus::Expired,
            'past_due', 'paused' => PaymentStatus::Processing,
            'canceled', 'unpaid' => PaymentStatus::Cancelled,
            default => PaymentStatus::Failed,
        };
    }

    /**
     * Map Stripe interval to internal interval.
     */
    protected function mapInterval(string $interval): string
    {
        return match (strtolower($interval)) {
            'daily', 'day' => 'day',
            'weekly', 'week' => 'week',
            'monthly', 'month' => 'month',
            'yearly', 'year', 'annual', 'annually' => 'year',
            default => $interval,
        };
    }

    /**
     * Retrieve a Stripe Session and map it to a GatewayResult.
     * Extracts nested PaymentIntent status if available.
     */
    protected function retrieveSessionOrPaymentIntent(StripeClient $client, array|string $sessionId): GatewayResult
    {
        $session = $client->retrieveSession($sessionId);

        $paymentIntentId = $session['payment_intent'] ?? null;

        if ($paymentIntentId) {
            $intent = $client->retrievePaymentIntent($paymentIntentId);

            return new GatewayResult(
                status: $this->mapPaymentIntentStatus($intent['status'] ?? ''),
                gatewayReference: $paymentIntentId,
                gatewayResponse: $intent,
            );
        }

        return new GatewayResult(
            status: $this->mapSessionStatus($session['status'] ?? ''),
            gatewayReference: $sessionId,
            gatewayResponse: $session,
        );
    }
}
