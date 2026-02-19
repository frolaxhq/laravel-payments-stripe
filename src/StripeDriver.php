<?php

declare(strict_types=1);

namespace Frolax\PaymentStripe;

use Frolax\Payment\Contracts\GatewayDriverContract;
use Frolax\Payment\Contracts\SupportsBankTransfer;
use Frolax\Payment\Contracts\SupportsBuyNowPayLater;
use Frolax\Payment\Contracts\SupportsHostedRedirect;
use Frolax\Payment\Contracts\SupportsPayout;
use Frolax\Payment\Contracts\SupportsRecurring;
use Frolax\Payment\Contracts\SupportsRefund;
use Frolax\Payment\Contracts\SupportsStatusQuery;
use Frolax\Payment\Contracts\SupportsThreeDSecure;
use Frolax\Payment\Contracts\SupportsTokenization;
use Frolax\Payment\Contracts\SupportsWallets;
use Frolax\Payment\Contracts\SupportsWebhookVerification;
use Frolax\Payment\DTOs\CanonicalPayload;
use Frolax\Payment\DTOs\CanonicalRefundPayload;
use Frolax\Payment\DTOs\CanonicalStatusPayload;
use Frolax\Payment\DTOs\CanonicalSubscriptionPayload;
use Frolax\Payment\DTOs\CredentialsDTO;
use Frolax\Payment\DTOs\GatewayResult;
use Frolax\Payment\Enums\PaymentStatus;
use Illuminate\Http\Request;

class StripeDriver implements GatewayDriverContract, SupportsBankTransfer, SupportsBuyNowPayLater, SupportsHostedRedirect, SupportsPayout, SupportsRecurring, SupportsRefund, SupportsStatusQuery, SupportsThreeDSecure, SupportsTokenization, SupportsWallets, SupportsWebhookVerification
{
    protected ?CredentialsDTO $credentials = null;

    // ─── Core GatewayDriverContract ──────────────────────────────────

    public function name(): string
    {
        return 'stripe';
    }

    public function setCredentials(CredentialsDTO $credentials): static
    {
        $this->credentials = $credentials;

        return $this;
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

    /**
     * Create a payment using Stripe Checkout Sessions.
     */
    public function create(CanonicalPayload $payload, CredentialsDTO $credentials): GatewayResult
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
     *
     * Retrieves the Checkout Session and its PaymentIntent to determine status.
     */
    public function verify(Request $request, CredentialsDTO $credentials): GatewayResult
    {
        $sessionId = $request->query('session_id');

        if (! $sessionId) {
            return new GatewayResult(
                status: PaymentStatus::Failed,
                errorMessage: 'Missing session_id in return URL',
            );
        }

        $client = $this->makeClient($credentials);
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

    // ─── SupportsHostedRedirect ──────────────────────────────────────

    public function getRedirectUrl(GatewayResult $result): ?string
    {
        return $result->redirectUrl;
    }

    // ─── SupportsWebhookVerification ─────────────────────────────────

    /**
     * Verify Stripe webhook signature using HMAC-SHA256.
     *
     * @see https://stripe.com/docs/webhooks/signatures
     */
    public function verifyWebhookSignature(Request $request, CredentialsDTO $credentials): bool
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

    public function parseWebhookEventType(Request $request): ?string
    {
        return $request->json('type');
    }

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

    // ─── SupportsRefund ──────────────────────────────────────────────

    public function refund(CanonicalRefundPayload $payload, CredentialsDTO $credentials): GatewayResult
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

    // ─── SupportsStatusQuery ─────────────────────────────────────────

    public function status(CanonicalStatusPayload $payload, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        $reference = $payload->gatewayReference ?? $payload->paymentId;

        // Determine if it's a session or payment intent
        if (str_starts_with($reference, 'cs_')) {
            $session = $client->retrieveSession($reference);
            $piId = $session['payment_intent'] ?? null;

            if ($piId) {
                $intent = $client->retrievePaymentIntent($piId);

                return new GatewayResult(
                    status: $this->mapPaymentIntentStatus($intent['status'] ?? ''),
                    gatewayReference: $piId,
                    gatewayResponse: $intent,
                );
            }

            return new GatewayResult(
                status: $this->mapSessionStatus($session['status'] ?? ''),
                gatewayReference: $reference,
                gatewayResponse: $session,
            );
        }

        $intent = $client->retrievePaymentIntent($reference);

        return new GatewayResult(
            status: $this->mapPaymentIntentStatus($intent['status'] ?? ''),
            gatewayReference: $reference,
            gatewayResponse: $intent,
        );
    }

    // ─── SupportsRecurring ───────────────────────────────────────────

    public function createSubscription(CanonicalSubscriptionPayload $payload, CredentialsDTO $credentials): GatewayResult
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
        $customerId = $customer['id'];

        // Create a price for the plan
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
            'customer' => $customerId,
            'items' => [
                ['price' => $price['id']],
            ],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription',
            ],
            'expand' => ['latest_invoice.payment_intent'],
            'metadata' => [
                'plan_id' => $payload->plan->id,
                'idempotency_key' => $payload->idempotencyKey,
            ],
        ];

