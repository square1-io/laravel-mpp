![Tests](https://github.com/square1-io/laravel-mpp/actions/workflows/tests.yml/badge.svg)

# Laravel MPP

Charge AI agents for access to Laravel routes with the [Machine Payments Protocol (MPP)](https://mpp.dev).

`square1/laravel-mpp` returns a `402 Payment Required` challenge for a protected route. An agent that supports MPP pays the challenge, retries the request, and receives the response with a payment receipt. You set the price for each route. You can also issue a metered session, in which one payment grants several accesses.

The package includes two payment rails:

- [Stripe](https://stripe.com) Shared Payment Tokens ([SPTs](https://docs.stripe.com/agentic-commerce/concepts/shared-payment-tokens)), settled as [PaymentIntents](https://docs.stripe.com/payments/payment-intents).
- [Tempo](https://tempo.xyz) pathUSD, paid by the standard [`npx mppx`](https://mpp.dev) client.

## Readiness

You can use the Laravel middleware, the signed challenges, the receipts, the metered sessions and the storage drivers in production.

The Stripe rail uses Stripe Shared Payment Tokens, which currently use preview APIs. Use the rail for development in test mode, for demonstrations, and for a pilot or live flow that Stripe has approved. The shape of the API, the behaviour of the Dashboard, and the availability of a buyer wallet can change while the agentic-commerce APIs of Stripe are in preview. Test mode works everywhere. As of August 2026, Stripe restricts live acceptance to companies in North America.

The Tempo rail settles pathUSD from the standard `mppx` client. It uses the Moderato testnet by default, and you select mainnet through the environment. Both rails use the same MPP wire format, and one route can offer both. Check conformance with `npx mppx validate`.

MPP is a new protocol, and it changes quickly. This package follows the published spec as the spec changes.

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
- [Discovery](#discovery)
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

By default, the package derives the challenge signing key from `APP_KEY`. Set `MPP_CHALLENGE_SECRET` in production if you want to rotate the MPP signing key separately. A rotation invalidates only the `402` challenges that are in flight. It does not invalidate a session that the server has issued.

```dotenv
MPP_SESSION_DRIVER=cache
MPP_CHALLENGE_SECRET=
```

## Quickstart

This example uses Stripe test mode, and transacts directly with a Shared Payment Token. Test mode works wherever your Stripe account is based. As of August 2026, Stripe restricts live acceptance to an account based in North America, and this includes the [Link](https://link.com) buyer wallet. The test-mode flow below is therefore the test path that Stripe supports most widely today.

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

The response is a `402 Payment Required` in the MPP wire format ([spec](https://paymentauth.org/draft-httpauth-payment-00)). The `WWW-Authenticate` header carries the terms. It holds one `Payment` challenge per offered rail. Each challenge authenticates itself. Its `id` is an HMAC, which is a keyed signature, over every bound field. No one can therefore change the terms between the quote and the paid retry.

```http
HTTP/1.1 402 Payment Required
Content-Type: application/problem+json
Cache-Control: no-store
WWW-Authenticate: Payment id="...", realm="your-host", method="stripe",
    intent="charge", request="eyJhbW91bnQiOiIxMDAi...", expires="2026-08-18T15:05:00Z",
    opaque="eyJub25jZSI6..."
```

Every challenge carries an `opaque` parameter. It holds a random nonce for that mint, and the binding data of this package. A conformant client echoes it back without a change, with the other parameters.

The `request` parameter is base64url JSON. It carries the economic terms: the amount in minor units, the currency, and the Stripe network profile. The body is a plain RFC 9457 problem document:

```json
{
  "type": "https://paymentauth.org/problems/payment-required",
  "title": "Payment Required",
  "status": 402,
  "detail": "Payment is required.",
  "challengeId": "..."
}
```

That response confirms that the seller side works. To complete the payment loop yourself in test mode, see [Testing Stripe End to End](#testing-stripe-end-to-end).

## Choose a Payment Rail

Stripe is the default primary method. Use Tempo per route with `method=tempo`, or globally with `MPP_DEFAULT_METHOD=tempo`.

### Stripe

Stripe settlement uses Shared Payment Tokens. The verifier creates a PaymentIntent from the SPT that the buyer presents, and then confirms it.

```dotenv
STRIPE_SECRET_KEY=sk_test_...
STRIPE_NETWORK_ID=profile_...
STRIPE_API_VERSION=2026-05-27.preview
```

The package needs `STRIPE_SECRET_KEY` to settle a payment. It still emits a `402` without the key, but settlement fails until you set it.

`STRIPE_NETWORK_ID` is the Stripe profile id that the challenge advertises. A Link wallet or an agent wallet uses it to restrict an SPT to your business. The settlement call on the server does not use it. A live buyer flow through Link does depend on it, and on the availability of Stripe for your buyer account and seller account.

To get a profile id:

1. Open [Stripe profile](https://dashboard.stripe.com/profiles) in the Stripe Dashboard.
2. Create a profile for your business.
3. Use the resulting `profile_...` value as `STRIPE_NETWORK_ID`.

Stripe SPT support uses preview APIs. Build against test mode first. Pin the Stripe API version. Read the changelog of Stripe and the changelog of this package before you upgrade. Test mode works wherever your account is based.

### Testing Stripe End to End

In development, you can mint a test SPT yourself. You can then run the full `402 -> mint SPT -> retry -> 200` loop without Link.

One test account is enough, because the same `sk_test_...` key can both mint the SPT and settle it. The steps below use two accounts instead. Two separate accounts are closer to the conditions in production.

- Seller account: the `STRIPE_SECRET_KEY` of the Laravel application. This account creates the PaymentIntent and confirms it.
- Buyer account: a different `sk_test_...` key, which you use only to mint the test SPT. It replaces the buyer wallet that issues the SPT in production.

First request the challenge. Then copy every parameter from the `WWW-Authenticate` header. A conformant client echoes the challenge back in its credential, without a change.

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

Send the original request again, with a credential in the spec format. The credential is base64url JSON of `{challenge, payload}`. The `challenge` field echoes the parameters of the header, and `opaque` is one of them. The `payload` field carries the SPT. The echo must match every parameter that the header sent, so copy `opaque` exactly.

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
  "timestamp": "2026-08-18T15:01:12Z"
}
```

The `reference` value is the Stripe PaymentIntent id. The receipt carries four fields: `status`, `method`, `timestamp` and `reference`.

You rarely build a credential by hand. `npx mppx validate https://your-host --endpoint GET:/resource --yes` runs the whole loop for you.

A card has a minimum charge amount, which is often about \$0.50 or EUR 0.50. Price a route that a card pays for above that minimum. You can also use a metered bundle, where the one charge is above the minimum.

### Per-Payer Stripe Customers

By default, a Stripe payment is a guest charge. Set `methods.stripe.customer_resolver` to attach a Stripe Customer from your seller account to the PaymentIntent. Use it when the paid retry already carries an identity that you trust, such as an authenticated user or an API key.

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

The resolver returns a `cus_...` id from the same Stripe account as `STRIPE_SECRET_KEY`. It runs on the paid retry, so every identity that it reads must be present on that retry. In an API-key workflow, resolve the key to one of your own accounts, and return the Stripe Customer id of that account.

An open agent-payment endpoint often needs no resolver. A guest PaymentIntent with metadata is usually enough, because the SPT proves the authority to pay. It does not identify a stable customer on the seller side.

When the resolver returns `null`, or throws, the package makes a guest charge instead.

### Tempo

Tempo settlement accepts pathUSD from the standard `npx mppx` client. The agent signs a pathUSD transfer and pays the gas. Your server broadcasts the signed transaction, and then confirms that the network mined it.

```dotenv
TEMPO_RECIPIENT=0x...

# Defaults target the Moderato testnet. For mainnet, set all three together:
# TEMPO_RPC_URL=<mainnet RPC URL>
# TEMPO_CHAIN_ID=4217
# TEMPO_TOKEN=0x20C000000000000000000000b9537d11c60E8b50
```

`TEMPO_RECIPIENT` is required. The RPC URL, the chain id (`42431`) and the pathUSD token address default to the Moderato testnet. A client can therefore pay the rail as soon as you set a recipient.

The token address is different on each network. On the testnet, pathUSD is `0x20c0000000000000000000000000000000000000`. On mainnet, pathUSD is `0x20C000000000000000000000b9537d11c60E8b50`. A token from one network, on the chain of the other network, reverts with `TIP20: Uninitialized`. Always change the chain id, the RPC URL and the token together.

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

Use this flow to see a real Tempo testnet transfer arrive at a recipient address.

Create a temporary recipient address with [Foundry](https://getfoundry.sh). Install Foundry with [`foundryup`](https://book.getfoundry.sh/getting-started/installation), then create a local test wallet with [`cast wallet new`](https://getfoundry.sh/cast/reference/wallet/new/):

```bash
foundryup
cast wallet new
```

Copy the `address` value that the command generates, and set it as `TEMPO_RECIPIENT` in the Tempo configuration above. This address receives the testnet payment. Keep the generated private key only if you intend to reuse the address, or to move funds from it.

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

The successful response carries a `Payment-Receipt` header. The header is base64url JSON. Its `reference` value is the transaction hash.

```json
{
  "status": "success",
  "method": "tempo",
  "reference": "0x...",
  "timestamp": "..."
}
```

View the recipient address in the Tempo testnet explorer:

```text
https://explore.testnet.tempo.xyz/address/0x...
```

Replace `0x...` with the address that you set as `TEMPO_RECIPIENT`. The explorer shows the incoming pathUSD transfer after the network mines the transaction.

Both rails use the same wire format, so one route can offer Tempo and Stripe together. See [Offering Both Rails on One Route](#offering-both-rails-on-one-route).

### Offering Both Rails on One Route

One route can offer both rails in one `402`. The MPP wire format supports this. A `402` carries one `Payment` challenge per offered rail, in `WWW-Authenticate`. Each challenge has its own binding id. The client answers exactly one of them. The response format does not require the client to choose a rail before it reads the quote.

Offer both rails on one route:

```php
Route::get('/resource', MyPaidResource::class)
    ->middleware('mpp:0.50,USD,methods=stripe|tempo');
```

You can also make several rails the default for every route. No route then names a rail:

```dotenv
MPP_ACCEPT=stripe|tempo
```

A `methods=` option on a route overrides the set in the config, and so does a `methods:` argument on the attribute. A single `method=` still restricts a route to one rail. A configuration with one rail emits a `402` that is byte-identical to the `402` of an earlier version of this package.

The `402` then carries two challenges:

```http
WWW-Authenticate: Payment id="...", realm="...", method="stripe", intent="charge", request="...", expires="..."
WWW-Authenticate: Payment id="...", realm="...", method="tempo", intent="charge", request="...", expires="..."
```

What a client reads depends on its HTTP library:

- **A client that uses `fetch`, in Node or in a browser, joins the lines again.** The WHATWG Headers spec states that `headers.get('www-authenticate')` returns the repeated lines joined with `, `. That is one string, and it carries every challenge. An agent built on `fetch`, and `mppx` is one, therefore reads the same bytes whether the server sends one joined line or several lines.
- **A header bag on a server usually returns only the first line.** The `$response->headers->get()` method of Laravel and Symfony returns one line. Use `->all('WWW-Authenticate')` when you need every challenge. Otherwise a response with several rails looks like a response with one rail.

Each challenge is bound on its own, and a client can spend each one on its own. To settle the Stripe challenge burns only that challenge. On the paid retry, the package dispatches by the method of the stored challenge. A credential therefore cannot move a payment to a different rail.

A caller selects a rail with the `Accept-Payment` request header. It is a standard MPP header, so you document nothing of your own for your agents:

```http
GET /resource HTTP/1.1
Accept-Payment: tempo/charge, stripe/charge;q=0.3
```

A payment client can send the same preference. For example, this command selects
Tempo on a route that offers both methods:

```bash
npx mppx https://your-host/resource \
  -H 'Accept-Payment: tempo/charge' \
  --network testnet --account <your-account>
```

The server filters and ranks its offered challenges by the header. The q values, the wildcards such as `tempo/*`, and the `q=0` exclusions all work as they do in `Accept`. A caller that sends no header receives the full offered set, in the order that you configured. The spec states that a server ignores a header that matches nothing, so a caller can never obtain a rail that you did not offer.

> **List the rail that your callers can pay first.**
>
> The spec asks a client to choose a challenge by capability: "clients SHOULD select one based on their capabilities and user preferences". Not every client does so. `npx mppx` 0.9.2 takes the first challenge in the set. It does not check whether it can pay that challenge, and it fails when it cannot:
>
> ```
> Error (REQUEST_FAILED): Request failed: Invalid CLI options
> (paymentMethod: Invalid input: expected string, received undefined)
> ```
>
> On `methods=stripe|tempo`, an agent that can pay only with crypto receives the card challenge, and it fails. On `methods=tempo|stripe`, the same agent pays. The `402` is correct in both cases. The only difference is which rail leads the set.
>
> Two conclusions follow. First, list the rail that your usual caller can pay. Second, treat "both rails from one URL" as correct only for a caller that sends `Accept-Payment`. For any other caller, you offer the first rail, and the caller ignores the rest. The order that you write is the order that you get.

### Validating Conformance

Point the reference validator at your application. It tests discovery, the challenge format, and the error handling. On the testnet, with a wallet that it funds automatically, it can also run an on-chain tempo settlement:

```bash
npx mppx@latest validate https://your-host --endpoint GET:/resource --yes
```


## Discovery

The package serves an advisory OpenAPI 3.1 document at `/openapi.json`, and generates it from the live router. Every payment-gated route appears with its `x-payment-info` offers. The package resolves those offers in the same way as the runtime does, through the price-book entries, the global defaults and the request builder of each rail. A route that the package prices per request emits `amount: null`.

An MPP agent reads the document to find a payable endpoint before it calls you. A registry crawls it to list your service. `mppx validate` checks it.

The discovery draft requires the document at `GET /openapi.json`, so the path is fixed. Disable it if your app serves its own OpenAPI document, and merge the `x-payment-info` extension there instead:

```dotenv
MPP_DISCOVERY=false
```

**You never configure a price here.** Everything under `x-payment-info` comes from the router, and from the request builders that mint the 402. The document therefore cannot advertise a price that the gate does not charge. Everything else in this section is documentation: what the operation does, what to send to it, what it returns, and who operates the service.

### What the Service Is

The `info` object, the `servers` array and the `x-service-info` extension of the draft do not change per route, so they are config. Where your application already states a value, that value is the default:

```dotenv
MPP_DISCOVERY_TITLE="Clip API"          # defaults to APP_NAME
MPP_DISCOVERY_VERSION=2.1.0             # your API's version
MPP_DISCOVERY_DESCRIPTION="Paid video endpoints."
MPP_DISCOVERY_CATEGORIES=media,compute  # x-service-info.categories
MPP_DISCOVERY_HOMEPAGE=https://example.com/docs
MPP_DISCOVERY_API_REFERENCE=https://example.com/reference
MPP_DISCOVERY_LLMS=https://example.com/llms.txt
```

`servers` follows `APP_URL` until you set `mpp.discovery.servers`. Set it when another host serves the API, or when a path prefix does. The contact and licence details are in `config/mpp.php`. The package omits an empty value, and does not publish it blank.

You can write a documentation link as a relative link, such as `/llms.txt`. The package publishes it as an absolute link, resolved against your service URL as RFC 3986 §5.3 defines. The draft types these links `format: uri` and requires RFC 3986 conformance, so a relative reference fails a strict validator. A registry that stored `"/"` also has nothing to follow.

The resolution rules matter when your `servers` URL carries a path, such as `https://example.com/api`. A link that starts with a slash resolves against the root of the host and drops that path, so `/llms.txt` becomes `https://example.com/llms.txt`. A link without one resolves against the directory of the base path. A protocol-relative link such as `//cdn.example.com/llms.txt` keeps its own host and takes the scheme of the base.

The two response headers that the draft recommends are on by default. They are `Cache-Control: public, max-age=300` and `Access-Control-Allow-Origin: *`. Set `mpp.discovery.cache_control` or `mpp.discovery.allow_origin` to `null` to omit either one.

### What an Operation Is

Documentation for one operation belongs with the route. You can write it in three places. Which place to use depends on where you defined the route, not on what you want to state.

On the action, next to `#[RequiresPayment]`:

```php
use Square1\Mpp\Attributes\DiscoveryInfo;
use Square1\Mpp\Attributes\RequiresPayment;

#[RequiresPayment(amount: '0.50', currency: 'USD', methods: ['tempo', 'stripe'])]
#[DiscoveryInfo(
    summary: 'Clip a video',
    description: 'Returns a clip of the source video, starting at the requested offset.',
    priceNote: ['tempo' => 'On-chain, per clip.', 'stripe' => 'Card, per clip.'],
    tags: ['media'],
    request: ClipRequest::class,
    response: ['type' => 'object', 'required' => ['url'], 'properties' => [
        'url' => ['type' => 'string', 'format' => 'uri'],
    ]],
)]
public function clip(ClipRequest $request) { /* … */ }
```

On the route, for closures and route-file definitions:

```php
Route::post('/clip', fn () => /* … */)
    ->middleware('mpp:0.50,USD')
    ->discovery(summary: 'Clip a video', priceNote: 'Per clip, whatever its length.');
```

The macro takes the same arguments as the attribute. You can also spread an array of them into it, as `->discovery(...$stated)`. The package registers it as `discovery()`, unless your application already defines a macro with that name. `mppDiscovery()` is always available.

In the config, for a route that you did not define and cannot annotate. Key the entry by route name, which is the preferred form because it survives a change of URL. You can also key it by `"GET /uri"` or by `"/uri"`:

```php
'operations' => [
    'reports.show' => [
        'summary' => 'Fetch a report',
        'priceNote' => 'Per report.',
        'parameters' => ['year' => 'Four-digit year.'],
        'query' => ['format' => 'Output format.'],
    ],
],
```

The three places merge field by field, and the place nearest to the route wins. The order is the route, then the action, then the config. A summary in the config therefore survives a route macro that sets only a price note.

`#[DiscoveryInfo]` and `->discovery()` take eleven fields: `summary`, `description`, `priceNote`, `tags`, `operationId`, `request`, `response`, `parameters`, `query`, `deprecated` and `hidden`.

`priceNote` becomes the `description` of each offer. Pass a string for one note across every rail, or a map keyed by method name to describe each rail separately. `hidden: true` keeps a route out of the document, and the route stays payable.

Every field is unset until you state it, and that includes `deprecated` and `hidden`. A `hidden: false` nearer the route therefore overrides a `hidden: true` further from it.

### Input and Output Schemas

The draft asks every payable operation to declare an input schema. A client or a registry can mark an operation without one as "schema-missing". When your action type-hints a `FormRequest`, you have already written that schema, and the package reads it there:

```php
public function rules(): array
{
    return [
        'url' => 'required|url',
        'seconds' => 'required|integer|min:1|max:60',
        'format' => 'required|in:mp4,webm',
        'tags' => 'array|max:5',
        'tags.*' => 'string',
    ];
}
```

Those rules become types, formats, bounds, enumerations, nesting and requiredness in the published `requestBody`.

On a verb that carries no body, the same rules describe the query string instead, so the package publishes them as `in: query` parameters. A `GET` action that type-hints a `FormRequest` therefore documents its query without further work. A nested rule such as `filter.status` needs an OpenAPI serialization style that the rules do not state, so the package leaves those out rather than choose one.

A rule that states a database fact rather than a shape, such as `unique` or `exists`, contributes nothing. The package ignores a rule that it does not recognise, and does not infer a meaning for it. The 422 response stays authoritative for the rest, in the same way as the 402 response does for the price.

Set `MPP_DISCOVERY_FORM_REQUESTS=false` to turn this off. Each operation then falls back to the permissive `{"type": "object"}` body that the package has always emitted.

`request` and `response` also take a JSON Schema array, a full OpenAPI `requestBody` or response array, or the name of a class. `response` takes a plain schema for the 200 response, or a map keyed by status code, and every entry of that map takes the same three forms:

```php
#[DiscoveryInfo(response: [
    '200' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string']]],
    '404' => ['description' => 'No such video.'],
])]
```

An inline array is the shortest form for a small shape, and the wrong form for a shape that several routes share, or for one long enough to hide the rest of the attribute. A class states a longer shape, and implements `ProvidesSchema`:

```php
use Square1\Mpp\Discovery\ProvidesSchema;

final class ScoreSchema implements ProvidesSchema
{
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['score'],
            'properties' => ['score' => ['type' => 'integer']],
        ];
    }
}

#[DiscoveryInfo(response: ['200' => ScoreSchema::class, '404' => NotFound::class])]
```

The method is static, so the package reads a schema without building the class. A `FormRequest` cannot implement the interface, because it belongs to Laravel, so the package reads its `rules()` instead. Those are the only two forms a class name takes; the package logs anything else and leaves the schema out.

The package logs a schema that it cannot resolve, and leaves it out. The document is advisory. An incorrect schema reference costs the operation its schema. It never costs the operation its entry, and it never stops the route from charging.

### What the Router Already Knows

You write nothing at all for some of the document:

- The **name** of a route becomes its `operationId`. The package omits the id when a route serves several verbs or several paths, because OpenAPI requires the id to be unique.
- The package declares the **path parameters**, and carries a `where()` constraint across as an anchored `pattern`. An optional Laravel parameter, such as the one in `/report/{year}/{month?}`, becomes two OpenAPI paths and not one optional parameter. An OpenAPI path parameter is always required.
- A path parameter states a **type** when either of two signals gives one. The action can type-hint the argument, as in `show(int $id)`. A `where()` constraint can also match digits and nothing else, which is what `whereNumber()` assigns. A bounded constraint such as `[0-9]{4}` states a length as well as a type, so it stays a string with a `pattern`, which keeps both.
- A **docblock** on the action becomes the `summary` and the `description` of the operation. The first paragraph is the summary, and the rest is the description. The package drops the annotation tags. This is **off by default**. An author writes a docblock for colleagues, and it can state things that you would not publish to an unauthenticated endpoint that registries crawl. Read your docblocks, and then set `MPP_DISCOVERY_DOCBLOCKS=true`.

### Free Routes

A paid API usually has free parts, such as a redirect, a status endpoint, or the free tier of a paid endpoint. An agent that plans a call needs to know about them, or it pays for a request to find them. The document lists only payment-gated routes by default. Name the free routes to list them too:

```php
'include' => ['links.redirect', 'GET /health', 'api/public/*'],
```

The package matches the patterns against the same keys as `operations`. Those keys are the route name, `"GET /uri"` and `"/uri"`, and the path works with or without its leading slash. The patterns accept `*` wildcards.

The package documents a listed free route in the same way as any other route, with a summary, parameters and schemas. Such a route carries no `x-payment-info` and no `402`. It is not payable, and a `402` would tell a client something that is not true.

Keep the patterns specific. A broad pattern publishes your route table on an unauthenticated endpoint that registries crawl. That is a decision about disclosure, and not a convenience. The discovery document never lists itself.

### Anything Else

OpenAPI is larger than the part of it that the payment drafts use. You can need a part that the package does not model, such as `components`, a security scheme, or free routes beside the paid ones. Post-process the finished document for those:

```php
// config/mpp.php
'pipeline' => [
    [DiscoveryComponents::class, 'handle'],
],
```

```php
class DiscoveryComponents
{
    public function handle(array $document): array
    {
        $document['components'] = ['schemas' => [/* … */]];

        return $document;
    }
}
```

The stages run in order. Each stage takes the document array and returns it. Each stage is a `[Class::class, 'method']` pair that the container resolves, so the list survives `php artisan config:cache`. The package logs a stage that throws, or that returns a value other than an array, and then skips it. A post-processor that fails must not stop discovery.

The pipeline is the one place where you can make the document non-conformant. The draft publishes a JSON Schema for each of its two extensions, and both schemas set `additionalProperties: false`. An extra key on an offer, such as a `recipient` or a `price` block, therefore matches neither branch of the schema, and a strict registry rejects it.

The test suite of this package validates the generated document against those schemas. The tests hold a copy of each schema, taken from the draft without a change, in `tests/Fixtures`. Run the same check over your own document if you use the pipeline.


## Protecting Routes

You can protect a route with middleware arguments, with a controller attribute, or with automatic attribute enforcement.

### Middleware

```php
Route::get('/resource', MyPaidResource::class)
    ->middleware('mpp:0.50,USD');

Route::get('/report', ReportController::class)
    ->middleware('mpp:5.00,USD,grants=10,scope=report.basic');
```

You can also name a [price book](#price-book) entry by its key:

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

The package then enforces payment on an annotated controller action, and you add no `mpp` middleware to the route:

```php
#[RequiresPayment(amount: '0.50', currency: 'USD')]
public function latest()
{
    // ...
}
```

Automatic enforcement is off by default. It runs on the configured route groups, which are `web` and `api` by default. The package skips a route that already carries the `mpp` middleware, so that route is not charged twice.

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

`scope` is a label that you choose for the priced resource. A metered session can be spent only within its scope. When you omit the label, the package derives one from the URI of the route.

When you list several methods, the first one is the primary method. It leads the challenge set, before the caller reorders the set with `Accept-Payment`. The `challengeId` in the body names it.

This rule holds wherever you write the list: in the `methods=` option of a route, in the `methods:` argument of the attribute, or in `MPP_ACCEPT`. `MPP_DEFAULT_METHOD` chooses the rail for a route that names none, and it does not reorder a list that a route names. A single `method=` is the one option that overrides the order, and it takes the primary place for that route.

### Defaults

Use the defaults so that you do not repeat a price or a rail setting:

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

Leave `MPP_DEFAULT_AMOUNT` unset when every protected route is to declare its own price.

## Metered Access

> Metering is an experimental extension. A metered session is a feature of
> this package, and it is not part of the MPP core spec. The prepaid-session
> mechanism is this package's own design on top of the `Payment` scheme of the
> spec. That mechanism is the `session="…"` credential form and the
> `Payment-Session` response header.
>
> The spec is preparing a general `session` intent
> ([mpp-specs PR #280](https://github.com/tempoxyz/mpp-specs/pull/280)). This
> package intends to follow that intent when the spec adopts it. The change may
> not be backward compatible.
>
> Metering is stable enough to build one deployment on. Pin the version of the
> package, and expect a migration note before you adopt a release that follows
> #280. A once-off charge, which is the default `grants` of 1, follows the spec
> exactly, and carries no such warning.

Set `grants` above `1` when one payment is to grant several accesses:

```php
Route::get('/report', ReportController::class)
    ->middleware('mpp:5.00,USD,grants=10,scope=report.basic');
```

The paid request spends the first credit and returns a `Payment-Session` header:

```http
HTTP/1.1 200 OK
Payment-Receipt: <base64url JSON: {"status":"success","method":"stripe","reference":"pi_...","timestamp":"..."}>
Payment-Session: id="sess_...", remaining="9", scope="report.basic", expiresAt="..."
```

Use the session again on a later request. The credential is `Payment session="..."` alone. Add no other auth-param to it:

```bash
curl -si https://your-host/report \
  -H 'Authorization: Payment session="sess_..."'
```

Each successful request decrements the balance, and returns the updated `Payment-Session` header. When the session has no credits left, or when it expires, the next request receives a fresh `402`.

The package checks the scope of each spend, and each spend is atomic. Concurrent requests cannot spend more credits than the server granted to the session.

Metering works in the same way on both rails. A Tempo payment for a metered route also issues a session. You use that session again with the same `Authorization: Payment session="sess_..."` header as above.

## Retries and Idempotency

A payment moves real money. A client can retry for several reasons: the response was lost, the connection dropped, or two requests raced. The client is not to pay twice.

The gate removes the duplicates among the retries and the concurrent requests for one challenge. After a challenge settles, a retry that echoes it receives the recorded result, and not a new charge.

This covers the common cases. It does not by itself make a side effect safe against a crash. When the process stops after the charge and before the package records the response, the gate cannot replay a result that it never stored. Add idempotency in your application for a side effect that must survive that window, for example an `Idempotency-Key` on the action. The section below describes the crash window in full.

A challenge is single-use, and the first successful settlement burns it. What a later request receives depends on the reason for that request:

| Situation | Response | Meaning for the client |
| --- | --- | --- |
| First valid payment | `200` plus `Payment-Receipt` | Settled. |
| Retry of an already-settled challenge (for example, the `200` was lost) | `200` plus the original `Payment-Receipt` | Already paid. Here is the receipt again. No second charge. |
| Retry while the first settlement is still in flight | `409` plus `Retry-After` | Wait and retry. Do not pay again. |
| An unknown, expired, or tampered challenge | `402` plus a fresh challenge | This one needs paying. |

The differences are deliberate. Only a `402` means "pay". A `409` means that the payment is in progress, so the client waits. A replayed `200` means that the client has already paid. A conformant agent that reads these signals does not pay twice on a retry.

This holds within the limits below. The gate cannot replay a response that it never recorded, so such a retry takes a fresh `402`. That happens after a crash before the record, and for a streamed, binary or over-limit response. Add idempotency in your application for a side effect that must survive that window.

The package keeps a settled response in a ledger for a short time, which `mpp.settlement_replay_ttl` sets and which defaults to five minutes. It can then match a retry to that response. A metered payment replays the same session. The package spends no extra credit, and issues no second session. Set the retention above the retry and timeout window of your clients:

```dotenv
MPP_SETTLEMENT_REPLAY_TTL=300   # seconds a settled payment is replayable (default 300)
```

The challenge id alone does not grant a replay. The id travels in the `402` and in the credential, so it is not a secret. The gate stores a fingerprint that no one can reverse, over three things: the successful credential, the request body, and the concrete request target. It replays only when a retry matches that fingerprint. A different credential, a changed body, or a different target does not receive the paid response.

The package snapshots a response for replay only when the response is buffered and within `mpp.replay_max_bytes`, which defaults to 256 KB. It does not store a streamed or binary response, or a response over the limit. A retry after a lost response therefore takes a fresh `402`, and not an empty or truncated body.

Such an endpoint is therefore not idempotent after a lost response, and a dropped connection can charge its buyer again. Keep a paid endpoint buffered and within the limit when you need replay. Otherwise make the endpoint idempotent in your application.

A lock serialises the concurrent settlements of one challenge. Its lifetime covers the slowest rail, because an on-chain confirmation on Tempo can take tens of seconds. Two requests can never settle one challenge in parallel. The second request receives the `409` above.

> One window stays open by design. The protected action runs before the ledger
> write and before the challenge burn. When the server process or the cache
> stops in that period, the challenge can stay live, and the action can run a
> second time on a retry. The window is a few milliseconds long.
>
> To close the window completely would need a distributed transaction across
> the rail, the ledger and your application. MPP does not assume such a
> transaction. Exactly-once execution of an arbitrary controller side effect is
> therefore the responsibility of your application. Use your own
> Idempotency-Key handling, or a transactional state machine, for an action
> that must never repeat. On a card rail and on an on-chain rail, the practical
> exposure of this window is very small.

## Dynamic Pricing

Everything above prices a route. Sometimes the price belongs to the request instead. A pro account pays \$2.50 where a free account pays \$5.00. A partner receives a larger bundle for the same money. A caller in another region pays in another currency.

A price resolver sets the price for each request. Register the resolver once, and name it on each route that it applies to. The package mints the resolved price into the `402`:

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

Attach it as you attach any other option:

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

One rule applies: something must supply a price before the gate. That is either the route or a resolver.

You choose which one, for each route, by whether you write an amount:

```php
// The route owns the default price. The resolver can overwrite it.
->middleware('mpp:5.00,USD,scope=report,pricing=tiered');

// The resolver owns the price.
->middleware('mpp:scope=report,pricing=tiered');
```

Write the amount when your endpoint has a real list price. That amount is then the price for every caller that the resolver does not recognise. It is also the price that the route falls back to when you disable the resolver later.

Omit the amount when you have no list price to state, as with pricing per use or per item. Do not write a placeholder that nothing reads.

On a route that a resolver owns, every resolver can decline. The package then cannot price the request, and raises `UnpriceableRequestException`. The exception names the route and the resolvers that ran.

This is deliberately a different exception from `InvalidConfigurationException`. The configuration is correct, and the cause depends on the request. The exception can therefore occur in production long after a deploy, and not once at boot.

When you set `mpp.defaults.amount`, every route has a house price, and this case cannot occur. A resolver that declines then falls back to that default.

### What a Resolver May Change

A resolver is a plain class with one method. You extend no base class, and the package treats no class specially. You return an array, and the package reads it. One return value can set any of these keys together, and not the amount alone.

| Key | Effect |
| --- | --- |
| `amount` | The price. Must be a positive number. |
| `currency` | The currency code, upper-cased for you. |
| `grants` | Accesses per payment. `> 1` issues a metered session. |
| `scope` | The label the payment and any session are bound to. |
| `free` | `true` serves the route without charging. |

Any other key throws `InvalidConfigurationException`, and that includes `method`. The package resolves the rails that a route offers once, from the `method=` or `methods=` option of the route and from the configured default. A resolver cannot change that set. A resolver sets the price, and not the payment terms around it. To offer several rails at once, see [Offering Both Rails on One Route](#offering-both-rails-on-one-route).

An `amount` that is zero, negative or not numeric also throws. You must state explicitly that you give a resource away. A resolver that calculates the amount incorrectly, or that reads an empty config value, therefore fails with a message. It does not make a paid endpoint free without one.

```php
return ['free' => true];    // yes, serve this one for nothing
return ['amount' => '0'];   // throws
```
`free => true` and an `amount` together throw for the same reason. The package is never to infer which one you intended.

A free request has no challenge, no session and no receipt. The package serves it as it serves an unguarded route. The preconditions of the route still run, so a free caller cannot reach a resource that a check would refuse.

One method returns every form. This example runs on a route that declares `mpp:9.00,USD,grants=3,scope=everything.list`:

```php
public function price(Request $request, PaymentSpec $spec): ?array
{
    return match ($request->user()?->tier) {

        // Only the price. Every other value on the route stays.
        'pro' => ['amount' => '2.00'],

        // Price, currency, bundle size and credit pool, all at once.
        'partner' => [
            'amount' => '18.00',
            'currency' => 'EUR',
            'grants' => 25,
            'scope' => 'everything.partner',
        ],

        // No charge. The same method, and the same return type. `free` is
        // another key. Do not add an 'amount' beside it, or the pair throws.
        'staff' => ['free' => true],

        // No opinion. This is NOT free: the price of the route stays.
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

Keep one difference clear. `null` means "no opinion", and not "no charge". To waive a charge is always `['free' => true]`. On a route that states no price of its own, that difference decides between a served request and an exception.

### Composition

Resolvers combine in the same way as preconditions. The global resolvers run first, then the resolvers of the route. Both run in the declared order, without duplicates. Each resolver receives the spec as the resolver before it left it, so a later resolver can build on an earlier one:

```php
->middleware('mpp:5.00,USD,pricing=tiered|regional')
```

Here `regional` sees the amount that `tiered` set, and not the default \$5 of the route. An unknown name throws, and does not fall back to the static price. A typo therefore cannot charge every caller the list price without a message.

### Pricing and Metered Sessions

A metered session is bound to a scope, and not to a payer. A session is a credit balance for its bearer. Any holder of the id can spend it within that scope.

When the price of a metered route varies, therefore vary its `scope` as well:

```php
'partner' => ['amount' => '2.00', 'grants' => 20, 'scope' => 'report.partner'],
```

Without a separate scope, any holder can spend credits that someone bought at \$2 within the same scope. That includes a holder who owes \$5. The package logs a warning when a resolver changes the price of a metered route and does not change its scope. It logs once per scope per process, which is once per request under PHP-FPM and once per worker under Octane. A once-off route, where `grants` is 1, never issues a session, and this does not apply to it.

### The Quote Is Binding

A resolver sets the price of a challenge, and not the price of a settlement. The package signs the amount into the `402` with an HMAC. Settlement verifies against that stored challenge, and never against a spec that the package resolves again. A resolver whose answer changes between the `402` and the paid retry therefore cannot change the price that you quoted to that buyer:

```
402  →  amount="2.00"   (caller was on the pro tier)
        ... their subscription lapses ...
retry →  settles at 2.00
```

The same rule holds in the other direction. A resolver that returns `free` after the package issued a `402` cannot burn or settle that challenge. A resolver that raises the price cannot charge an outstanding quote more than the quote stated. Only a new challenge carries the new price.

A resolver runs on every gated request, and that includes a paid retry and a session spend. Keep a resolver fast, and give it no side effects. Do not write an audit record in one.

The package ignores the resolved `amount` on those requests. It does not ignore the resolved `scope`. It spends a session against the scope that the resolver returns at that moment. When the tier of a caller changes while that caller holds credits, the session no longer matches, and the caller receives a fresh `402`. Keep the scope of a tier stable for as long as its sessions can live, which `MPP_SESSION_TTL` sets. You can also key the scope on a value that outlives the tier.

## Preconditions

The payment gate runs before your controller. On a paid retry, it settles the payment and then calls the controller. A 404 that the controller raises therefore arrives after the buyer has paid. The first unpaid request for a missing resource also returns a `402`, which tells an agent to pay for something that does not exist.

A precondition closes that gap. A precondition is a named check that runs before the package mints a `402` or settles a payment. It returns a response to reject the request, such as a `404` for a missing resource or a `403` for a blocked user. It returns null to let the request continue to the gate. Any logic that decides whether a request can ever be fulfilled belongs here, and not in the controller.

Define each check once in the config, and then attach it where it applies. Each check is a `[Class::class, 'method']` pair. The container resolves the pair, so the list survives `config:cache`. The package calls the pair with the request and the resolved `PaymentSpec`:

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

Attach the checks of a route in the same way as the other arguments, pipe-separated and in order, on the middleware or on the attribute:

```php
Route::get('/posts/{post}', ShowPost::class)
    ->middleware('mpp:1.00,USD,scope=post.view,preconditions=postexists');

#[RequiresPayment(amount: '1.00', scope: 'post.view', preconditions: ['postexists'])]
public function show() { /* ... */ }
```

The package adds the checks of a route to the global checks, and runs them in order. The `global` checks run first, then the checks of the route, without duplicates. The first check that returns a response ends the run, and the rest do not run. A global `usernotblocked` therefore rejects a request before the `postexists` check of a route runs. A name that `checks` does not define throws `InvalidConfigurationException`, so a typo fails closed and does not skip a check.

The checks run on every guarded route, however you declared it: with middleware arguments, with `mpp` and an attribute, or with an attribute that the package enforces automatically.

The `PaymentSpec` that a check receives has already passed through the [price resolvers](#dynamic-pricing). `$spec->amount` is therefore the price that the package will charge for this request, and not the static price of the route. A check can read it. For example, a check can refuse a purchase above the spending limit of a caller.

Some requests you can judge only after settlement. You must then refund the buyer instead. A refund is worse for the buyer, and each rail handles it differently. Use a precondition wherever you can determine existence or eligibility in advance.

## Session Storage

A metered route, where `grants` is above 1, issues a session. A session is a prepaid credit balance that the server keeps between requests. The agent holds only the session id. The server holds the remaining count, and decrements it on each request, so the server must store that balance. A once-off route, where `grants` is 1, creates no session. You therefore need a session store only when you use metered access.

The default driver is `cache`:

```dotenv
MPP_SESSION_DRIVER=cache
```

The cache driver uses the default cache store of your application, until you set `MPP_SESSION_CACHE_STORE`. An application that uses Redis therefore keeps its sessions in Redis automatically.

Point the driver at a shared store that persists. A cache that is local to one server, or that holds data in memory only, can remove a balance early, or hide it from another worker. A buyer would then lose access that the buyer had paid for.

Use the database driver when a balance is to survive a cache eviction and a restart. Use it also to share a balance across application servers without a shared cache:

```dotenv
MPP_SESSION_DRIVER=database
MPP_SESSION_DB_CONNECTION=
```

The migration creates the `mpp_sessions` table, which holds those balances. That is the only purpose of the migration, and you need it only with the database driver:

```bash
php artisan vendor:publish --tag=mpp-migrations
php artisan migrate
```

## Configuration

The main settings are in `config/mpp.php`.

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
| `discovery.enabled` | Serves the OpenAPI document at `GET /openapi.json`. Default: `true`. |
| `discovery.title` / `.version` | The document's `info.title` (defaults to `app.name`) and `info.version`. |
| `discovery.summary` / `.description` / `.terms_of_service` / `.contact` / `.license` | The rest of the OpenAPI `info` object. Empty values are omitted. |
| `discovery.servers` | OpenAPI `servers`. Null follows `app.url`. Takes plain URLs or the full OpenAPI form. |
| `discovery.categories` / `discovery.docs.*` | The draft's `x-service-info`: what the service does, and where to read more. |
| `discovery.form_requests` | Derives an operation's input schema from the `FormRequest` its action type-hints. Default: `true`. |
| `discovery.docblocks` | Publishes the action's docblock as the operation's summary and description. Default: `false`. |
| `discovery.cache_control` / `.allow_origin` | The response headers the draft recommends. Null sends neither. |
| `discovery.operations` | Per-operation documentation for routes you cannot annotate, keyed by route name or `"GET /uri"`. |
| `discovery.include` | Free (non-gated) routes to list in the document, matched by route name, `"GET /uri"` or `"/uri"` with `*` wildcards. Empty by default. |
| `discovery.pipeline` | `[Class::class, 'method']` stages that post-process the finished document. |

### Price Book

A price book entry gives a name to a price that you use often:

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

An entry can also carry its own `preconditions` and `pricing` lists. Every route that uses the entry then inherits both lists:

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

You can write either list as an array, or as a pipe-separated string such as `'tiered|regional'`. A route that names its own `pricing=` or `preconditions=` replaces the list of the entry, and does not add to it.

### Configuration Validation

The gate checks the configuration of a rail that the package ships, before it mints a challenge.

| Rail | Missing config | Result |
| --- | --- | --- |
| Stripe `secret_key` | Settlement cannot run. | Logs once, still emits `402`. |
| Stripe `network_id` / `payment_method_types` | The challenge would be non-conformant (a wallet cannot scope an SPT to you). | Throws `InvalidConfigurationException`. |
| Tempo `recipient`, `token`, or `chain_id` | The challenge would be unpayable or unsafe. | Throws `InvalidConfigurationException`. |
| Tempo `rpc_url` | Settlement cannot broadcast the transaction. | Logs once, still emits `402`. |

A custom verifier validates its own configuration.

### Production

Serve every payment-gated route and the discovery endpoint over HTTPS. The MPP core draft and the discovery draft both require TLS, because a `402`, its credential and the settlement proofs carry material that is sensitive to payment.

This package fails closed. Over plain HTTP, it refuses to issue a challenge, refuses to serve discovery, and raises `InvalidConfigurationException`.

When a load balancer or a proxy terminates TLS, configure the trusted proxies of Laravel, so that the application reads the real scheme. Set `MPP_ALLOW_INSECURE=true` for local development and for testing over plain HTTP only. Keep it off in production.

On a deployment with more than one node, set `MPP_CACHE_STORE` to a shared atomic backend, such as Redis, Memcached or the database. The challenges, the settlement replay ledger and the settlement lock are all in this store. The single-use guarantee and the lock both need storage that every node can read.

The `array` driver holds data in one process, and the `file` driver cannot lock across nodes. With either driver, two nodes can settle the same challenge twice. Null follows the default of the application, which is correct for one node and for local development.

## Testing

The test suite uses Pest:

```bash
composer test
composer lint
```

The live Stripe tests skip themselves until you supply a test key:

```bash
STRIPE_SECRET_KEY=sk_test_... vendor/bin/pest --group=stripe
```

The cross-account Stripe tests need two different test accounts:

```bash
STRIPE_BUYER_SECRET_KEY=sk_test_... STRIPE_SECRET_KEY=sk_test_... vendor/bin/pest --group=stripe-cross
```

## Advanced Usage

### Custom Native Verifiers

A native rail implements `Square1\Mpp\Settlement\Verifier`.

The paid retry presents a `proof` value. Your verifier checks that proof against the record that the rail itself holds. It returns success only when the settled amount and the settled currency match the signed challenge.

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

Then use the rail on a route with `method=acme`, or make it your house rail with `MPP_DEFAULT_METHOD=acme`.

The default fiat shape of a 402 `request` payload is an amount in minor units, a currency and `methodDetails`. When the payload of your rail has another shape, also implement a `Square1\Mpp\Protocol\Requests\RailRequestBuilder`, and name it as `request_builder` in the method block. Without that builder, the default fiat builder mints a request in the shape of Stripe for your rail, and your verifier expects another shape. A fiat rail that has the shape of Stripe needs no builder.

The gate already checks five things: that the challenge exists, that it has not expired, that the route offered it for this method, that its scope binds it to the route, and that its signature is valid. The gate also burns a challenge after a successful settlement, and serialises the concurrent settlement attempts. When your rail supports an idempotency key, use the challenge id as that key.

### Wire Format

Most implementors do not build these headers by hand, because `npx mppx` and `mppx validate` run the loop. The headers are still useful when you debug. The format is the format of the MPP core spec, [draft-httpauth-payment-00](https://paymentauth.org/draft-httpauth-payment-00), and it is the same for every rail.

An unpaid response, with one `Payment` challenge per offered rail, joined by commas:

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

The `id` is an HMAC-SHA256 over the seven binding slots, which are `realm|method|intent|request|expires|digest|opaque`. The challenge therefore authenticates itself. Change any term, and the id no longer verifies.

`request` and `opaque` are canonical JSON, encoded with base64url. The canonical form is JCS, the JSON Canonicalization Scheme of RFC 8785. `request` carries the economic terms of the rail. `opaque` carries a random `nonce` for that mint, the `scope` of this package, the bound request `resource`, and, on a metered route, `grants`. No one can change either value, for the same reason.

A paid retry, with one base64url credential. It echoes the challenge, and carries the proof of the rail in `payload`:

```http
Authorization: Payment <base64url of {"challenge": {...echoed params...}, "payload": {"spt": "spt_..."}}>
```

Each rail has its own payload. Stripe presents `{"spt": "..."}`. Tempo presents `{"type": "transaction", "signature": "0x..."}`, and a `source` DID at the top level. A DID is a Decentralized Identifier. A custom rail defines its own payload. The general `proof()` accessor of the package reads `proof`, `spt` or `hash`.

Successful response:

```http
Payment-Receipt: <base64url of {"status": "success", "method": "...", "reference": "...", "timestamp": "..."}>
```

A metered follow-up request. This is an extension of the package. It spends a prepaid session, and it is not a payment:

```http
Authorization: Payment session="sess_..."
```

### Security Notes

- The package signs each challenge with an HMAC, over the payment terms and the expiry.
- A challenge is bound to its concrete request target, which is the HTTP method, the path and the query. For a request with a body, it is also bound to the digest of that body. Settlement enforces both bindings, so a challenge for one target or body cannot settle another. A replay needs a fingerprint that no one can reverse, over the credential, the body and the target. The challenge id alone therefore cannot retrieve a paid response.
- A paid retry must echo the complete selected challenge without a change, with the proof of the rail.
- A price that a resolver sets binds at mint time. Settlement verifies against the stored challenge, so a second resolution cannot change the price that you quoted to a buyer.
- A resolver must waive a charge explicitly, with `free => true`. A resolved amount that is zero, or that the package cannot read, throws instead of serving the route free.
- The package burns a challenge after a successful settlement.
- The package trusts a Stripe settlement only after a PaymentIntent succeeds, and matches the amount and the currency of the challenge.
- The package trusts a Tempo settlement only after the signed transfer pays the challenged token, amount and recipient, and after the network confirms the transaction.
- The package checks the scope of a metered session, and decrements it atomically.
- The challenge signing key and the Stripe secret key stay on the server.

### Octane and FrankenPHP

The package is safe under a long-lived worker. It passes the state of a request through each call, and does not store that state on a singleton.

Reload your workers after you change `MPP_CHALLENGE_SECRET`, a TTL, a Stripe key or the Tempo config. Tempo settlement blocks while it polls for a receipt, for up to `poll_attempts * poll_delay_ms`.

## License

This package is released under the MIT License. See [LICENSE.md](LICENSE.md).

The MPP APIs and the Stripe SPT APIs can change while either one uses a preview API. Pin the version of the package, and read the changelog before you upgrade.
