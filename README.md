![Tests](https://github.com/square1-io/laravel-mpp/actions/workflows/tests.yml/badge.svg)

# Laravel MPP

Charge AI agents for access to Laravel routes with the [Machine Payments Protocol (MPP)](https://mpp.dev).

`square1/laravel-mpp` returns a `402 Payment Required` challenge for protected routes. A capable agent pays the challenge, retries the request, and receives the response with a payment receipt. You choose the price per route, or issue a metered session where one payment grants multiple accesses.

The package includes two payment rails:

- [Stripe](https://stripe.com) Shared Payment Tokens ([SPTs](https://docs.stripe.com/agentic-commerce/concepts/shared-payment-tokens)), settled as [PaymentIntents](https://docs.stripe.com/payments/payment-intents).
- [Tempo](https://tempo.xyz) pathUSD, paid by the standard [`npx mppx`](https://mpp.dev) client.

## Readiness

The Laravel middleware, signed challenges, receipts, metered sessions, and storage drivers are designed for production use.

The bundled Stripe rail depends on Stripe Shared Payment Tokens, which currently use preview APIs. Use it for test-mode development, demos, and Stripe-approved pilot or live flows. Expect API shape, Dashboard behavior, and buyer-wallet availability to change while Stripe's agentic-commerce APIs are in preview. Test mode will work globally, but live acceptance is currently gated to North American companies (August 26).

The bundled Tempo rail settles pathUSD from the standard `mppx` client, on the Moderato testnet by default (mainnet via env). Both rails speak the same MPP wire format and can be offered together on one route. You can verify conformance with `npx mppx validate`.

MPP is a young, fast-moving protocol. This package tracks the published spec closely as it evolves.

```php
Route::get('/resource', MyPaidResource::class)
    ->middleware('mpp:0.50,USD');

#[RequiresPayment(amount: '5.00', currency: 'USD', grants: 10, scope: 'report.basic')]
public function report()
{
    // One $5 payment grants 10 accesses.
}
```

For a real-world demo, see [PayForGoals.com](https://www.payforgoals.com).

## Contents

- [Readiness](#readiness)
- [Installation](#installation)
- [Quickstart](#quickstart)
- [Choose a Payment Rail](#choose-a-payment-rail)
- [Protecting Routes](#protecting-routes)
- [Metered Access](#metered-access)
- [Retries and Idempotency](#retries-and-idempotency)
- [Dynamic Pricing](#dynamic-pricing)
- [Preconditions](#preconditions)
- [Session Storage](#session-storage)
- [Configuration](#configuration)
- [Testing](#testing)
- [Advanced Usage](#advanced-usage)
- [License](#license)

Release history, and anything to watch when upgrading, is in [CHANGELOG.md](CHANGELOG.md).

## Installation

Requires PHP 8.3+ and Laravel 12 or 13. The Tempo rail needs the `bcmath` and `gmp` PHP extensions for its pure-PHP on-chain math (Keccak, transaction decoding, and settlement). Composer enforces both.

```bash
composer require square1/laravel-mpp
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

This example uses Stripe test mode, transacting directly with a Shared Payment Token. Test mode works wherever your Stripe account is based. As of August 2026, live acceptance is gated to North America-based accounts, including the [Link](https://link.com) buyer wallet. The test-mode flow below is the broadly supported test path today.

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

The response is a `402 Payment Required` in the MPP wire format ([spec](https://paymentauth.org/draft-httpauth-payment-00)). The terms live in the `WWW-Authenticate` header. It holds one `Payment` challenge per offered rail. Each challenge authenticates itself. Its `id` is an HMAC (a keyed signature) over every bound field, so no one can change the terms between the quote and the paid retry.

```http
HTTP/1.1 402 Payment Required
Content-Type: application/problem+json
Cache-Control: no-store
WWW-Authenticate: Payment id="...", realm="your-host", method="stripe",
    intent="charge", request="eyJhbW91bnQiOiIxMDAi...", expires="2026-08-18T15:05:00Z",
    opaque="eyJub25jZSI6..."
```

Every challenge carries an `opaque` parameter (it holds a per-mint random nonce and this package's binding data). A conformant client echoes it back unchanged, along with the other parameters.

The `request` parameter is base64url JSON carrying the economic terms (amount in minor units, currency, Stripe network profile). The body is a plain RFC 9457 problem document:

```json
{
  "type": "https://paymentauth.org/problems/payment-required",
  "title": "Payment Required",
  "status": 402,
  "detail": "Payment is required.",
  "challengeId": "..."
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

Stripe SPT support uses preview APIs. Build against test mode first, pin the Stripe API version, and review Stripe and package changelogs before upgrading. Test mode works wherever your account is based.

### Testing Stripe End to End

In development, you can mint a test SPT yourself. This lets you drive the full `402 -> mint SPT -> retry -> 200` loop without Link.

A single test account works: the same `sk_test_...` key can mint the SPT and settle it. We guide a two-account setup instead, because separate accounts match production conditions more closely.

- Seller account: the Laravel app's `STRIPE_SECRET_KEY`. This account creates and confirms the PaymentIntent.
- Buyer account: a different `sk_test_...` key used only to mint the test SPT. It stands in for the buyer wallet that issues the SPT in production.

First request the challenge and copy the full parameter set from the `WWW-Authenticate` header. A conformant client echoes the challenge back without changes inside its credential.

```bash
curl -s https://your-host/resource
```

```http
WWW-Authenticate: Payment id="tdCiAa...",                  # each parameter gets echoed back
    realm="your-host", method="stripe", intent="charge",
    request="eyJhbW91bnQiOiIxNTAi...",                     # base64url terms: amount "150" (minor units), currency "usd", networkId
    expires="2026-06-30T11:12:36Z",
    opaque="eyJub25jZSI6..."                               # must be echoed back unchanged
```

Mint a test SPT for a \$1.00 challenge:

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

Replay the original request with a spec credential. The credential is base64url JSON of `{challenge, payload}`. The `challenge` field echoes the header's parameters, including `opaque`. The `payload` field carries the SPT. The challenge echo must match every parameter the header sent, so copy `opaque` verbatim.

```bash
CREDENTIAL=$(php -r 'echo rtrim(strtr(base64_encode(json_encode([
    "challenge" => [
        "id" => $argv[1], "realm" => "your-host",
        "method" => "stripe", "intent" => "charge",
        "request" => $argv[2], "expires" => $argv[3],
        "opaque" => $argv[5],
    ],
    "payload" => ["spt" => $argv[4]],
])), "+/", "-_"), "=");' "$CHALLENGE_ID" "$REQUEST_B64" "$EXPIRES" "$SPT" "$OPAQUE")

curl -si https://your-host/resource -H "Authorization: Payment $CREDENTIAL"
```

The response should be `200 OK` and include a `Payment-Receipt` header. The header is base64url JSON:

```json
{
  "status": "success",
  "method": "stripe",
  "reference": "pi_...",
  "timestamp": "2026-08-18T15:01:12Z",
  "challengeId": "...",
  "amount": "1.00",
  "currency": "USD"
}
```

The `reference` value is the Stripe PaymentIntent id. You rarely build this by hand. `npx mppx validate https://your-host --endpoint GET:/resource --yes` runs the whole loop for you.

Cards have minimum charge amounts, often around \$0.50 or EUR 0.50. Price card-backed routes above the minimum, or use a metered bundle where the single charge clears it.

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

Tempo settlement accepts pathUSD from the standard `npx mppx` client. The agent signs a pathUSD transfer and pays gas. Your server broadcasts the signed transaction and confirms that it mined.

```dotenv
TEMPO_RECIPIENT=0x...

# Defaults target the Moderato testnet. For mainnet, set all three together:
# TEMPO_RPC_URL=<mainnet RPC URL>
# TEMPO_CHAIN_ID=4217
# TEMPO_TOKEN=0x20C000000000000000000000b9537d11c60E8b50
```

`TEMPO_RECIPIENT` is required. The RPC URL, chain id (`42431`), and pathUSD token address default to the Moderato testnet, so the rail is payable out of the box once you set a recipient. The token address differs per network. Testnet pathUSD is `0x20c0000000000000000000000000000000000000`. Mainnet pathUSD is `0x20C000000000000000000000b9537d11c60E8b50`. A token from one network on the other network's chain reverts with `TIP20: Uninitialized`. Always change chain id, RPC, and token together.

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

The successful response includes a `Payment-Receipt` header. The header is base64url JSON. Its `reference` value is the transaction hash.

```json
{
  "status": "success",
  "method": "tempo",
  "reference": "0x...",
  "timestamp": "...",
  "challengeId": "...",
  "amount": "10000",
  "currency": "0x20c0000000000000000000000000000000000000"
}
```

View the recipient address in the Tempo testnet explorer:

```text
https://explore.testnet.tempo.xyz/address/0x...
```

Replace `0x...` with the address you set as `TEMPO_RECIPIENT`. The explorer should show the incoming pathUSD transfer after the transaction is mined.

Both rails speak the same wire format, so a route can offer Tempo and Stripe together. See [Offering Both Rails on One Route](#offering-both-rails-on-one-route).

### Offering Both Rails on One Route

One route can offer both rails in a single `402`. The MPP wire format supports this. A `402` carries one `Payment` challenge per offered rail in `WWW-Authenticate`. Each challenge has its own binding id. The client answers exactly one. The response format does not force a choice before the quote.

Offer both rails on one route:

```php
Route::get('/resource', MyPaidResource::class)
    ->middleware('mpp:0.50,USD,methods=stripe|tempo');
```

Or make multi-rail the house default, so no route needs to name rails at all:

```dotenv
MPP_ACCEPT=stripe|tempo
```

Per-route `methods=` (or the attribute's `methods:`) overrides the config set. A single `method=` still pins a route to one rail. A single-rail configuration emits a byte-identical `402` to previous versions of this package.

The `402` then carries two challenges:

```http
WWW-Authenticate: Payment id="...", realm="...", method="stripe", intent="charge", request="...", expires="..."
WWW-Authenticate: Payment id="...", realm="...", method="tempo", intent="charge", request="...", expires="..."
```

How a client sees this depends on its HTTP library:

- **`fetch`-based clients (Node, browsers) recombine it.** Per the WHATWG Headers spec, `headers.get('www-authenticate')` returns the repeated lines joined with `, ` - one string carrying every challenge. Agents built on `fetch`, `mppx` included, therefore see byte-identical input whether the server sends one joined line or several.
- **Server-side bags usually return only the first.** Laravel/Symfony's `$response->headers->get()` gives you one line. Use `->all('WWW-Authenticate')` when you need every challenge, or a multi-rail response will look single-rail.

Each challenge is bound and spendable on its own. Settling the Stripe challenge burns only that challenge. Dispatch on the paid retry uses the stored challenge's method, so a credential cannot claim its way onto a different rail.

Callers select a rail with the `Accept-Payment` request header. It is a standard MPP header, so you document nothing custom for your agents:

```http
GET /resource HTTP/1.1
Accept-Payment: tempo/charge, stripe/charge;q=0.3
```

The same preference can be sent by a payment client. For example, this selects
Tempo on a route that offers both methods:

```bash
npx mppx https://your-host/resource \
  -H 'Accept-Payment: tempo/charge' \
  --network testnet --account <your-account>
```

The server filters and ranks its offered challenges by the header. q-values, wildcards (`tempo/*`), and `q=0` exclusions all work as in `Accept`. A caller that sends nothing gets the full offered set in your configured order. A header that matches nothing is ignored per spec, so a caller can never obtain a rail you did not offer.

> **Order the rails your callers can actually pay first.**
>
> The spec asks clients to pick a challenge by capability - "clients SHOULD select one based on their capabilities and user preferences" - but not every client does. `npx mppx` (0.9.2) takes the first challenge in the set without checking whether it can pay it, and fails outright if it cannot:
>
> ```
> Error (REQUEST_FAILED): Request failed: Invalid CLI options
> (paymentMethod: Invalid input: expected string, received undefined)
> ```
>
> So on `methods=stripe|tempo` a crypto-only agent is handed the card challenge and dies, while `methods=tempo|stripe` pays cleanly. Nothing is wrong with the `402` in either case - the difference is entirely which rail leads the set.
>
> Two things follow. List the rail your typical caller can pay first, and treat "both rails from one URL" as working properly only for callers that send `Accept-Payment`; for callers that do not, you are really offering the first rail with the rest as ignored detail. The order you write is the order you get.

### Discovery

The package serves an advisory OpenAPI document at `/openapi.json`. It lists every payment-gated route with its `x-payment-info` offers. The package generates it from the live router. It resolves each route's price the same way the runtime does, using price-book entries, global defaults, and per-rail request builders. Routes priced per request emit `amount: null`. MPP agents use it to find payable endpoints, and `mppx validate` checks it.

The discovery draft requires this document at `GET /openapi.json`, so the path is fixed. Disable it if your app serves its own OpenAPI document, and merge the `x-payment-info` extension there instead:

```dotenv
MPP_DISCOVERY=false
```

### Validating Conformance

Point the reference validator at your app. It exercises discovery, challenge format, and error handling. On testnet, with an auto-funded wallet, it can also run an on-chain tempo settlement:

```bash
npx mppx@latest validate https://your-host --endpoint GET:/resource --yes
```


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
| Settlement rail | `method=tempo` | `method: 'tempo'` |
| Price per request | `pricing=tiered` | `pricing: ['tiered']` |
| Preconditions | `preconditions=postexists` | `preconditions: ['postexists']` |

`scope` is a label you choose for the priced resource. Metered sessions are locked to their scope. If you omit it, the package derives one from the route URI.

When you list several methods, the first one is the primary. It leads the challenge set, before any `Accept-Payment` reordering by the caller. The body's `challengeId` names it.

That holds wherever the list is written - a route's `methods=`, the attribute's `methods:`, or `MPP_ACCEPT`. `MPP_DEFAULT_METHOD` chooses the rail for routes that name none; it does not reorder a list that does. A single `method=` is the one thing that overrides the order, taking the primary slot for that route.

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

> Metering is an experimental extension. Metered sessions are a package
> feature, not part of the MPP core spec. The prepaid-session mechanism (the
> `session="…"` credential form and the `Payment-Session` response header) is
> this package's own shape on top of the spec's `Payment` scheme. The spec has
> a generic `session` intent in progress ([mpp-specs PR #280](https://github.com/tempoxyz/mpp-specs/pull/280)).
> If it lands, this package intends to align with it. That change may not be
> backward compatible. Metering is stable enough to build on for one
> deployment. Pin the package version, and expect a migration note before you
> adopt a release that follows #280. Once-off charges (`grants` = 1, the
> default) are pure spec and have no such caveat.

Set `grants` above `1` when one payment should grant multiple accesses:

```php
Route::get('/report', ReportController::class)
    ->middleware('mpp:5.00,USD,grants=10,scope=report.basic');
```

The paid request spends the first credit and returns a `Payment-Session` header:

```http
HTTP/1.1 200 OK
Payment-Receipt: <base64url JSON: {"status":"success","method":"stripe","reference":"pi_...","amount":"5.00","currency":"USD",...}>
Payment-Session: id="sess_...", remaining="9", scope="report.basic", expiresAt="..."
```

Reuse the session on later requests. The credential is `Payment session="..."` on its own. Do not add other auth-params to it:

```bash
curl -si https://your-host/report \
  -H 'Authorization: Payment session="sess_..."'
```

Each successful request decrements the balance and returns the updated `Payment-Session` header. When the session is exhausted or expired, the next request receives a fresh `402`.

Session spends are scope-checked and atomic. Concurrent requests cannot spend more credits than the session was granted.

Metering works the same on both rails. A Tempo payment for a metered route also issues a session, reused with the same `Authorization: Payment session="sess_..."` header shown above.

## Retries and Idempotency

A payment moves real money. A client may retry because a response was lost, a connection dropped, or two requests raced. It should not be charged twice. The gate deduplicates retries and concurrent requests against one challenge. Once a challenge settles, a retry that echoes it gets the recorded result rather than a new bill. This covers the common cases. It does not by itself make a side effect crash-safe. If the process dies after the charge but before the response is recorded, the gate cannot replay a result it never stored. For side effects that must survive that window, add application-level idempotency (an `Idempotency-Key` on the action). The crash window is described in full below.

A challenge is single-use. The first successful settlement burns it. What happens on a later request depends on why it arrives:

| Situation | Response | Meaning for the client |
| --- | --- | --- |
| First valid payment | `200` plus `Payment-Receipt` | Settled. |
| Retry of an already-settled challenge (for example, the `200` was lost) | `200` plus the original `Payment-Receipt` | Already paid. Here is the receipt again. No second charge. |
| Retry while the first settlement is still in flight | `409` plus `Retry-After` | Wait and retry. Do not pay again. |
| An unknown, expired, or tampered challenge | `402` plus a fresh challenge | This one needs paying. |

The difference is deliberate. Only a `402` means pay. A `409` means the payment is in progress, so wait. A replayed `200` means you already paid. A conformant agent that reads these signals does not double-pay on a retry. This holds within the limits below. The gate cannot replay a response it never recorded, so a retry then takes a fresh `402`. That happens on a crash before the record, or on a streamed, binary, or over-limit response. For side effects that must survive that window, add application-level idempotency.

The package keeps a settled response in a short-lived ledger (`mpp.settlement_replay_ttl`, default 5 minutes) so it can match a retry to it. Metered payments replay the same session. No extra credit is spent, and no second session is issued. Set the retention above your clients' own retry and timeout window:

```dotenv
MPP_SETTLEMENT_REPLAY_TTL=300   # seconds a settled payment is replayable (default 300)
```

Replay is not granted on the challenge id alone. The id travels in the `402` and the credential, so it is not a secret. The gate stores a non-reversible fingerprint of three things: the successful credential, the request body, and the concrete request target. It replays only when a retry matches that fingerprint. A different credential, a changed body, or a different target does not get the paid response.

Only a buffered response within `mpp.replay_max_bytes` (default 256 KB) is snapshotted for replay. A streamed or binary response, or one over the limit, is not stored, so a lost-response retry of that endpoint takes a fresh `402` rather than an empty or truncated body. Such an endpoint is therefore not lost-response-idempotent: a dropped connection can charge its buyer again. Keep a paid endpoint buffered and within the limit if you need replay, or make it idempotent in the application.

A lock serialises concurrent settlements of the same challenge. Its lifetime covers the slowest rail, since a Tempo on-chain confirm can take tens of seconds. Two requests can never settle one challenge in parallel. The second request gets the `409` above.

> One window remains open by design. The protected action runs before the ledger write and the challenge burn, so if the server process or cache dies in that span the challenge can be left live and the action can run a second time on a retry. The window is a few milliseconds wide. Closing it completely would need a distributed transaction across the rail, the ledger, and your application, which MPP does not assume. True exactly-once execution for arbitrary controller side effects is the application's responsibility: use your own Idempotency-Key handling or a transactional state machine for actions that must never repeat. For card and on-chain rails the practical exposure of the window itself is very small.

## Dynamic Pricing

Everything above prices a route. Sometimes the price belongs to the request. A pro account pays \$2.50 where a free account pays \$5.00. A partner gets a bigger bundle for the same money. A caller in another region pays in another currency.

A price resolver decides the price per request. Register it once, and name it on the routes it applies to. The resolved price is the one minted into the `402`:

```php
// config/mpp.php
'pricing' => [
    'resolvers' => [
        'tiered' => [\App\Mpp\Pricing\TieredPrice::class, 'price'],
    ],

    // Apply to every gated route, before any route-specific resolvers.
    'global' => [],
],
```

```php
namespace App\Mpp\Pricing;

use Illuminate\Http\Request;
use Square1\Mpp\Payment\PaymentSpec;

class TieredPrice
{
    /** @return array<string, mixed>|null */
    public function price(Request $request, PaymentSpec $spec): ?array
    {
        return match ($request->user()?->tier) {
            'pro'     => ['amount' => '2.00'],
            'partner' => ['amount' => '2.00', 'grants' => 20, 'scope' => 'report.partner'],
            'staff'   => ['free' => true],
            default   => null,   // leave the route's own price alone
        };
    }
}
```

Attach it like any other option:

```php
// $5 is the LIST PRICE. A caller pays it when `tiered` returns null.
// Recognised tiers get a discount off it.
Route::get('/report', ReportController::class)
    ->middleware('mpp:5.00,USD,scope=report,pricing=tiered');

#[RequiresPayment(amount: '5.00', scope: 'report', pricing: ['tiered'])]
public function show() { /* ... */ }
```

```php
// Or on a price_book entry, so every route using the entry inherits it.
'price_book' => [
    'report.basic' => ['amount' => '5.00', 'currency' => 'USD', 'pricing' => ['tiered']],
],
```

### Who Owns the Price

One rule applies. Something must supply a price before the gate, either the route or a resolver.

Which one is yours to choose, per route, by whether you write an amount:

```php
// The route owns the default price; the custom resolver may overwrite it.
->middleware('mpp:5.00,USD,scope=report,pricing=tiered');

// The resolver fully owns responsibility for the price.
->middleware('mpp:scope=report,pricing=tiered');
```

Write the amount when your endpoint has a real list price. It is then the price for every caller the resolver does not recognise. It is also the price you fall back to if you later disable the resolver. Leave it out when there is no list price to state, as with usage-based or per-item pricing. Do not invent a placeholder that nothing reads.

On a resolver-owned route, if every resolver declines, the request cannot be priced and raises `UnpriceableRequestException`, naming the route and the resolvers that ran. It is a distinct exception from `InvalidConfigurationException` on purpose. The configuration is fine, and what went wrong depends on the request, so it can recur in production long after a deploy rather than surfacing once at boot.

If `mpp.defaults.amount` is set, every route has a house price and this case can't arise. In this case, a declining resolver falls back to this default.

### What a Resolver May Change

A resolver is a plain class with one method. There is no base class to extend and no special casing. You return an array, and the package reads it. One return can set any of these keys together. It is not limited to the amount.

| Key | Effect |
| --- | --- |
| `amount` | The price. Must be a positive number. |
| `currency` | The currency code, upper-cased for you. |
| `grants` | Accesses per payment. `> 1` issues a metered session. |
| `scope` | The label the payment and any session are bound to. |
| `free` | `true` serves the route without charging. |

Any other key, including `method`, throws `InvalidConfigurationException`. The package resolves which rails a route offers once, from the route's own `method=` or `methods=` and the configured default. A resolver cannot change that. A resolver sets the price, not the payment terms around it. To offer several rails at once, see [Offering Both Rails on One Route](#offering-both-rails-on-one-route).

A zero, negative, or non-numeric `amount` also throws. Giving a resource away must be explicit. A resolver that miscalculates, or reads an empty config value, then fails loudly instead of quietly making a paid endpoint free.

```php
return ['free' => true];    // yes, serve this one for nothing
return ['amount' => '0'];   // throws
```
 `free => true` and an `amount` together throw for the same reason. The package should never have to guess which one you meant. A free request skips the challenge, the session, and the receipt. It is served like an unguarded route. Its preconditions still run, so a free caller cannot reach a resource a check would have refused them.

Every shape from one method, on a route declaring `mpp:9.00,USD,grants=3,scope=everything.list`:

```php
public function price(Request $request, PaymentSpec $spec): ?array
{
    return match ($request->user()?->tier) {

        // Just the price. Everything else on the route stands.
        'pro' => ['amount' => '2.00'],

        // Price, currency, bundle size and credit pool, all at once.
        'partner' => [
            'amount' => '18.00',
            'currency' => 'EUR',
            'grants' => 25,
            'scope' => 'everything.partner',
        ],

        // No charge. Same method, same return type. `free` is just another key.
        // No 'amount' alongside it, or the pair throws.
        'staff' => ['free' => true],

        // No opinion. NOT free: the route's own price stands.
        default => null,
    };
}
```

| Caller | Result |
| --- | --- |
| unrecognised (`null`) | `402`, 9.00 USD, grants 3, scope `everything.list` |
| `pro` | `402`, 2.00 USD, grants 3, scope `everything.list` |
| `partner` | `402`, 18.00 EUR, grants 25, scope `everything.partner` |
| `staff` | `200`, served, no challenge |

Keep one distinction clear. `null` means no opinion, not no charge. Waiving is always `['free' => true]`. On a route that states no price of its own, that difference decides between a served request and an exception.

### Composition

Resolvers compose like preconditions. Globals run first, then the route's own, in declared order, de-duplicated. Each one receives the spec as the previous one left it, so a later resolver can build on an earlier one:

```php
->middleware('mpp:5.00,USD,pricing=tiered|regional')
```

Here `regional` sees the tier-adjusted amount, not the route's default \$5. An unknown name throws instead of falling back to the static price, so a typo cannot quietly charge everyone list price.

### Pricing and Metered Sessions

Metered sessions are bound to a scope, not to a payer. A session is a bearer credit balance. Whoever holds the id can spend it on that scope.

So if a metered route's price varies, vary its `scope` too:

```php
'partner' => ['amount' => '2.00', 'grants' => 20, 'scope' => 'report.partner'],
```

Without that, any bearer can spend credits bought at \$2 on the same scope, including one who should have paid \$5. The package logs a warning when a resolver reprices a metered route without changing its scope. It logs once per scope per process, so once per request under PHP-FPM and once per worker under Octane. Once-off routes (`grants = 1`) never issue a session and are unaffected.

### The Quote Is Binding

A resolver decides the price of a challenge, not of a settlement. The amount is HMAC-signed into the `402`, and settlement verifies against that stored challenge, never against a freshly-resolved spec. So a resolver whose answer changes between the `402` and the paid retry cannot change what that buyer was quoted:

```
402  →  amount="2.00"   (caller was on the pro tier)
        ... their subscription lapses ...
retry →  settles at 2.00, receipt says 2.00
```

The same holds in the other direction: a resolver that turns `free` after issuing a `402` cannot burn or settle that challenge, and a resolver that raises the price cannot charge an outstanding quote more than it promised. Only new challenges get the new price.

Resolvers run on every gated request, including paid retries and session spends. Keep them cheap and free of side effects. They are not the place to write an audit record. The resolved `amount` is ignored on those requests, but the resolved `scope` is not. A session is spent against the scope the resolver returns at that moment. If a caller's tier changes while they hold credits, their session stops matching and they get a fresh `402`. Keep a tier's scope stable for as long as its sessions can live (`MPP_SESSION_TTL`), or key the scope on something that outlives the tier.

## Preconditions

The payment gate runs before your controller. On a paid retry it settles the payment and then calls the controller, so a 404 raised inside the controller comes after the buyer has already paid. And the first, unpaid request to a missing resource returns a `402`, which tells an agent to pay for something that does not exist.

Preconditions close that gap. A precondition is a named check that runs before a `402` is minted or a payment settled. It returns a response to reject the request (a `404` for a missing resource, a `403` for a blocked user) or null to let the request proceed to the gate. Anything that decides whether a request can ever be fulfilled belongs here, not in the controller.

Define checks once in config, then attach them where they apply. Each check is a `[Class::class, 'method']` pair, resolved through the container (so it stays `config:cache`-safe), called with the request and the resolved `PaymentSpec`:

```php
// config/mpp.php
'preconditions' => [
    'checks' => [
        'postexists'     => [\App\Mpp\Checks\PostExists::class, 'check'],
        'usernotblocked' => [\App\Mpp\Checks\UserNotBlocked::class, 'check'],
    ],

    // Run on every gated route, before any route-specific checks.
    'global' => ['usernotblocked'],
],
```

```php
namespace App\Mpp\Checks;

use App\Models\Post;
use Illuminate\Http\Request;
use Square1\Mpp\Payment\PaymentSpec;
use Symfony\Component\HttpFoundation\Response;

class PostExists
{
    public function check(Request $request, PaymentSpec $spec): ?Response
    {
        return Post::find($request->route('post'))
            ? null
            : response()->json(['error' => 'No such post.'], 404);
    }
}
```

Attach route-specific checks the same way as other arguments, pipe-separated and ordered, on the middleware or the attribute:

```php
Route::get('/posts/{post}', ShowPost::class)
    ->middleware('mpp:1.00,USD,scope=post.view,preconditions=postexists');

#[RequiresPayment(amount: '1.00', scope: 'post.view', preconditions: ['postexists'])]
public function show() { /* ... */ }
```

Checks are additive and composed in order: the `global` checks run first, then the route's own, de-duplicated. The first check that returns a response wins, and the rest do not run, so a global `usernotblocked` short-circuits before a route's `postexists` ever fires. A name that is not defined in `checks` throws `InvalidConfigurationException`, so a typo fails closed rather than silently skipping a check.

Checks run on every guarded route, however it was declared: middleware arguments, `mpp` plus an attribute, or an attribute enforced automatically. The `PaymentSpec` they receive has already passed through any [price resolvers](#dynamic-pricing). So `$spec->amount` is the price this request will actually be charged, not the route's static one. A check can use that. For example, it can refuse a purchase above a caller's spending cap.

If a request can only be judged after settlement, you have to refund instead. A refund is worse for the buyer and is rail-specific. Prefer a precondition wherever existence or eligibility can be determined up front.

## Session Storage

A metered route (`grants > 1`) issues a session, which is a prepaid credit balance the server keeps between requests. The agent holds only the session id. The server holds the remaining count and decrements it on each request, so it must store that balance somewhere. Once-off routes (`grants = 1`) never create a session, so you only need a session store if you use metered access.

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
| `settlement_replay_ttl` | How long a settled response stays replayable for retries, in seconds. Default: `300`. |
| `replay_max_bytes` | Largest response body snapshotted for replay. Streamed, binary, or larger responses are not replayable. Default: `262144`. |
| `settle_lock_ttl` | Settlement lock lifetime in seconds. Must outlive the slowest rail. Default: `300`. |
| `cache_store` | Cache store for challenges, the replay ledger, and the settlement lock. Null follows the app default. Production multi-node needs a shared atomic store. |
| `default_method` | Primary settlement method. Default: `stripe`. |
| `defaults.amount` | Global price fallback. Leave null to require each route to set a price. |
| `defaults.currency` | Global currency fallback. Default: `USD`. |
| `defaults.grants` | Global grants fallback. Default: `1`. |
| `methods.stripe.*` | Stripe verifier settings. |
| `methods.tempo.*` | Tempo verifier settings. |
| `sessions.*` | Metered session storage settings. |
| `attributes.enabled` | Enables automatic `#[RequiresPayment]` enforcement. Default: `false`. |
| `attributes.middleware_groups` | Route groups used by automatic attribute enforcement. Default: `['web', 'api']`. |
| `price_book` | Named pricing presets. |
| `pricing.resolvers` | Named `[Class::class, 'method']` price resolvers, keyed by the name routes reference. |
| `pricing.global` | Resolvers applied to every gated route, before route-specific ones. |
| `preconditions.checks` | Named `[Class::class, 'method']` checks, keyed by the name routes reference. |
| `preconditions.global` | Checks run on every gated route, before route-specific ones. |

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

An entry can also carry its own `preconditions` and `pricing` lists, so every route using it inherits them:

```php
'price_book' => [
    'report.basic' => [
        'amount' => '5.00',
        'currency' => 'USD',
        'grants' => 10,
        'pricing' => ['tiered'],
        'preconditions' => ['usernotblocked'],
    ],
],
```

Either list may be written as an array or pipe-separated (`'tiered|regional'`). A route that names its own `pricing=` or `preconditions=` replaces the entry's list rather than adding to it.

### Configuration Validation

The gate checks built-in rail configuration before it mints a challenge.

| Rail | Missing config | Result |
| --- | --- | --- |
| Stripe `secret_key` | Settlement cannot run. | Logs once, still emits `402`. |
| Stripe `network_id` / `payment_method_types` | The challenge would be non-conformant (a wallet cannot scope an SPT to you). | Throws `InvalidConfigurationException`. |
| Tempo `recipient`, `token`, or `chain_id` | The challenge would be unpayable or unsafe. | Throws `InvalidConfigurationException`. |
| Tempo `rpc_url` | Settlement cannot broadcast the transaction. | Logs once, still emits `402`. |

Custom verifiers are responsible for their own configuration validation.

### Production

Serve payment-gated routes and the discovery endpoint over HTTPS. The MPP core and discovery drafts require TLS, because a `402`, its credential, and the settlement proofs carry payment-sensitive material. This package fails closed: over plain HTTP it refuses to issue a challenge or serve discovery and raises `InvalidConfigurationException`. When TLS terminates at a load balancer or proxy, configure Laravel's trusted proxies so the app sees the real scheme. Set `MPP_ALLOW_INSECURE=true` only for local development and testing over plain HTTP, and keep it off in production.

Set `MPP_CACHE_STORE` to a shared, atomic backend (Redis, Memcached, or database) on any multi-node deployment. Challenges, the settlement replay ledger, and the settlement lock all live in this store, and the single-use guarantee and the lock both need storage every node can see. The `array` driver is per-process and the `file` driver cannot lock across nodes, so with either one two nodes can settle the same challenge twice. Null follows the app default, which is fine for a single node or local development.

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
```

Then use it on a route with `method=acme`, or make it the house rail with `MPP_DEFAULT_METHOD=acme`.

If your rail's 402 `request` payload is not the default fiat shape (amount in minor units, currency, `methodDetails`), also implement a `Square1\Mpp\Protocol\Requests\RailRequestBuilder` and name it as `request_builder` in the method block. Without it, the default fiat builder mints a Stripe-shaped request for your rail, which will not match what your verifier expects. A genuinely fiat, Stripe-shaped rail can skip this.

The gate already checks that the challenge exists, is unexpired, was offered for the method, is scope-bound to the route, and has a valid signature. It also burns successful challenges and serializes concurrent settlement attempts. If your rail supports idempotency keys, use the challenge id.

### Wire Format

Most implementors do not build these headers by hand, because `npx mppx` and `mppx validate` drive the loop. They are still useful for debugging. The format is the MPP core spec's ([draft-httpauth-payment-00](https://paymentauth.org/draft-httpauth-payment-00)), and it is the same for every rail.

Unpaid response, with one `Payment` challenge per offered rail, comma-combined:

```http
HTTP/1.1 402 Payment Required
WWW-Authenticate: Payment id="...", realm="your-host", method="stripe", intent="charge",
    request="<base64url JCS JSON>", expires="...", opaque="<base64url JCS JSON>"
Content-Type: application/problem+json
Cache-Control: no-store

{
  "type": "https://paymentauth.org/problems/payment-required",
  "title": "Payment Required",
  "status": 402,
  "detail": "Payment is required.",
  "challengeId": "..."
}
```

The `id` is an HMAC-SHA256 over the seven binding slots (`realm|method|intent|request|expires|digest|opaque`), so the challenge authenticates itself. Change any term and the id no longer verifies. `request` and `opaque` are base64url-encoded canonical JSON (JCS, the JSON Canonicalization Scheme in RFC 8785). `request` carries the rail-specific economic terms. `opaque` carries a per-mint random `nonce`, this package's `scope`, the bound request `resource`, and, for metered routes, `grants`. Both are tamper-proof in the same way.

Paid retry, with a single base64url credential echoing the challenge and the rail proof in `payload`:

```http
Authorization: Payment <base64url of {"challenge": {...echoed params...}, "payload": {"spt": "spt_..."}}>
```

Rail payloads differ. Stripe presents `{"spt": "..."}`. Tempo presents `{"type": "transaction", "signature": "0x..."}` plus a top-level `source` DID (Decentralized Identifier). Custom rails define their own. The package's generic `proof()` accessor reads `proof`, `spt`, or `hash`.

Successful response:

```http
Payment-Receipt: <base64url of {"status": "success", "method": "...", "reference": "...", "timestamp": "...", ...}>
```

Metered follow-up (a package extension, a prepaid session spend rather than a payment):

```http
Authorization: Payment session="sess_..."
```

### Security Notes

- Challenges are HMAC-signed over the payment terms and expiry.
- A challenge is bound to its concrete request target (HTTP method, path, and query) and, for a request with a body, to that body's digest. Settlement enforces both, so a challenge for one target or body cannot settle another. Replay is gated by a non-reversible fingerprint of the credential, body, and target, so the challenge id alone cannot retrieve a paid response.
- A paid retry must echo the complete selected challenge unchanged, alongside the rail proof.
- A dynamically resolved price binds at mint time. Settlement verifies against the stored challenge, so re-resolving cannot change what a buyer was quoted.
- Waiving a charge must be explicit (`free => true`). A zero or unparseable resolved amount throws instead of serving free.
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