        if ($payload->trialDays && $payload->trialDays > 0) {
            $params['trial_period_days'] = $payload->trialDays;
        }

        if ($payload->couponCode) {
            $params['coupon'] = $payload->couponCode;
        }

        $subscription = $client->createSubscription($params);

        // Get client_secret for frontend confirmation
        $clientSecret = $subscription['latest_invoice']['payment_intent']['client_secret'] ?? null;
        $redirectUrl = $payload->urls?->return;

        return new GatewayResult(
            status: $this->mapSubscriptionStatus($subscription['status'] ?? ''),
            gatewayReference: $subscription['id'],
            redirectUrl: $redirectUrl,
            gatewayResponse: $subscription,
            metadata: [
                'customer_id' => $customerId,
                'client_secret' => $clientSecret,
            ],
        );
    }

    public function cancelSubscription(string $subscriptionId, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);
        $subscription = $client->cancelSubscription($subscriptionId);

        return new GatewayResult(
            status: PaymentStatus::Cancelled,
            gatewayReference: $subscriptionId,
            gatewayResponse: $subscription,
        );
    }

    public function pauseSubscription(string $subscriptionId, CredentialsDTO $credentials): GatewayResult
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

    public function resumeSubscription(string $subscriptionId, CredentialsDTO $credentials): GatewayResult
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

    public function updateSubscription(string $subscriptionId, array $changes, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);
        $subscription = $client->updateSubscription($subscriptionId, $changes);

        return new GatewayResult(
            status: $this->mapSubscriptionStatus($subscription['status'] ?? ''),
            gatewayReference: $subscriptionId,
            gatewayResponse: $subscription,
        );
    }

    public function getSubscriptionStatus(string $subscriptionId, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);
        $subscription = $client->retrieveSubscription($subscriptionId);

        return new GatewayResult(
            status: $this->mapSubscriptionStatus($subscription['status'] ?? ''),
            gatewayReference: $subscriptionId,
            gatewayResponse: $subscription,
        );
    }

    // ─── SupportsTokenization ────────────────────────────────────────

    public function tokenize(CanonicalPayload $payload, CredentialsDTO $credentials): GatewayResult
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

    public function chargeToken(string $token, CanonicalPayload $payload, CredentialsDTO $credentials): GatewayResult
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

    public function deleteToken(string $token, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);
        $result = $client->detachPaymentMethod($token);

        return new GatewayResult(
            status: PaymentStatus::Completed,
            gatewayReference: $token,
            gatewayResponse: $result,
        );
    }

    public function listTokens(string $customerId, CredentialsDTO $credentials): array
    {
        $client = $this->makeClient($credentials);

        return $client->listPaymentMethods($customerId)['data'] ?? [];
    }

    // ─── SupportsPayout ──────────────────────────────────────────────

    public function createPayout(array $payoutData, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        // If destination is set, use Transfer (connected account), otherwise Payout (bank)
        if (isset($payoutData['destination'])) {
            $result = $client->createTransfer($payoutData);
        } else {
            $result = $client->createPayout($payoutData);
        }

        return new GatewayResult(
            status: match ($result['status'] ?? '') {
                'paid', 'pending' => PaymentStatus::Completed,
                'in_transit' => PaymentStatus::Processing,
                'canceled' => PaymentStatus::Cancelled,
                'failed' => PaymentStatus::Failed,
                default => PaymentStatus::Pending,
            },
            gatewayReference: $result['id'] ?? null,
            gatewayResponse: $result,
        );
    }

    public function splitPayment(array $splitRules, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        // Create a payment intent with transfer_data for the primary split
        $params = $splitRules;
        $intent = $client->createPaymentIntent($params);

        return new GatewayResult(
            status: $this->mapPaymentIntentStatus($intent['status'] ?? ''),
            gatewayReference: $intent['id'],
            gatewayResponse: $intent,
        );
    }

    public function getPayoutStatus(string $payoutId, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        // Determine if payout or transfer
        if (str_starts_with($payoutId, 'tr_')) {
            $result = $client->get("/v1/transfers/{$payoutId}");
        } else {
            $result = $client->retrievePayout($payoutId);
        }

        return new GatewayResult(
            status: match ($result['status'] ?? 'paid') {
                'paid', 'pending' => PaymentStatus::Completed,
                'in_transit' => PaymentStatus::Processing,
                'canceled' => PaymentStatus::Cancelled,
                'failed' => PaymentStatus::Failed,
                default => PaymentStatus::Pending,
            },
            gatewayReference: $payoutId,
            gatewayResponse: $result,
        );
    }

    // ─── SupportsThreeDSecure ────────────────────────────────────────

    public function initiate3DS(CanonicalPayload $payload, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        $params = [
            'amount' => $this->toStripeAmount($payload->money->amount, $payload->money->currency),
            'currency' => strtolower($payload->money->currency),
            'payment_method_types' => ['card'],
            'payment_method_options' => [
                'card' => [
                    'request_three_d_secure' => 'any',
                ],
            ],
            'confirm' => 'false',
            'metadata' => [
                'order_id' => $payload->order->id,
            ],
        ];

        if ($payload->urls?->return) {
            $params['return_url'] = $payload->urls->return;
        }

        $intent = $client->createPaymentIntent($params);

        return new GatewayResult(
            status: $this->mapPaymentIntentStatus($intent['status'] ?? ''),
            gatewayReference: $intent['id'],
            redirectUrl: $intent['next_action']['redirect_to_url']['url'] ?? null,
            gatewayResponse: $intent,
            metadata: [
                'client_secret' => $intent['client_secret'] ?? null,
            ],
        );
    }

    public function verify3DS(Request $request, CredentialsDTO $credentials): GatewayResult
    {
        $paymentIntentId = $request->query('payment_intent');

        if (! $paymentIntentId) {
            return new GatewayResult(
                status: PaymentStatus::Failed,
                errorMessage: 'Missing payment_intent parameter',
            );
        }

        $client = $this->makeClient($credentials);
        $intent = $client->retrievePaymentIntent($paymentIntentId);

        return new GatewayResult(
            status: $this->mapPaymentIntentStatus($intent['status'] ?? ''),
            gatewayReference: $paymentIntentId,
            gatewayResponse: $intent,
        );
    }

    // ─── SupportsWallets ─────────────────────────────────────────────

    public function createWalletCharge(CanonicalPayload $payload, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        $walletTypes = $payload->extra['wallet_types'] ?? ['card', 'apple_pay', 'google_pay'];

        $session = $client->createCheckoutSession([
            'mode' => 'payment',
            'payment_method_types' => $walletTypes,
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
            'success_url' => $payload->urls?->return ?? url('/payments/stripe/return'),
            'cancel_url' => $payload->urls?->cancel ?? url('/payments/stripe/cancel'),
            'metadata' => [
                'order_id' => $payload->order->id,
                'wallet_payment' => 'true',
            ],
        ]);

        return new GatewayResult(
            status: PaymentStatus::Pending,
            gatewayReference: $session['id'],
            redirectUrl: $session['url'] ?? null,
            gatewayResponse: $session,
        );
    }

    public function getWalletBalance(string $walletId, CredentialsDTO $credentials): GatewayResult
    {
        // Stripe doesn't expose wallet balance; return metadata about the payment method
        $client = $this->makeClient($credentials);
        $methods = $client->listPaymentMethods($walletId);

        return new GatewayResult(
            status: PaymentStatus::Completed,
            gatewayReference: $walletId,
            gatewayResponse: $methods,
        );
    }

    // ─── SupportsBankTransfer ────────────────────────────────────────

    public function initiateBankTransfer(CanonicalPayload $payload, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        // Create customer for bank transfer
        $customerParams = [];
        if ($payload->customer?->email) {
            $customerParams['email'] = $payload->customer->email;
        }
        if ($payload->customer?->name) {
            $customerParams['name'] = $payload->customer->name;
        }
        $customer = $client->createCustomer($customerParams);

        $intent = $client->createPaymentIntent([
            'amount' => $this->toStripeAmount($payload->money->amount, $payload->money->currency),
            'currency' => strtolower($payload->money->currency),
            'customer' => $customer['id'],
            'payment_method_types' => ['customer_balance'],
            'payment_method_data' => [
                'type' => 'customer_balance',
            ],
            'payment_method_options' => [
                'customer_balance' => [
                    'funding_type' => 'bank_transfer',
                    'bank_transfer' => [
                        'type' => $payload->extra['bank_transfer_type'] ?? 'us_bank_transfer',
                    ],
                ],
            ],
            'metadata' => [
                'order_id' => $payload->order->id,
            ],
        ]);

        return new GatewayResult(
            status: $this->mapPaymentIntentStatus($intent['status'] ?? ''),
            gatewayReference: $intent['id'],
            gatewayResponse: $intent,
            metadata: [
                'customer_id' => $customer['id'],
                'bank_instructions' => $intent['next_action']['display_bank_transfer_instructions'] ?? null,
            ],
        );
    }

    public function verifyBankTransfer(string $transferReference, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);
        $intent = $client->retrievePaymentIntent($transferReference);

        return new GatewayResult(
            status: $this->mapPaymentIntentStatus($intent['status'] ?? ''),
            gatewayReference: $transferReference,
            gatewayResponse: $intent,
        );
    }

    // ─── SupportsBuyNowPayLater ──────────────────────────────────────

    public function createBNPLSession(CanonicalPayload $payload, CredentialsDTO $credentials): GatewayResult
    {
        $client = $this->makeClient($credentials);

        $bnplTypes = $payload->extra['bnpl_types'] ?? ['klarna', 'afterpay_clearpay'];

        $session = $client->createCheckoutSession([
            'mode' => 'payment',
            'payment_method_types' => $bnplTypes,
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
            'success_url' => $payload->urls?->return ?? url('/payments/stripe/return'),
            'cancel_url' => $payload->urls?->cancel ?? url('/payments/stripe/cancel'),
            'metadata' => [
                'order_id' => $payload->order->id,
                'bnpl_payment' => 'true',
            ],
        ]);

        return new GatewayResult(
            status: PaymentStatus::Pending,
            gatewayReference: $session['id'],
            redirectUrl: $session['url'] ?? null,
            gatewayResponse: $session,
        );
    }

    public function getBNPLPlans(CanonicalPayload $payload, CredentialsDTO $credentials): array
    {
        // Stripe doesn't have a direct "list plans" API for BNPL.
        // Return the supported BNPL method types for the given currency.
        $currency = strtolower($payload->money->currency);

        $plans = [];

        if (in_array($currency, ['usd', 'gbp', 'eur', 'aud', 'cad', 'nzd', 'sek', 'dkk', 'nok', 'chf', 'pln', 'czk'])) {
            $plans[] = [
                'id' => 'klarna',
                'name' => 'Klarna',
                'description' => 'Pay later or in installments with Klarna',
                'payment_method_type' => 'klarna',
            ];
        }

        if (in_array($currency, ['usd', 'gbp', 'aud', 'nzd', 'cad'])) {
            $plans[] = [
                'id' => 'afterpay_clearpay',
                'name' => 'Afterpay / Clearpay',
                'description' => 'Pay in 4 installments',
                'payment_method_type' => 'afterpay_clearpay',
            ];
        }

        return $plans;
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    protected function makeClient(CredentialsDTO $credentials): StripeClient
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

    protected function mapSessionStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'complete' => PaymentStatus::Completed,
            'open' => PaymentStatus::Pending,
            'expired' => PaymentStatus::Expired,
            default => PaymentStatus::Failed,
        };
    }

    protected function mapSubscriptionStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'active' => PaymentStatus::Completed,
            'trialing' => PaymentStatus::Completed,
            'incomplete' => PaymentStatus::Pending,
            'incomplete_expired' => PaymentStatus::Expired,
            'past_due' => PaymentStatus::Processing,
            'canceled', 'unpaid' => PaymentStatus::Cancelled,
            'paused' => PaymentStatus::Processing,
            default => PaymentStatus::Failed,
        };
    }

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
}
