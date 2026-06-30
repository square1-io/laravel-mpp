![Tests](https://github.com/square1-io/laravel-mpp/actions/workflows/tests.yml/badge.svg)

# Laravel MPP

Charge AI agents for access to Laravel routes with the [Machine Payments Protocol (MPP)](https://mpp.dev).

`square1-io/laravel-mpp` returns a `402 Payment Required` challenge for protected routes. A capable agent pays the challenge, retries the request, and receives the response with a payment receipt. You choose the price per route, or issue a metered session where one payment grants multiple accesses.

The package includes two payment rails:

- [Stripe](https://stripe.com) Shared Payment Tokens ([SPTs](https://docs.stripe.com/agentic-commerce/concepts/shared-payment-tokens)), settled as [PaymentIntents](https://docs.stripe.com/payments/payment-intents).
- [Tempo](https://tempo.xyz) pathUSD, paid by the stock [`npx mppx`](https://mpp.dev) client.

## Readiness

The Laravel middleware, signed challenges, receipts, metered sessions, and storage drivers are designed for production use.

The bundled Stripe rail depends on Stripe Shared Payment Tokens, which currently use preview APIs. Use it for test-mode development, demos, and Stripe-approved pilot or live flows. Expect API shape, Dashboard behavior, and buyer-wallet availability to change while Stripe's agentic-commerce APIs are in preview. Test mode will work globally, but live acceptance is currently gated to US-only companies (June 26).

The bundled Tempo rail targets Tempo testnet pathUSD and the stock `mppx` client. Treat it as testnet integration support unless you have a separate mainnet deployment plan.

```php
Route::get('/resource', MyPaidResource::class)
    ->middleware('mpp:0.50,USD');

#[RequiresPayment(amount: '5.00', currency: 'USD', grants: 10, scope: 'report.basic')]
public function report()
{
    // One $5 payment grants 10 accesses.
}
```

## Contents

- [Readiness](#readiness)
- [Installation](#installation)
- [Quickstart](#quickstart)
- [Choose a Payment Rail](#choose-a-payment-rail)
- [Protecting Routes](#protecting-routes)
- [Metered Access](#metered-access)
- [Session Storage](#session-storage)
- [Configuration](#configuration)
- [Testing](#testing)
- [Advanced Usage](#advanced-usage)
- [License](#license)

## Installation

Requires PHP 8.4 and Laravel 12 or 13.

```bash
composer require square1-io/laravel-mpp
```

Publish the config:

```bash
php artisan vendor:publish --tag=mpp-config
```

The database session store keeps metered credit balances in a table. Publish and run its migration only if you use that driver (see [Session Storage](#session-storage)):

```bash
php artisan vendor:publish --tag=mpp-migrations
php artisan migrate
```

The package registers the `mpp` middleware alias automatically. No `bootstrap/app.php` changes are required.

By default, challenge signing uses a key derived from `APP_KEY`. Set `MPP_CHALLENGE_SECRET` in production if you want to rotate the MPP signing key independently. Rotating it invalidates only in-flight `402` challenges, not issued sessions.

```dotenv
MPP_SESSION_DRIVER=cache
MPP_CHALLENGE_SECRET=
```

## Quickstart

This example uses Stripe test mode, transacting directly with a Shared Payment Token. Test mode works wherever your Stripe account is based. As of June 2026, live acceptance is gated to US-based accounts, including the [Link](https://link.com) buyer wallet, so the test-mode flow below is the broadly supported test path today.

Add your Stripe test secret key:

```dotenv
STRIPE_SECRET_KEY=sk_test_...
```

Protect a route:

```php
use Illuminate\Support\Facades\Route;

Route::get('/resource', fn () => response()->json(['result' => 'SOME_DATA']))
    ->middleware('mpp:1.00,USD');
```

Hit the route without payment:

```bash
curl -si https://your-host/resource
```

The response is a signed `402 Payment Required` challenge:

```json
{
  "type": "https://paymentauth.org/problems/payment-required",
  "title": "Payment Required",
  "status": 402,
  "challengeId": "chal_...",
  "accepts": [
    {
      "method": "stripe",
      "amount": "1.00",
      "currency": "USD",
      "scope": "report.basic",
      "expiresAt": "...",
      "sig": "..."
    }
  ]
}
```

That confirms the seller side is working. To complete the payment loop yourself in test mode, see [Testing Stripe End to End](#testing-stripe-end-to-end).

## Choose a Payment Rail

Stripe is the default primary method. Use Tempo per route with `method=tempo`, or globally with `MPP_DEFAULT_METHOD=tempo`.

### Stripe

Stripe settlement uses Shared Payment Tokens. The verifier creates and confirms a PaymentIntent from the SPT presented by the buyer.

```dotenv
STRIPE_SECRET_KEY=sk_test_...
STRIPE_NETWORK_ID=profile_...
STRIPE_API_VERSION=2026-05-27.preview
```

`STRIPE_SECRET_KEY` is needed to settle a payment. The package still emits a `402` without it, but settlement will fail until it is set.

`STRIPE_NETWORK_ID` is the Stripe profile id advertised in the challenge. Link and agent wallets use it to scope an SPT to your business. It is not used by the server-side settlement call, but live Link-based buyer flows depend on Stripe availability for your buyer and seller accounts.

To get a profile id:

1. Open [Stripe profile](https://dashboard.stripe.com/profiles) in the Stripe Dashboard.
2. Create a profile for your business.
3. Use the resulting `profile_...` value as `STRIPE_NETWORK_ID`.

Stripe SPT support uses preview APIs. Build against test mode first, pin the Stripe API version, and review Stripe and package changelogs before upgrading. Test mode works wherever your account is based. As of June 2026, live acceptance is limited to US-based Stripe accounts.

### Testing Stripe End to End

In development, you can mint a test SPT yourself. This lets you drive the full `402 -> mint SPT -> retry -> 200` loop without Link.

A single test account works: the same `sk_test_...` key can mint the SPT and settle it. We guide a two-account setup instead, because separate accounts match production conditions more closely.

- Seller account: the Laravel app's `STRIPE_SECRET_KEY`. This account creates and confirms the PaymentIntent.
- Buyer account: a different `sk_test_...` key used only to mint the test SPT. It stands in for the buyer wallet that issues the SPT in production.

First request the challenge and copy its `challengeId` and Stripe accept `sig`:

```bash
curl -s https://your-host/resource
```

```bash
{
  "type": "https://paymentauth.org/problems/payment-required",
  "title": "Payment Required",
  "status": 402,
  "detail": "Payment is required to access this resource.",
  "challengeId": "chal_...",                                # We'll need this for later
  "accepts": [
    {
      "method": "stripe",
      "amount": "1.50",
      "currency": "USD",
      "network_id": "profile_test_...",
      "payment_method_types": [
        "card"
      ],
      "grants": 1,
      "scope": "report.basic",
      "expiresAt": "2026-06-30T11:12:36Z",
      "sig": "03ce60d5..."                                  # We need this one also
    }
  ]
}
```

Mint a test SPT for a $1.00 challenge:

```bash
curl -s -u "sk_test_buyer_...:" -H "Stripe-Version: 2026-05-27.preview" \
  -X POST https://api.stripe.com/v1/test_helpers/shared_payment/granted_tokens \
  -d payment_method=pm_card_visa \
  -d "usage_limits[currency]=usd" \
  -d "usage_limits[max_amount]=100" \
  -d "usage_limits[expires_at]=$(($(date +%s)+300))"
```

```bash
{
  "id": "spt_...",                                          # Note this value also
  "object": "shared_payment.granted_token",
  ...
  "usage_limits": {
    "currency": "usd",
    "expires_at": 1782818057,
    "max_amount": 1000
  }
}
```

Replay the original request with the token:

```bash
curl -si https://your-host/resource \
  -H 'Authorization: Payment method="stripe", challengeId="chal_...", sig="...", spt="spt_..."'
```

The response should be `200 OK` and include a `Payment-Receipt` header:

```http
Payment-Receipt: id="rcpt_...", challengeId="chal_...", method="stripe", amount="1.00", currency="USD", ref="pi_...", settledAt="..."
```

The `ref` value is the Stripe PaymentIntent id.

Cards have minimum charge amounts, often around $0.50 or EUR 0.50. Price card-backed routes above the minimum, or use a metered bundle where the single charge clears it.

### Per-Payer Stripe Customers

By default, Stripe payments are guest charges. Set `methods.stripe.customer_resolver` to attach a seller-account Stripe Customer to the PaymentIntent when the paid retry already carries an identity you trust, such as an authenticated user or API key.

When implementing a customer resolver, attach it to the config:

```php
// config/mpp.php
'methods' => [
    'stripe' => [
        'customer_resolver' => [\App\Mpp\StripeCustomerResolver::class, 'resolve'],
    ],
],
```

```php
namespace App\Mpp;

use Illuminate\Http\Request;

class StripeCustomerResolver
{
    public function resolve(Request $request): ?string
    {
        return $request->user()?->stripe_customer_id;
    }
}
```

The resolver should return a `cus_...` id from the same Stripe account as `STRIPE_SECRET_KEY`. It runs on the paid retry, so any identity it uses must be present on that retry. For an API-key workflow, resolve the key to one of your own accounts and return that account's Stripe Customer id.

For open agent-payment endpoints, guest PaymentIntents plus metadata are often the right shape: the SPT proves payment authority, not a stable seller-side customer.

If the resolver returns `null` or throws, the package falls back to a guest charge.

### Tempo

Tempo settlement accepts pathUSD from the stock `npx mppx` client. The agent signs a pathUSD transfer and pays gas. Your server broadcasts the signed transaction and confirms that it mined.

```dotenv
TEMPO_RECIPIENT=0x...
TEMPO_RPC_URL=https://rpc.moderato.tempo.xyz
TEMPO_CHAIN_ID=42431
TEMPO_TOKEN=0x20c0000000000000000000000000000000000000
TEMPO_DECIMALS=6
```

`TEMPO_RECIPIENT` is required. The RPC URL, chain id, token, and decimals default to Tempo testnet values.

Protect a route with Tempo:

```php
Route::get('/paid', fn () => response()->json(['data' => 'paid']))
    ->middleware('mpp:0.01,USD,method=tempo,scope=paid');
```

Pay it with `mppx`:

```bash
npx mppx https://your-host/paid --network testnet --account <your-account>
```

### Testing Tempo End to End

Use this flow when you want to see a real Tempo testnet transfer land in a recipient address.

Create a temporary recipient address with [Foundry](https://getfoundry.sh). Install Foundry with [`foundryup`](https://book.getfoundry.sh/getting-started/installation), then create a local test wallet with [`cast wallet new`](https://getfoundry.sh/cast/reference/wallet/new/):

```bash
foundryup
cast wallet new
```

Copy the generated `address` value and use it as `TEMPO_RECIPIENT` in the Tempo configuration above. This address receives the testnet payment, so keep the generated private key only if you plan to reuse or move funds from it.

```dotenv
TEMPO_RECIPIENT=0x...
```

Add a low-value test route:

```php
use Illuminate\Support\Facades\Route;

Route::get('/tempo-test', fn () => response()->json([
    'paid' => true,
    'at' => now()->toIso8601String(),
]))->middleware('mpp:0.01,USD,method=tempo,scope=tempo.test');
```

Pay the route with a funded mppx testnet account:

```bash
npx mppx https://your-host/tempo-test --network testnet --account <your-account>
```

The successful response includes a `Payment-Receipt` header. Its `ref` value is the transaction hash:

```http
Payment-Receipt: id="rcpt_...", challengeId="chal_...", method="tempo", amount="0.01", currency="USD", ref="0x...", settledAt="..."
```

View the recipient address in the Tempo testnet explorer:

```text
https://explore.testnet.tempo.xyz/address/0x...
```

Replace `0x...` with the address you set as `TEMPO_RECIPIENT`. The explorer should show the incoming pathUSD transfer after the transaction is mined.

Tempo uses the mppx wire format. It cannot be co-offered in the same `402` challenge as Stripe. To support both rails on the same URL, choose the rail per request. See [Stripe and Tempo on One Route](#stripe-and-tempo-on-one-route).

## Protecting Routes

You can protect routes with middleware arguments, controller attributes, or automatic attribute enforcement.

### Middleware

```php
Route::get('/resource', MyPaidResource::class)
    ->middleware('mpp:0.50,USD');

Route::get('/report', ReportController::class)
    ->middleware('mpp:5.00,USD,grants=10,scope=report.basic');
```

You can also reference a [price book](#price-book) entry by key:

```php
Route::get('/report', ReportController::class)
    ->middleware('mpp:report.basic');
```

### Attribute Plus Middleware

```php
use Square1\Mpp\Attributes\RequiresPayment;

class ReportController
{
    #[RequiresPayment(amount: '5.00', currency: 'USD', grants: 10, scope: 'report.basic')]
    public function __invoke()
    {
        // ...
    }
}

Route::get('/report', ReportController::class)->middleware('mpp');
```

### Automatic Attribute Enforcement

Enable the attribute enforcer:

```dotenv
MPP_ATTRIBUTES_ENABLED=true
```

Then attributed controller actions are enabled without adding `mpp` to each route:

```php
#[RequiresPayment(amount: '0.50', currency: 'USD')]
public function latest()
{
    // ...
}
```

Automatic enforcement is disabled by default. It runs on the configured route groups, `web` and `api` by default. Routes already carrying the `mpp` middleware are skipped, so they are not charged twice.

### Payment Options

| Option | Middleware | Attribute |
| --- | --- | --- |
| Price and currency | `mpp:0.50,USD` | `amount: '0.50', currency: 'USD'` |
| One charge per request | `grants=1` | `grants: 1` |
| One charge for N accesses | `grants=10` | `grants: 10` |
| Scope | `scope=report.basic` | `scope: 'report.basic'` |
| Single method | `method=tempo` | `method: 'tempo'` |
| Multiple native methods | `methods=stripe\|acme` | `methods: ['stripe', 'acme']` |

`scope` is a label you choose for the priced resource. Metered sessions are locked to their scope. If you omit it, the package derives one from the route URI.

When you list several methods, the first one is the primary. It sets the dialect of the challenge and is the default method on a paid retry that omits one.

### Defaults

Use defaults to avoid repeating price or rail settings:

```dotenv
MPP_DEFAULT_METHOD=tempo
MPP_DEFAULT_AMOUNT=0.01
MPP_DEFAULT_CURRENCY=USD
MPP_DEFAULT_GRANTS=1
```

```php
Route::get('/report', ReportController::class)
    ->middleware('mpp:scope=report');

#[RequiresPayment(scope: 'resource')]
public function latest()
{
    // Amount, currency, grants, and method come from config.
}
```

Leave `MPP_DEFAULT_AMOUNT` unset if every protected route should declare its own price.

## Metered Access

Set `grants` above `1` when one payment should grant multiple accesses:

```php
Route::get('/report', ReportController::class)
    ->middleware('mpp:5.00,USD,grants=10,scope=report.basic');
```

The paid request spends the first credit and returns a `Payment-Session` header:

```http
HTTP/1.1 200 OK
Payment-Receipt: id="rcpt_...", method="stripe", amount="5.00", currency="USD", ref="pi_..."
Payment-Session: id="sess_...", remaining="9", scope="report.basic", expiresAt="..."
```

Reuse the session on later requests:

```bash
curl -si https://your-host/report \
  -H 'Authorization: Payment method="stripe", session="sess_..."'
```

Each successful request decrements the balance and returns the updated `Payment-Session` header. When the session is exhausted or expired, the next request receives a fresh `402`.

Session spends are scope-checked and atomic. Concurrent requests cannot spend more credits than the session was granted.

Metering works the same on both rails. A Tempo payment for a metered route also issues a session, reused with the same `Authorization: Payment ..., session="sess_..."` header shown above.

## Session Storage

A metered route (`grants > 1`) issues a session, which is a prepaid credit balance the server keeps between requests. The agent holds only the session id; the server holds the remaining count and decrements it on each request, so that balance has to be stored somewhere. Once-off routes (`grants = 1`) never create a session, so you only need a session store if you use metered access.

The default driver is `cache`:

```dotenv
MPP_SESSION_DRIVER=cache
```

The cache driver uses your app's default cache store unless `MPP_SESSION_CACHE_STORE` is set, so a Redis-backed application keeps sessions in Redis automatically. Point it at a persistent, shared store. A per-server or memory-only cache can evict a balance early or hide it from other workers, which would cut a buyer's paid-for access short.

Use the database driver when you want balances to survive cache eviction and restarts, or to share them across app servers without a shared cache:

```dotenv
MPP_SESSION_DRIVER=database
MPP_SESSION_DB_CONNECTION=
```

The migration creates the `mpp_sessions` table that holds those balances. It is the only reason the migration exists, and you need it only with the database driver:

```bash
php artisan vendor:publish --tag=mpp-migrations
php artisan migrate
```

## Configuration

The main settings live in `config/mpp.php`.

| Key | Purpose |
| --- | --- |
| `secret` | Challenge signing key. Defaults to a key derived from `APP_KEY` when unset. |
| `challenge_ttl` | Challenge lifetime in seconds. Default: `300`. |
| `session_ttl` | Metered session lifetime in seconds. Default: `3600`. |
| `default_method` | Primary settlement method. Default: `stripe`. |
| `accept` | Ordered native method set, such as `['stripe', 'acme']`. Leave null to offer only `default_method`. Including Tempo raises `InvalidConfigurationException`. |
| `defaults.amount` | Global price fallback. Leave null to require each route to set a price. |
| `defaults.currency` | Global currency fallback. Default: `USD`. |
| `defaults.grants` | Global grants fallback. Default: `1`. |
| `methods.stripe.*` | Stripe verifier settings. |
| `methods.tempo.*` | Tempo verifier settings. |
| `sessions.*` | Metered session storage settings. |
| `attributes.enabled` | Enables automatic `#[RequiresPayment]` enforcement. Default: `false`. |
| `attributes.middleware_groups` | Route groups used by automatic attribute enforcement. Default: `['web', 'api']`. |
| `price_book` | Named pricing presets. |

### Price Book

Price book entries let you name common prices:

```php
'price_book' => [
    'report.basic' => ['amount' => '5.00', 'currency' => 'USD', 'grants' => 10],
],
```

```php
Route::get('/report', ReportController::class)
    ->middleware('mpp:report.basic');
```

The key also becomes the default scope.

### Configuration Validation

The gate checks built-in rail configuration before it mints a challenge.

| Rail | Missing config | Result |
| --- | --- | --- |
| Stripe `secret_key` | Settlement cannot run. | Logs once, still emits `402`. |
| Stripe `network_id` | Link or agent wallets cannot scope an SPT to you. | Logs once, still emits `402`. |
| Tempo `recipient`, `token`, or `chain_id` | The challenge would be unpayable or unsafe. | Throws `InvalidConfigurationException`. |
| Tempo `rpc_url` | Settlement cannot broadcast the transaction. | Logs once, still emits `402`. |

Custom verifiers are responsible for their own configuration validation.

## Testing

The local test suite uses Pest:

```bash
composer test
composer lint
```

Live Stripe tests self-skip unless a test key is present:

```bash
STRIPE_SECRET_KEY=sk_test_... vendor/bin/pest --group=stripe
```

Cross-account Stripe tests need two different test accounts:

```bash
STRIPE_BUYER_SECRET_KEY=sk_test_... STRIPE_SECRET_KEY=sk_test_... vendor/bin/pest --group=stripe-cross
```

## Advanced Usage

### Multiple Native Rails

Stripe uses the package's native challenge shape, where one `402` can list several signed `accepts[]` entries. Custom rails that implement `Square1\Mpp\Settlement\Verifier` can use the same shape.

```php
'accept' => ['stripe', 'acme'],
```

Per route:

```php
Route::get('/resource', MyPaidResource::class)
    ->middleware('mpp:0.50,USD,methods=stripe|acme');
```

The order matters: the first method listed in `accept` or `methods=` is the primary, and the agent is offered the rest in that order. Each accept entry is signed for its method. A signature for one method cannot be reused for another.

> Tempo cannot be part of a multi-rail `accepts[]` challenge.
>
> Do not configure `accept => ['stripe', 'tempo']` or `methods=stripe|tempo`. Tempo uses the mppx challenge shape, so a Tempo challenge must offer only Tempo. If one Laravel route should accept both Stripe and Tempo, choose the rail per request instead.

### Stripe and Tempo on One Route

A single `402` can use only one wire format. To accept either Stripe or Tempo on the same Laravel route, choose the rail before invoking the MPP middleware:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Square1\Mpp\Http\Middleware\RequirePayment;
use Symfony\Component\HttpFoundation\Response;

class ChooseRail
{
    public function __construct(private readonly RequirePayment $mpp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $spec = $request->query('rail') === 'tempo'
            ? '0.01,USD,method=tempo,scope=resource'
            : '0.50,USD,method=stripe,scope=resource';

        return $this->mpp->handle($request, $next, ...explode(',', $spec));
    }
}
```

```php
Route::get('/resource', MyPaidResource::class)
    ->middleware(\App\Http\Middleware\ChooseRail::class);
```

Point mppx clients at the Tempo URL, for example `/resource?rail=tempo`.

### Custom Native Verifiers

A native rail implements `Square1\Mpp\Settlement\Verifier`.

The paid retry presents a `proof` value. Your verifier must check that proof against the rail's own source of truth and return success only when the settled amount and currency match the signed challenge.

```php
namespace App\Mpp;

use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;
use Square1\Mpp\Settlement\SettlementResult;
use Square1\Mpp\Settlement\Verifier;
use Square1\Mpp\Support\Money;

final class AcmePayVerifier implements Verifier
{
    public function __construct(private readonly AcmePayClient $acme) {}

    public function verify(Credential $credential, Challenge $challenge, array $context = []): SettlementResult
    {
        $chargeId = $credential->proof;

        if ($chargeId === null || $chargeId === '') {
            return SettlementResult::failure('No AcmePay charge id presented.');
        }

        try {
            $charge = $this->acme->getCharge($chargeId);
        } catch (\Throwable $e) {
            return SettlementResult::failure('AcmePay lookup failed: '.$e->getMessage());
        }

        $expectedMinor = Money::toMinorUnits($challenge->amount, $challenge->currency);

        if ($charge->status !== 'succeeded'
            || $charge->amountMinor !== $expectedMinor
            || strtoupper($charge->currency) !== strtoupper($challenge->currency)) {
            return SettlementResult::failure('AcmePay charge does not match the challenge.');
        }

        return SettlementResult::settled(
            settlementRef: $charge->id,
            amountMinor: $expectedMinor,
            currency: $challenge->currency,
        );
    }
}
```

Register and offer it:

```php
'methods' => [
    'acme' => [
        'verifier' => \App\Mpp\AcmePayVerifier::class,
        'payment_method_types' => ['acme'],
    ],
],

'accept' => ['stripe', 'acme'],
```

The gate already checks that the challenge exists, is unexpired, was offered for the method, and has a valid signature. It also burns successful challenges and serializes concurrent settlement attempts. If your rail supports idempotency keys, use the challenge id.

### Wire Format

Most implementors do not need to build these headers by hand, but they are useful for debugging.

Native unpaid response:

```http
HTTP/1.1 402 Payment Required
WWW-Authenticate: Payment id="chal_...", method="stripe", amount="0.50", currency="USD", network_id="profile_...", grants="1", scope="resource", expires_at="...", sig="..."
Content-Type: application/problem+json
Cache-Control: no-store

{
  "type": "https://paymentauth.org/problems/payment-required",
  "title": "Payment Required",
  "status": 402,
  "challengeId": "chal_...",
  "accepts": [
    {
      "method": "stripe",
      "amount": "0.50",
      "currency": "USD",
      "network_id": "profile_...",
      "grants": 1,
      "scope": "resource",
      "expiresAt": "...",
      "sig": "..."
    }
  ]
}
```

Native paid retry:

```http
Authorization: Payment method="stripe", challengeId="chal_...", sig="...", spt="spt_..."
```

Custom native rails use `proof` instead of `spt`:

```http
Authorization: Payment method="acme", challengeId="chal_...", sig="...", proof="charge_..."
```

Metered follow-up:

```http
Authorization: Payment method="stripe", session="sess_..."
```

Tempo uses the separate mppx format emitted and consumed by the `mppx` client.

### Security Notes

- Challenges are HMAC-signed over the payment terms and expiry.
- A paid retry must echo the signature for the selected method.
- Challenges are burned after successful settlement.
- Stripe settlement is trusted only after a succeeded PaymentIntent matching the challenge amount and currency.
- Tempo settlement is trusted only after the signed transfer pays the challenged token, amount, and recipient, and the transaction is confirmed.
- Metered sessions are scope-checked and decremented atomically.
- The challenge signing key and Stripe secret key stay server-side.

### Octane and FrankenPHP

The package is safe under long-lived workers. Request-specific state is passed per call rather than stored on singletons.

Reload workers after changing `MPP_CHALLENGE_SECRET`, TTLs, Stripe keys, or Tempo config. Tempo settlement blocks while it polls for a receipt, up to `poll_attempts * poll_delay_ms`.

## License

This package is released under the MIT License. See [LICENSE.md](LICENSE.md).

MPP and Stripe SPT APIs may change while preview APIs are involved. Pin package versions and review the changelog when upgrading.
