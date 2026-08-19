# Changelog

All notable changes to `laravel-mpp` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). The public API is the config file, the middleware argument syntax, the `#[RequiresPayment]` attribute, the `PaymentSpec` your checks and resolvers receive, the `Verifier` / `SettlementChecker` interfaces, and `RequirePayment::handle()`. Service constructors resolved from the container are internal and may change in a minor release.

## [2.0.0] - unreleased

**Breaking.** The wire format is now the MPP core spec ([draft-httpauth-payment-00](https://paymentauth.org/draft-httpauth-payment-00), with `tempoxyz/mpp-specs` main as the source of truth). Every 402, credential, and receipt this package emits or accepts changed shape. The change aligns the package with the published MPP spec, so it interoperates with any standard MPP agent. There is no wire back-compat with 1.x. Server-side integration code (routes, config, resolvers, preconditions, custom `Verifier`s) is largely unaffected. Any client written against the 1.x wire format must be migrated. Conformance is machine-checkable against your own app with `npx mppx@latest validate <url> --endpoint GET:/<route> --yes`.

### Changed

- **Challenges.** A 402 now carries one spec-format `Payment` challenge per offered rail in `WWW-Authenticate`: `id`, `realm`, `method`, `intent`, `request` (base64url-encoded RFC 8785 JCS JSON), `expires`, and `opaque`. The challenge `id` is the binding signature. It is an HMAC-SHA256 over seven fixed slots (`realm|method|intent|request|expires|digest|opaque`), verified on the paid retry by recompute-and-compare. There is no separate `sig=` parameter.
- **Multi-rail is now one wire format.** Stripe and Tempo challenges share the spec shape, so `methods=stripe|tempo` (or `MPP_ACCEPT=stripe|tempo`) offers both from a single 402, and the client answers one. The separate Tempo fork (`TempoGate`, `MppxCodec`, `TempoChallengeFactory`/`Store`, `ChallengeOffer`) is deleted. One `PaymentGate` settles every rail, dispatching by the stored challenge's method, never the credential's claim.
- **Amounts.** They cross the wire as minor-unit integer strings (`"50"` cents, `"500000"` pathUSD at 6 decimals), converted in the per-rail request builders. 1.x sent decimal strings (`"0.50"`).
- **Credentials and receipts.** `Authorization: Payment <base64url {challenge, payload, source}>`. The `session="..."` auth-param form is retained for the metered-session extension. `Payment-Receipt` is base64url JCS `{status, method, timestamp, reference, ...}`.
- Client rail choice is negotiated via the spec's `Accept-Payment` request header (q-values, wildcards, most-specific wins, `q=0` excludes, a header matching nothing is ignored per spec).
- The binding is now recomputed before the challenge is burned, so a tampered retry no longer consumes the challenge (1.x burned first).
- Tempo network defaults target the Moderato testnet (chain id 42431, token `0x20c0000000000000000000000000000000000000`), so the rail is payable out of the box with only a recipient set. For mainnet, set `TEMPO_CHAIN_ID` (4217), `TEMPO_TOKEN` (`0x20C000000000000000000000b9537d11c60E8b50`), and a mainnet `TEMPO_RPC_URL` together. Never mix networks, or transfers revert with `TIP20: Uninitialized`.
- Package extensions (`scope`, `grants`) ride in the spec's `opaque` parameter. They are JCS-encoded, bound in slot 7, and echoed unchanged by conformant clients.
- **Stripe `network_id` is now required to offer the rail.** A stripe challenge must advertise `networkId` and `paymentMethodTypes` to be conformant, so minting a stripe `402` without `STRIPE_NETWORK_ID` now throws `InvalidConfigurationException` instead of emitting a non-conformant challenge. `secret_key` stays a settle-time secret (warn-only).
- **Tempo challenges advertise `supportedModes: ["pull"]`.** The verifier only accepts a client-signed `type="transaction"` credential for the server to broadcast. Omitting `supportedModes` let a conformant client assume both pull and push were supported. The challenge now states the one mode it accepts.
- Stripe settlement failures no longer leak the raw gateway exception to the caller. The full exception is logged, and the response carries a stable, generic message.
- **Metered sessions are now experimental.** The prepaid-session extension (the `session="…"` credential form and `Payment-Session` header) is a package invention, not spec. The MPP spec's generic `session` intent ([mpp-specs #280](https://github.com/tempoxyz/mpp-specs/pull/280)) may supersede it with a change that is not backward compatible. The feature is unchanged and fully supported. Pin the version, and expect a migration note before you adopt a post-#280 release. Once-off charges (`grants` = 1) are pure spec and unaffected.

### Security

- **Challenges are now bound to the concrete request target and body (cross-route authorization fix).** Settlement requires the challenge's target identity (HTTP method, path, and normalized query, bound into `opaque` at mint) to match the request, and the route to still offer the challenge's method. This binds parameterized paths and price/selection query params, not just the route pattern, and does not rely on scope as identity (an app can share one scope across differently-priced routes). The request body is bound via its digest (see below). Before this, a challenge for a cheap target could buy a more expensive one at the same route pattern or scope. Pinned by `AuthorizationBindingTest` and `ConformanceHardeningTest`.
- **Replay is authorized by a credential fingerprint, not the challenge id.** The challenge id travels in the `402` and the credential, so it is not a secret. Replay now requires a non-reversible fingerprint of the successful credential proof, the request-body digest, and the concrete target to match. A different credential, a changed body, or a different target cannot retrieve the paid response.
- **Request bodies are bound into the challenge (RFC 9530).** A body-carrying request's `Content-Digest` is computed at mint, advertised as the challenge `digest` parameter, and bound into the seven-slot HMAC. Settlement recomputes the retry's body digest and rejects a mismatch, so a client cannot obtain a quote for one body and pay with another (which mattered most under dynamic pricing). Body-less requests are unaffected. New `Square1\Mpp\Support\Digest`.
- **Replay no longer re-runs the protected action, and restores the full response.** A retry of a settled challenge returns the recorded response verbatim (status, body, every header, and cookies), so a matched retry does not re-run the action or repeat its side effects. This holds once the response is recorded: if the process crashes after the charge but before the record, a retry takes a fresh `402`, so side effects that must survive that window need application-level idempotency. Only a buffered response within `mpp.replay_max_bytes` (default 256 KB) is snapshotted. A streamed, binary, or over-limit response is not stored, and its lost-response retry takes a fresh `402` rather than an empty body, so such endpoints are not lost-response-idempotent. Pinned by a side-effect-counter test and header/streamed replay tests.
- **Metered issuance now fails closed.** The prepaid session is created at `grants - 1` (this request being the first access) instead of created full and then separately consumed. A failed first consume could previously fail open and hand back a full-credit session, granting `grants + 1` accesses.
- **Protocol errors are now typed, and malformed credentials are distinguished from absent ones.** Rejections emit the draft's problem types (`invalid-challenge`, `verification-failed`, `payment-expired`, `malformed-credential`) instead of collapsing to `payment-required`. `CredentialParser` throws `MalformedCredentialException` for a present-but-unparseable `Payment` credential (an absent credential still just asks the price). A settlement credential must also echo the full selected challenge (realm, method, intent, request, expires, and any opaque and digest), each present and matching. A missing or altered field (a bare `{id}`, a swapped method) is rejected as malformed.
- **Retries are now idempotent.** One settlement per challenge, and no double charge. A settled challenge's receipt is kept in a short-lived ledger (`mpp.settlement_replay_ttl` / `MPP_SETTLEMENT_REPLAY_TTL`, default 300s). A retry of an already-settled payment (for example, the `200` was lost in transit) now replays that original receipt with a `200`. For metered routes it replays the same session, with no extra credit spent. It does not issue a fresh `402` the client would pay again. A retry that arrives while the first settlement is still in flight gets a `409` with `Retry-After`, which means wait, not pay again. It is not a fresh challenge. The settlement lock's lifetime is now its own knob (`mpp.settle_lock_ttl`, default 300s), sized to outlive the slowest rail. It was a fixed 10s, shorter than Tempo's on-chain confirm, so it could lapse mid-settlement and let two requests settle one challenge in parallel. New: `Square1\Mpp\Protocol\SettlementLedger`, config keys `mpp.settlement_replay_ttl` and `mpp.settle_lock_ttl`. Pinned by new `SettlementMiddlewareTest` cases (replay, metered single-session replay, 409 on contention).
- **Challenge ids are now unique per mint (replay/resurrection fix).** The id is the seven-slot binding HMAC. Its inputs (realm, method, intent, request, expires to the second, opaque) were all deterministic. So two 402s minted for the same route and price within one wall-clock second produced an identical id. A rejected replay's own re-challenge could then re-mint the burned id and let one payment settle twice (a second metered session, or a free re-serve backed by the rails' own charge-idempotency). A per-mint 128-bit random `nonce` now rides in `opaque` (bound into slot 7, echoed by conformant clients), which makes every id unique, per-payer, and impossible to resurrect. It also makes the Stripe idempotency key (the challenge id) unique per mint. Pinned by `ChallengeFactoryTest` (id uniqueness) and `SettlementMiddlewareTest` (no resurrection through re-challenge).

### Added

- `MPP_ACCEPT` (pipe-separated default offered rails) and `MPP_REALM` (protection space, defaults to the request host).
- `MPP_CACHE_STORE` (`mpp.cache_store`), the cache store for challenges, the settlement replay ledger, and the settlement lock. Null follows the app default. Production multi-node deployments must point it at a shared atomic backend (redis / memcached / database), because `array` and `file` cannot give cross-node single-use or locking.
- `MPP_REPLAY_MAX_BYTES` (`mpp.replay_max_bytes`, default 262144), the largest response body snapshotted for idempotent replay. Streamed, binary, and larger responses are not snapshotted.
- **Discovery document.** `/openapi.json` generated from the live router. It resolves each route's price the way the runtime does (price-book entries, global defaults, per-rail request builders) and emits `amount: null` for per-request-priced routes, so the document matches the live `402`. Config under `mpp.discovery.{enabled,title,version}`. Served at the fixed `GET /openapi.json` the discovery draft requires. Route named `mpp.discovery`.
- `Square1\Mpp\Support\Jcs` (RFC 8785 canonicalization, rejects floats so money can never round silently) and `Support\Base64Url`.
- The testbench workbench now doubles as the in-repo conformance demo: `vendor/bin/testbench serve --port=4242`, then run the `mppx validate` oracle against `GET:/paid`.

### Removed

- `MethodConfigValidator::assertSingleDialect`. It would have thrown in production on any stripe+tempo co-offer (1.x's test fakes masked it), and the dialect split it policed no longer exists.
- Dead config keys `methods.tempo.realm`, `methods.tempo.network_id`, `methods.tempo.payment_method_types`. Each tempo challenge now advertises a random `methodDetails.memo`, and the paid transfer must carry that exact value (the Tempo charge draft's model), so no realm-derived memo is configured. Tempo never advertises fiat method details.

### Upgrading

- Nothing to do for most server-side code. Republish the config (or diff it) to pick up `realm`, `accept`, and `discovery`.
- Rewrite any 1.x client integrations against the spec format, or point them at a stock MPP client (`npx mppx`). Validate with the conformance oracle above.
- If you offer the Stripe rail, set `STRIPE_NETWORK_ID` (a `profile_…` from the Stripe Dashboard). Minting a stripe `402` without it now throws instead of emitting a non-conformant challenge, so a deployment that relied on the old warn-only behavior must add it before upgrading.
- On a multi-node deployment, set `MPP_CACHE_STORE` to a shared atomic backend (redis / memcached / database). The single-use guarantee and the settlement lock need storage every node can see, and `array` and `file` cannot provide it.
- Tempo now defaults to the Moderato testnet. For a mainnet deployment, set `TEMPO_CHAIN_ID=4217`, `TEMPO_TOKEN=0x20C000000000000000000000b9537d11c60E8b50`, and a mainnet `TEMPO_RPC_URL` together.

## [1.2.0] - 2026-08-07

### Added

- **Dynamic pricing.** A route's price can now depend on the request rather than being fixed in the route definition, so one endpoint can charge $2 to one caller and $5 to another. Register a named resolver under `mpp.pricing.resolvers` as a `[Class::class, 'method']` pair. It receives the `Request` and the resolved `PaymentSpec` and returns an array of overrides, or `null` to keep the route's own price.
- Attach resolvers with `pricing=` on the middleware (`mpp:5.00,USD,pricing=tiered`), `pricing:` on the attribute (`#[RequiresPayment(amount: '5.00', pricing: ['tiered'])]`), a `pricing` key on a `price_book` entry, or `mpp.pricing.global` for every guarded route. Globals run first, then the route's own, in order and de-duplicated, each seeing the previous one's result. An unknown name throws.
- Overridable keys: `amount`, `currency`, `grants`, `scope`, and `free`. Anything else throws, including `method` / `methods`. A resolver sets the price, not the payment terms around it.
- **Resolver-owned pricing.** A route may now state no amount at all and leave pricing entirely to its resolvers (`mpp:scope=report,pricing=tiered`), instead of being forced to declare a placeholder that nothing reads. One rule decides it. Something must supply a price before the gate: the route, a global default, or a resolver. If a route states none and every resolver declines, the request raises the new `UnpriceableRequestException`, naming the route and the resolvers that ran, rather than being served or silently priced at zero. Declaring an amount is still the right choice where a list price is real. It is what unrecognised callers pay, and the fallback if a resolver is later disabled.
- `Square1\Mpp\Exceptions\UnpriceableRequestException`, a sibling of `InvalidConfigurationException` under `MppException`. It is kept distinct because a declined price is not a configuration defect. The config is valid, the outcome depends on the request, and it can recur in production long after deploy. Triage should read it as "a resolver returned null for this caller".
- **Free requests.** A resolver returning `['free' => true]` serves the route with no challenge, session, or receipt. It must be said explicitly. A zero, negative, or unparseable `amount` throws instead of quietly giving the resource away, as does `free => true` alongside an `amount`. A waived request still runs its preconditions, and never reaches the payment gate, so it needs no working settlement rail.
- `Money::isValidAmount()`, now the single definition of a well-formed decimal amount for the package.
- `price_book` entries accept their `methods`, `preconditions`, and `pricing` lists either as an array or as a pipe-separated string (`'stripe|tempo'`).

### Fixed

- **Precondition fix.** Preconditions declared on a `#[RequiresPayment]` attribute were silently skipped when the route relied on automatic enforcement (`MPP_ATTRIBUTES_ENABLED=true`) rather than carrying the `mpp` middleware. Such routes reach the payment gate without passing through that middleware, which is where the checks used to run, so they never ran and the request was charged as if they had passed. See Upgrading below. This changes responses.

### Changed

- A price resolver's `amount` is validated by the same format rule that converts it at mint time, so `'1e2'` or `'+2.00'` is rejected where it is set rather than failing later with a vaguer error. Affects only the new pricing feature.
- Docs no longer advertise offering several rails from one `402` (`accept`, `methods=`, `methods:`). The capability is unchanged. With only Stripe and Tempo shipped there is no valid pair, because Tempo speaks a different wire format and cannot share a challenge with a native rail. It is inert until you add a custom native `Verifier`. The `accept` key is gone from the published config. An existing published config that sets it keeps working. A new README section, "Can One Route Offer Both Rails?", explains why a 402 quotes one rail and shows how to negotiate the rail per request.
- Internal: pricing, preconditions, and the free-request bypass now run in a new `PaymentPipeline`, the single path from a guarded route to the payment gate, shared by both middlewares so neither route style can skip a step the other runs. `PaymentGate` is left deciding only how a chargeable request pays.
- Internal: the constructors of `RequirePayment` and `EnforcePaymentAttributes` changed. Both are resolved from the container, so injecting `RequirePayment` and calling `handle($request, $next, ...$args)`, the documented "Stripe and Tempo on One Route" recipe, is unaffected. Only `new RequirePayment(...)` breaks.
- Internal: `PreconditionRunner`, `PriceResolver`, and a shared `ResolvesNamedCallables` trait extracted. `PaymentGate`'s constructor is unchanged from 1.1.0.
- `PaymentSpec::$amount` is now `?string`, null while a route is waiting on its resolvers, with a new `isPriced()` alongside it. A price resolver may therefore be handed a null amount. One that computes a percentage off `$spec->amount` should handle that. Precondition checks are unaffected, because the price assertion runs before them, so a check is always given a real amount.
- The "needs an amount" error moved out of `SpecResolver` and into the pipeline, which is the only place that knows whether resolvers ran and declined. Its two old messages are replaced by one that names the route.

### Upgrading

Nothing to change for the new features. Dynamic pricing is inert until you register a resolver, and every existing route keeps its current price.

The precondition fix, however, can change what a live endpoint returns. You are affected only if both of these are true:

- `mpp.attributes.enabled` is `true` (`MPP_ATTRIBUTES_ENABLED`, default `false`), and
- a controller action carries `#[RequiresPayment(preconditions: [...])]` and is guarded by automatic enforcement rather than by the `mpp` middleware.

For those routes the checks now run as documented. A request that previously got a `402` may now get whatever the check returns, typically `404` or `403`. Before upgrading, find them and confirm each check does what you intended when it was written but never exercised:

```bash
# `preconditions:` is attribute syntax; a config registry entry reads
# `'preconditions' =>`, so this finds the routes and not the definitions.
grep -rn "preconditions:" app/
```

## [1.1.0] - 2026-06-30

### Added

- **Preconditions.** Named checks that run before a `402` is minted or a payment settled, so a request that can never be fulfilled (a missing resource, a blocked user) is rejected without charging. Each is a `[Class::class, 'method']` pair under `mpp.preconditions.checks`, called with the `Request` and the resolved `PaymentSpec`, returning a `Response` to reject or `null` to proceed.
- `mpp.preconditions.global` runs on every guarded route. Routes add their own with `preconditions=` on the middleware or `preconditions:` on the attribute. Globals run first, then the route's own, in order and de-duplicated. The first `Response` wins. An unknown name throws, so a typo fails closed.

## [1.0.0] - 2026-06-30

Initial release: HTTP 402 machine payments for Laravel, over two settlement rails.

### Added

- **Stripe rail.** Shared Payment Tokens settled as PaymentIntents, with an optional per-payer Stripe Customer resolver.
- **Tempo rail.** pathUSD on-chain settlement in the mppx wire dialect, payable by a stock `npx mppx` client. Pure PHP, with no Node sidecar and no server signing key. The client signs the transfer and pays its own gas.
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
