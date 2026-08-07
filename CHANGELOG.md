# Changelog

All notable changes to `laravel-mpp` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). The public API is the config file, the middleware argument syntax, the `#[RequiresPayment]` attribute, the `PaymentSpec` your checks and resolvers receive, the `Verifier` / `SettlementChecker` interfaces, and `RequirePayment::handle()`. Service constructors resolved from the container are internal and may change in a minor release.

## [1.2.0] — unreleased

### Added

- **Dynamic pricing.** A route's price can now depend on the request rather than being fixed in the route definition, so one endpoint can charge $2 to one caller and $5 to another. Register a named resolver under `mpp.pricing.resolvers` as a `[Class::class, 'method']` pair; it receives the `Request` and the resolved `PaymentSpec` and returns an array of overrides or `null` to keep the route's own price.
- Attach resolvers with `pricing=` on the middleware (`mpp:5.00,USD,pricing=tiered`), `pricing:` on the attribute (`#[RequiresPayment(amount: '5.00', pricing: ['tiered'])]`), a `pricing` key on a `price_book` entry, or `mpp.pricing.global` for every guarded route. Globals run first, then the route's own, in order and de-duplicated, each seeing the previous one's result. An unknown name throws.
- Overridable keys: `amount`, `currency`, `grants`, `scope`, and `free`. Anything else throws, including `method` / `methods` — a resolver sets the price, not the payment terms around it.
- **Resolver-owned pricing.** A route may now state no amount at all and leave pricing entirely to its resolvers (`mpp:scope=report,pricing=tiered`), instead of being forced to declare a placeholder that nothing reads. One rule decides it: something must supply a price before the gate — the route, a global default, or a resolver. If a route states none and every resolver declines, the request raises the new `UnpriceableRequestException` naming the route and the resolvers that ran, rather than being served or silently priced at zero. Declaring an amount is still the right choice where a list price is real: it is what unrecognised callers pay, and the fallback if a resolver is later disabled.
- `Square1\Mpp\Exceptions\UnpriceableRequestException`, a sibling of `InvalidConfigurationException` under `MppException`. Kept distinct because a declined price is not a configuration defect: the config is valid, the outcome depends on the request, and it can recur in production long after deploy. Triage should read it as "a resolver returned null for this caller".
- **Free requests.** A resolver returning `['free' => true]` serves the route with no challenge, session, or receipt. It must be said explicitly: a zero, negative, or unparseable `amount` throws instead of quietly giving the resource away, as does `free => true` alongside an `amount`. A waived request still runs its preconditions, and never reaches the payment gate — so it needs no working settlement rail.
- `Money::isValidAmount()`, now the single definition of a well-formed decimal amount for the package.
- `price_book` entries accept their `methods`, `preconditions`, and `pricing` lists either as an array or as a pipe-separated string (`'stripe|tempo'`).

### Fixed

- **Preconditions declared on a `#[RequiresPayment]` attribute were silently skipped** when the route relied on automatic enforcement (`MPP_ATTRIBUTES_ENABLED=true`) rather than carrying the `mpp` middleware. Such routes reach the payment gate without passing through that middleware, which is where the checks used to run, so they never ran and the request was charged as if they had passed. See *Upgrading* below — this changes responses.

### Changed

