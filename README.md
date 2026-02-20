# Stripe Payment Gateway

Stripe payment gateway addon for `frolaxhq/laravel-payments` with full feature support across 11 capability interfaces.

## Overview

- **Gateway Key:** `stripe`
- **Capabilities:** redirect, webhook, refund, status_query, recurring, tokenization, payout, three_d_secure, wallets, bank_transfer, buy_now_pay_later
- **Profiles:** test (sandbox), live (production)

## Installation

```bash
composer require frolaxhq/payment-stripe
```

## Configuration

### Environment Variables

```dotenv
# Test (Sandbox)
STRIPE_TEST_SECRET_KEY=sk_test_...
STRIPE_TEST_PUBLISHABLE_KEY=pk_test_...
STRIPE_TEST_WEBHOOK_SECRET=whsec_...

# Live (Production)
STRIPE_LIVE_SECRET_KEY=sk_live_...
STRIPE_LIVE_PUBLISHABLE_KEY=pk_live_...
STRIPE_LIVE_WEBHOOK_SECRET=whsec_...
```

### Credentials

| Key | Required | Description |
|-----|----------|-------------|
| `secret_key` | ✅ | Stripe Secret Key (`sk_test_...` or `sk_live_...`) |
| `publishable_key` | ✅ | Stripe Publishable Key (`pk_test_...`) |
| `webhook_secret` | ✅ | Webhook signing secret (`whsec_...`) |

## Usage

### Create a Payment (Checkout Session)

```php
use Frolax\Payment\Facades\Payment;

$result = Payment::gateway('stripe')->create([
    'order' => ['id' => 'ORD-123', 'description' => 'Premium Plan'],
    'money' => ['amount' => 29.99, 'currency' => 'USD'],
    'customer' => ['email' => 'john@example.com'],
    'urls' => [
        'return' => route('payments.return', 'stripe') . '?session_id={CHECKOUT_SESSION_ID}',
        'cancel' => route('payments.cancel', 'stripe'),
    ],
]);

if ($result->requiresRedirect()) {
    return redirect($result->redirectUrl);
}
```

### Verify on Return

```php
$result = Payment::gateway('stripe')->verify($request);
// $result->gatewayReference = PaymentIntent ID (pi_...)
```

### Refund

```php
$result = Payment::gateway('stripe')->refund([
    'payment_id' => 'pi_...', // PaymentIntent ID
    'money' => ['amount' => 29.99, 'currency' => 'USD'],
    'reason' => 'Customer requested',
]);
```

### Query Payment Status

```php
$result = Payment::gateway('stripe')->status([
    'payment_id' => 'internal-id',
    'gateway_reference' => 'pi_...', // or cs_... for session
]);
```

### Subscriptions

```php
// Create
$result = Payment::gateway('stripe')->subscribe([
    'plan' => [
        'id' => 'plan_pro',
        'name' => 'Pro Plan',
        'money' => ['amount' => 49.99, 'currency' => 'USD'],
        'interval' => 'monthly',
        'trial_days' => 14,
    ],
    'customer' => ['name' => 'Jane', 'email' => 'jane@example.com'],
]);

// Lifecycle
Payment::gateway('stripe')->cancelSubscription($subId);
Payment::gateway('stripe')->pauseSubscription($subId);
Payment::gateway('stripe')->resumeSubscription($subId);
Payment::gateway('stripe')->getSubscriptionStatus($subId);
```

### Tokenization (Save Payment Method)

```php
// Create SetupIntent → returns client_secret for frontend
$result = Payment::gateway('stripe')->tokenize([...]);
$clientSecret = $result->metadata['client_secret'];

// Charge a saved payment method
$result = Payment::gateway('stripe')->chargeToken('pm_...', [
    'order' => ['id' => 'ORD-456'],
    'money' => ['amount' => 15.00, 'currency' => 'USD'],
    'extra' => ['customer_id' => 'cus_...'],
]);

// Delete saved method
Payment::gateway('stripe')->deleteToken('pm_...');

// List saved methods
$methods = Payment::gateway('stripe')->listTokens('cus_...');
```

### Payouts

```php
// Bank payout
$result = Payment::gateway('stripe')->createPayout([
    'amount' => 10000, // in cents
    'currency' => 'usd',
]);

// Transfer to connected account
$result = Payment::gateway('stripe')->createPayout([
    'amount' => 5000,
    'currency' => 'usd',
    'destination' => 'acct_connected_123',
]);
```

### 3D Secure

```php
$result = Payment::gateway('stripe')->initiate3DS([
    'order' => ['id' => 'ORD-3DS'],
    'money' => ['amount' => 100, 'currency' => 'USD'],
    'urls' => ['return' => route('payments.3ds.return')],
]);
// Use client_secret on frontend for Stripe.js 3DS confirmation
```

### Wallets (Apple Pay / Google Pay)

```php
$result = Payment::gateway('stripe')->createWalletCharge([
    'order' => ['id' => 'ORD-W-001', 'description' => 'Wallet Payment'],
    'money' => ['amount' => 50, 'currency' => 'USD'],
    'urls' => ['return' => '...', 'cancel' => '...'],
    'extra' => ['wallet_types' => ['card', 'apple_pay', 'google_pay']],
]);
```

### Bank Transfer

```php
$result = Payment::gateway('stripe')->initiateBankTransfer([
    'order' => ['id' => 'ORD-BT'],
    'money' => ['amount' => 200, 'currency' => 'USD'],
    'customer' => ['email' => 'john@example.com'],
    'extra' => ['bank_transfer_type' => 'us_bank_transfer'],
]);
// Bank instructions available at $result->metadata['bank_instructions']
```

### Buy Now Pay Later (Klarna / Afterpay)

```php
$result = Payment::gateway('stripe')->createBNPLSession([
    'order' => ['id' => 'ORD-BNPL', 'description' => 'BNPL Order'],
    'money' => ['amount' => 150, 'currency' => 'USD'],
    'urls' => ['return' => '...', 'cancel' => '...'],
    'extra' => ['bnpl_types' => ['klarna', 'afterpay_clearpay']],
]);
```

## Webhooks

Stripe uses HMAC-SHA256 webhook signature verification.

### Setup

1. Go to [Stripe Dashboard > Developers > Webhooks](https://dashboard.stripe.com/webhooks)
2. Add your webhook URL: `https://yourdomain.com/payments/stripe/webhook`
3. Copy the signing secret (`whsec_...`) to your env
4. Subscribe to events: `payment_intent.succeeded`, `payment_intent.payment_failed`, `checkout.session.completed`, `invoice.paid`, etc.

## Stripe API Reference

| Capability | Stripe API | Endpoint |
|-----------|-----------:|---------|
| Payments | Checkout Sessions | `POST /v1/checkout/sessions` |
| Verify | PaymentIntents | `GET /v1/payment_intents/{id}` |
| Refund | Refunds | `POST /v1/refunds` |
| Subscriptions | Subscriptions | `POST /v1/subscriptions` |
| Tokenization | SetupIntents | `POST /v1/setup_intents` |
| Payouts | Payouts / Transfers | `POST /v1/payouts`, `POST /v1/transfers` |
| 3D Secure | PaymentIntents | Card `request_three_d_secure` option |
| Wallets | Checkout Sessions | `payment_method_types: ['apple_pay']` |
| Bank Transfer | PaymentIntents | `payment_method_types: ['customer_balance']` |
| BNPL | Checkout Sessions | `payment_method_types: ['klarna']` |

For full API docs, visit [stripe.com/docs/api](https://stripe.com/docs/api).