- A price resolver's `amount` is validated by the same format rule that converts it at mint time, so `'1e2'` or `'+2.00'` is rejected where it is set rather than failing later with a vaguer error. Affects only the new pricing feature.
- Docs no longer advertise offering several rails from one `402` (`accept`, `methods=`, `methods:`). The capability is unchanged and still tested, but with only Stripe and Tempo shipped there is no valid pair — Tempo speaks a different wire format and cannot share a challenge with a native rail — so it is inert until you add a custom native `Verifier`. The `accept` key is gone from the published config; an existing published config that sets it keeps working. A new README section, "Can One Route Offer Both Rails?", explains why a 402 quotes one rail and shows how to negotiate the rail per request.
- Internal: pricing, preconditions, and the free-request bypass now run in a new `PaymentPipeline`, the single path from a guarded route to the payment gate, shared by both middlewares so neither route style can skip a step the other runs. `PaymentGate` is left deciding only how a chargeable request pays.
- Internal: the constructors of `RequirePayment` and `EnforcePaymentAttributes` changed. Both are resolved from the container, so injecting `RequirePayment` and calling `handle($request, $next, ...$args)` — the documented "Stripe and Tempo on One Route" recipe — is unaffected. Only `new RequirePayment(...)` breaks.
- Internal: `PreconditionRunner`, `PriceResolver`, and a shared `ResolvesNamedCallables` trait extracted; `PaymentGate`'s constructor is unchanged from 1.1.0.
- `PaymentSpec::$amount` is now `?string`, null while a route is waiting on its resolvers, with a new `isPriced()` alongside it. **Price resolvers** may therefore be handed a null amount — one that computes a percentage off `$spec->amount` should handle it. **Precondition checks are unaffected**: the price assertion runs before them, so a check is always given a real amount.
- The "needs an amount" error moved out of `SpecResolver` and into the pipeline, which is the only place that knows whether resolvers ran and declined. Its two old messages are replaced by one that names the route.

### Upgrading

Nothing to change for the new features — dynamic pricing is inert until you register a resolver, and every existing route keeps its current price.

The precondition fix, however, can change what a live endpoint returns. You are affected only if **both** are true:

- `mpp.attributes.enabled` is `true` (`MPP_ATTRIBUTES_ENABLED`; the default is `false`), and
- a controller action carries `#[RequiresPayment(preconditions: [...])]` and is guarded by automatic enforcement rather than by the `mpp` middleware.

For those routes the checks now run as documented. A request that previously got a `402` may now get whatever the check returns — typically `404` or `403`. Before upgrading, find them and confirm each check does what you intended when it was written but never exercised:

```bash
# `preconditions:` is attribute syntax; a config registry entry reads
# `'preconditions' =>`, so this finds the routes and not the definitions.
grep -rn "preconditions:" app/
```

## [1.1.0] — 2026-06-30

### Added

- **Preconditions.** Named checks that run before a `402` is minted or a payment settled, so a request that can never be fulfilled — a missing resource, a blocked user — is rejected without charging. Each is a `[Class::class, 'method']` pair under `mpp.preconditions.checks`, called with the `Request` and the resolved `PaymentSpec`, returning a `Response` to reject or `null` to proceed.
- `mpp.preconditions.global` runs on every guarded route; routes add their own with `preconditions=` on the middleware or `preconditions:` on the attribute. Globals run first, then the route's own, in order and de-duplicated; the first `Response` wins. An unknown name throws, so a typo fails closed.

## [1.0.0] — 2026-06-30

Initial release: HTTP 402 machine payments for Laravel, over two settlement rails.

### Added

- **Stripe rail.** Shared Payment Tokens settled as PaymentIntents, with an optional per-payer Stripe Customer resolver.
- **Tempo rail.** pathUSD on-chain settlement in the mppx wire dialect, payable by a stock `npx mppx` client. Pure PHP — no Node sidecar and no server signing key; the client signs the transfer and pays its own gas.
- HMAC-signed challenges over the payment terms and expiry, burned after settlement so a payment cannot be replayed. Signing key derives from `APP_KEY` unless `MPP_CHALLENGE_SECRET` is set.
- Receipts on the paid response (`Payment-Receipt`).
- **Metered access.** One payment grants N accesses via a prepaid session (`Payment-Session`), scope-checked and decremented atomically, oversell-proof under concurrency. Cache and database session stores, both inheriting the application's own defaults.
- Route protection three ways: middleware arguments, `mpp` plus a `#[RequiresPayment]` attribute, or automatic attribute enforcement on chosen route groups.
- Multi-rail native challenges: one signed `accepts[]` entry per offered method, each signature valid only for its own method.
- Price book presets, global price defaults, and rail configuration validation that throws on config a `402` cannot be minted without and warns once on config that only impairs settlement.
- Extension points: the `Verifier` interface for a new rail, and `SettlementChecker` for rails whose settlement is a pre-existing external transaction.

[1.2.0]: https://github.com/square1-io/laravel-mpp/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/square1-io/laravel-mpp/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/square1-io/laravel-mpp/releases/tag/1.0.0
