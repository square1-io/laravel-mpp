# Changelog

This file records every notable change to `laravel-mpp`.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public API is the config file, the middleware argument syntax, the `#[RequiresPayment]` and `#[DiscoveryInfo]` attributes, the `->discovery()` route macro, the `PaymentSpec` that your checks and resolvers receive, the `Verifier` and `SettlementChecker` interfaces, and `RequirePayment::handle()`. A service constructor that the container resolves is internal, and it can change in a minor release.

## [2.3.0]

### Added

- **The discovery document can now carry everything that `draft-payment-discovery-01` asks for.** The generated `/openapi.json` was conformant, but it stated only prices. It can now also state what an operation does, what to send to it, what it returns, and who operates the service. Those are the parts of the draft that a price cannot supply. Nothing about `x-payment-info` changes. The package still derives it from the live router, and from the request builders that mint the 402. The document therefore still cannot advertise a price that the gate does not charge.
- Three groups of fields are now configured under `mpp.discovery`: the `x-service-info` extension of the draft, which is `categories`, `docs.homepage`, `docs.apiReference` and `docs.llms`; the rest of the OpenAPI `info` object, which is `summary`, `description`, `termsOfService`, `contact` and `license`; and `servers`. `title` still follows `app.name`, and `servers` now follows `app.url`.
- Documentation for one operation now has eleven fields: `summary`, `description`, `priceNote`, `tags`, `operationId`, `request`, `response`, `parameters`, `query`, `deprecated` and `hidden`. You can write them in three places, and the package merges them field by field. The place nearest to the route wins. The three places are a `->discovery(…)` route macro, which is also available as `->mppDiscovery(…)`; a new `#[DiscoveryInfo]` attribute on the action; and `mpp.discovery.operations`, keyed by route name or by `"GET /uri"`, for a route that you did not define. `priceNote` becomes the `description` of each offer. Pass one note for every rail, or a map keyed by method name.
- `hidden: true` keeps a payable route out of the document without making it free. Like every other field, `hidden` and `deprecated` are unset until stated, so `hidden: false` on the route overrides a `hidden: true` in config.
- Input schemas are derived from the `FormRequest` an action type-hints, translating types, formats, bounds, enumerations, nesting and requiredness into JSON Schema (`mpp.discovery.form_requests`, on by default). Rules that describe a database fact rather than a shape contribute nothing, and an unrecognised rule is ignored rather than guessed at. `request` and `response` also take a JSON Schema array, a full OpenAPI object, or the name of a class; `response` takes a bare schema for the 200 or a map keyed by status code.
- The name of a route becomes its `operationId`. The docblock of the action can become the `summary` and the `description` of the operation, through `mpp.discovery.docblocks`. That setting is **off** by default. An author writes a docblock for colleagues, so to publish it on an unauthenticated endpoint that registries crawl must be a decision, and not a result of an upgrade.
- `mpp.discovery.pipeline` takes `[Class::class, 'method']` stages. Each stage receives the finished document and returns it. Use a stage for any part of OpenAPI that the package does not model. The package logs a stage that throws, or that returns a value other than an array, and then skips it.
- `mpp.discovery.include` lists free routes beside the paid ones. A free route is a route that the package does not gate, such as a redirect, a status endpoint, or the free tier of a paid endpoint. The package matches each pattern by route name, by `"GET /uri"` or by `"/uri"`, and the patterns accept `*` wildcards. The package documents such a route in the same way as any other route, and the route carries no `x-payment-info` and no `402`. The list is empty by default, because a broad pattern publishes your route table on an unauthenticated endpoint that registries crawl.
- The test suite now validates the generated document against the JSON Schemas of the draft, for `x-payment-info` and for `x-service-info`. The tests hold a copy of each schema, taken from the draft without a change, in `tests/Fixtures`. Both schemas set `additionalProperties: false`, so the tests catch an extra key that a strict registry rejects. That includes a key that a pipeline stage adds, and the pipeline is the one place where an application can make the document non-conformant. This adds `opis/json-schema` as a dev dependency.
- The package serves the document with the two response headers that the draft recommends. They are `Cache-Control: public, max-age=300` and `Access-Control-Allow-Origin: *`. Set `mpp.discovery.cache_control` or `mpp.discovery.allow_origin` to null to omit either one.

- `Square1\Mpp\Discovery\ProvidesSchema` is the contract for a class that states a request or response shape. Name the class in `request` or `response`, including inside a status map, as in `['200' => ScoreSchema::class]`. The method is static, so the package reads the schema without building the class. A `FormRequest` cannot implement the interface, because it belongs to Laravel, so the package reads its `rules()` instead.
- On a verb that carries no body, the rules of a `FormRequest` become `in: query` parameters rather than a request body. A `GET` action that type-hints one therefore documents its query string with no further work. A nested rule needs an OpenAPI serialization style that the rules do not state, so the package leaves those out.
- A path parameter now states a type when the action type-hints the argument, as in `show(int $id)`, or when a `where()` constraint matches digits and nothing else, which is what `whereNumber()` assigns. A bounded constraint such as `[0-9]{4}` stays a string with a `pattern`, which keeps the length as well as the type.
- The package now derives a schema from the types of a data object. Name the class in `request` or `response`, and the package reads its public typed properties, promoted constructor properties included. A backed enum becomes an `enum` of its case values, a nested object becomes a nested schema, `?int` becomes `["integer", "null"]`, and a `DateTimeInterface` becomes a `date-time` string. A property is required when its type forbids null and the class gives it no default. An untyped property, a union type and `mixed` carry no shape, so the schema leaves them out, and the schema never sets `additionalProperties: false`. This is the output counterpart of the rules that the package already reads for the input, and it removes the schema class for an application that returns data objects.
- The package refuses to reflect a class that implements `JsonSerializable`. Such a class chooses its own JSON, so its properties describe a different object from the one it publishes. The package logs the reason and asks for `ProvidesSchema`.
- **The document now declares the four response headers that the gate sets.** They are `Payment-Receipt` and `Payment-Session` on a 2xx, `WWW-Authenticate` on the 402, and `Retry-After` on the 409. A site owner does not choose these headers, cannot change them and cannot remove them, so the package no longer asks for them. `Payment-Session` appears only for a metered route, because the gate issues a session only above one grant. A header that you describe yourself wins, and the package fills only what you left out.
- The document now declares the `409` response that the gate returns while a settlement is in progress. It previously stated only the `200` and the `402`.
- A response object now takes `schema` in place of `content`, as in `'200' => ['schema' => ClipResult::class, 'headers' => [...]]`. `schema` takes everything that `response` itself takes, a class name included. Without it, a response that states one header had to state a media type as well, which lost the short form for the schema.

### Changed

- The exception messages, the log messages and the RFC 9457 `detail` text now follow the same plain-English standard as the rest of the documentation. Each message states one idea per sentence, in the active voice. No message carries a different meaning.
- One of those messages is visible to a client. The `detail` of the 409 response that the gate returns while a settlement is in progress now reads "A payment for this challenge is already settling. Retry the same request shortly. Do not send a new payment." RFC 9457 defines `detail` as text for a person to read, and the `type` URI stays the field that a client matches on. Every `detail` on a 402 response is unchanged.

### Fixed

- The package now resolves a relative documentation link in `x-service-info.docs` as RFC 3986 §5.3 defines, and publishes the absolute result. It previously appended every form to the service URL. A link such as `/llms.txt` therefore reached `https://example.com/api/llms.txt` when the `servers` URL carried the path `/api`, and the file is at the root. A protocol-relative link now takes the scheme of the base, so it carries a scheme and passes the `format: uri` of the draft. The draft types those links `format: uri`, and requires conformance with RFC 3986. A strict validator therefore rejects `/llms.txt`, and a registry that stored `"/"` has nothing to follow. You can still write the link as a relative link in the config.
- A response that the package cannot resolve no longer publishes an empty `schema: {}`. A class name inside a status map reached the response builder as a plain string, and the operation published an empty schema as though it were a real one. The response now carries its description and no content, which states that the operation returns it and that nobody described its shape.
- An operation that declared its own responses no longer also receives a `200` that it never returns. The package published a route documented as a `307` redirect and a `404` with an incorrect `200` beside them. The package still adds the `402` to every payable operation, whatever else that operation declared.
- A route with an optional parameter published an invalid path template. The package emitted `/report/{year}/{month?}` without a change. That path names a parameter `month?`, and declares it nowhere. The package now declares the path parameters, and carries a `where()` constraint across as an anchored `pattern`. An optional Laravel parameter now becomes two OpenAPI paths, because an OpenAPI path parameter is always required.

## [2.2.0]

### Changed

- **`Payment-Receipt` is now exactly the receipt of the spec.** The header carries four fields, `{status, method, timestamp, reference}`, as `draft-httpauth-payment-00` §5.3 and the stripe and tempo charge drafts define it. It carries nothing else. The package removed its own extra fields, which were `amount`, `currency` and `challengeId`. A client that needs the settled amount, or the challenge that it paid, already holds both, in the challenge that it echoed to pay. One lookup of the `reference` returns the record of the rail. This is a conformance change, and not a defect fix. Read `amount`, `currency` and `challengeId` from the echoed challenge instead of from the receipt.
- `Protocol\Receipt` no longer has its `amount` and `currency` properties. It keeps `challengeId` on the object, for correlation on the server, and no longer emits it. This class is internal, and is not part of the documented public API.

## [2.1.0]

### Changed

- A 402 that offers several rails now puts each `Payment` challenge on its own `WWW-Authenticate` field line. It no longer joins them into one line with commas. Several fields in one header is legal HTTP, but it requires a parser to infer where each challenge ends. Separate field lines match the spec more closely (`draft-httpauth-payment-00`, B.2). This is a conformance change, and not a defect fix. A 402 with one rail does not change.
- If you read the header yourself, note that a plain `get()` returns only the first value of a repeated field. Read every value with `$response->headers->all('WWW-Authenticate')`. Otherwise a response with several rails looks like a response with one rail.
- `ChallengeFactory::wwwAuthenticate()` is renamed to `wwwAuthenticateLines()`, and it returns `list<string>` instead of a joined string. The class is internal, and is not part of the documented public API. The method was renamed, and not only re-typed, so that an external caller fails with a clear error.

- `mpp.accept`, which is `MPP_ACCEPT`, now keeps the order that you wrote. Before this release, the package moved `default_method` to the front of the list. `MPP_ACCEPT="tempo|stripe"` therefore still led with Stripe, until you also set `MPP_DEFAULT_METHOD=tempo`. The rail that leads decides what a client answers when that client does not negotiate. The `methods=` option of a route already kept its order, and does not change. `default_method` now chooses the rail only for a route that names none.
- One class now holds the rule for the offered set, `Payment\OfferedMethods`. The gate held one implementation, through `SpecResolver`, and the discovery document held another. The two differed as soon as anyone changed one of them, and the discovery document advertised a rail order that the 402 no longer minted. A test now asserts that the two agree.

### Documentation

- The section on several rails now warns that the rail order matters for a client that does not send `Accept-Payment`. The spec asks a client to select a challenge by capability (B.4, SHOULD). `npx mppx` 0.9.2 takes the first challenge in every case, and returns an error when it cannot pay that challenge. On `methods=stripe|tempo`, an agent that can pay only with crypto therefore fails. On `methods=tempo|stripe`, the same agent pays. List the rail that your usual caller can pay first.

## [2.0.0]

**Breaking.** The wire format is now the format of the MPP core spec, [draft-httpauth-payment-00](https://paymentauth.org/draft-httpauth-payment-00). The main branch of `tempoxyz/mpp-specs` is the source of truth.

Every 402, credential and receipt that this package emits or accepts has a new shape. The change aligns the package with the published MPP spec, so the package works with any standard MPP agent. The wire format is not compatible with 1.x.

Most integration code on the server does not change, and that includes the routes, the config, the resolvers, the preconditions and a custom `Verifier`. You must migrate any client that you wrote against the 1.x wire format. Check conformance against your own application with `npx mppx@latest validate <url> --endpoint GET:/<route> --yes`.

### Changed

- **Challenges.** A 402 now carries one `Payment` challenge per offered rail, in `WWW-Authenticate`, in the format of the spec. Each challenge has seven parameters: `id`, `realm`, `method`, `intent`, `request`, `expires` and `opaque`. The `request` parameter is RFC 8785 JCS JSON, encoded with base64url. The `id` of the challenge is the binding signature. It is an HMAC-SHA256 over seven fixed slots, `realm|method|intent|request|expires|digest|opaque`. The package verifies it on the paid retry: it recomputes the id and compares it. There is no separate `sig=` parameter.
- **Every rail now uses one wire format.** A Stripe challenge and a Tempo challenge have the same shape, which is the shape of the spec. `methods=stripe|tempo`, or `MPP_ACCEPT=stripe|tempo`, therefore offers both rails from one 402, and the client answers one of them. The separate Tempo classes are deleted: `TempoGate`, `MppxCodec`, `TempoChallengeFactory`, `TempoChallengeStore` and `ChallengeOffer`. One `PaymentGate` now settles every rail. It dispatches by the method of the stored challenge, and never by the claim in the credential.
- **Amounts.** An amount now crosses the wire as an integer string in minor units, for example `"50"` cents, or `"500000"` pathUSD at 6 decimals. The request builder of each rail makes the conversion. Version 1.x sent a decimal string, such as `"0.50"`.
- **Credentials and receipts.** A credential is now `Authorization: Payment <base64url {challenge, payload, source}>`. The package keeps the `session="..."` auth-param form, for the metered-session extension. `Payment-Receipt` is now base64url JCS of `{status, method, timestamp, reference, ...}`.
- A client now chooses its rail through the `Accept-Payment` request header of the spec. The header supports q values and wildcards. The most specific range wins, and `q=0` excludes a range. The spec states that a server ignores a header that matches nothing.
- The package now recomputes the binding before it burns the challenge. An altered retry therefore no longer consumes the challenge. Version 1.x burned the challenge first.
- The Tempo network defaults now target the Moderato testnet, with chain id 42431 and token `0x20c0000000000000000000000000000000000000`. A client can therefore pay the rail as soon as you set a recipient. For mainnet, set `TEMPO_CHAIN_ID` to 4217, `TEMPO_TOKEN` to `0x20C000000000000000000000b9537d11c60E8b50`, and `TEMPO_RPC_URL` to a mainnet endpoint, all together. Never mix the networks, or a transfer reverts with `TIP20: Uninitialized`.
- The extensions of the package, `scope` and `grants`, now travel in the `opaque` parameter of the spec. The package encodes them with JCS, and binds them in slot 7. A conformant client echoes them without a change.
- **The Stripe rail now requires `network_id`.** A conformant stripe challenge must advertise `networkId` and `paymentMethodTypes`. To mint a stripe `402` without `STRIPE_NETWORK_ID` therefore now throws `InvalidConfigurationException`, and does not emit a challenge that is not conformant. `secret_key` stays a secret for settlement only, and a missing key produces a warning.
- **A Tempo challenge now advertises `supportedModes: ["pull"]`.** The verifier accepts only a `type="transaction"` credential that the client signed, for the server to broadcast. Without `supportedModes`, a conformant client could assume that the server supported both pull and push. The challenge now states the one mode that it accepts.
- A Stripe settlement failure no longer returns the raw gateway exception to the caller. The package logs the full exception, and the response carries one stable general message.
- **A metered session is now an experimental feature.** The prepaid-session extension is a design of this package, and not part of the spec. That extension is the `session="…"` credential form and the `Payment-Session` header. The general `session` intent of the MPP spec ([mpp-specs #280](https://github.com/tempoxyz/mpp-specs/pull/280)) can replace it, with a change that is not backward compatible. The feature does not change in this release, and the package fully supports it. Pin the version, and expect a migration note before you adopt a release that follows #280. A once-off charge, where `grants` is 1, follows the spec exactly, and does not change.

### Security

- **The package now binds a challenge to the concrete request target and to the body.** This fixes an authorization defect across routes. Settlement now requires two things: that the target identity of the challenge matches the request, and that the route still offers the method of the challenge. The target identity is the HTTP method, the path and the normalized query, which the package binds into `opaque` at mint. This binds a parameterized path, and a query parameter that selects a price or an item. It binds more than the route pattern. It also does not use the scope as an identity, because an application can share one scope across routes at different prices. The package binds the request body through its digest, which the next entries describe. Before this release, a challenge for a cheap target could buy a more expensive one at the same route pattern or scope. `AuthorizationBindingTest` and `ConformanceHardeningTest` cover this.
- **A credential fingerprint now authorizes a replay, and the challenge id does not.** The challenge id travels in the `402` and in the credential, so it is not a secret. A replay now requires a match on a fingerprint that no one can reverse, over the proof of the successful credential, the digest of the request body, and the concrete target. A different credential, a changed body, or a different target cannot retrieve the paid response.
- **The package now binds the request body into the challenge, as RFC 9530 defines.** For a request with a body, the package computes the `Content-Digest` at mint, advertises it as the `digest` parameter of the challenge, and binds it into the seven-slot HMAC. Settlement recomputes the body digest of the retry, and rejects a value that does not match. A client therefore cannot take a quote for one body and pay with another body. That mattered most under dynamic pricing. A request with no body does not change. This adds `Square1\Mpp\Support\Digest`.
- **A replay no longer runs the protected action again, and it restores the full response.** A retry of a settled challenge returns the recorded response without a change, which is the status, the body, every header and the cookies. A retry that matches therefore does not run the action again, and does not repeat its side effects.

  This holds after the package records the response. When the process stops after the charge and before the record, a retry takes a fresh `402`. A side effect that must survive that window needs idempotency in your application.

  The package snapshots a response only when the response is buffered and within `mpp.replay_max_bytes`, which defaults to 256 KB. It does not store a streamed response, a binary response, or a response over the limit. A retry after a lost response then takes a fresh `402`, and not an empty body. Such an endpoint is therefore not idempotent after a lost response. A side-effect counter test, and tests for header and streamed replay, cover this.
- **Metered issuance now fails closed.** The package creates the prepaid session at `grants - 1`, because the current request is the first access. It no longer creates the session at its full balance and then consumes one credit separately. Before this release, a failed first consume could allow the request and return a session at its full balance, which granted `grants + 1` accesses.
- **Protocol errors now have types, and a malformed credential differs from an absent one.** A rejection now emits a problem type of the draft: `invalid-challenge`, `verification-failed`, `payment-expired` or `malformed-credential`. Every rejection previously used `payment-required`.

  `CredentialParser` now throws `MalformedCredentialException` for a `Payment` credential that is present and that the parser cannot read. A request with no credential still asks for the price.

  A settlement credential must also echo the whole selected challenge: the realm, the method, the intent, the request, the expiry, and any opaque and digest. Each field must be present and must match. The package rejects a credential with a missing or altered field as malformed, and that includes a bare `{id}` and a changed method.
- **A retry is now idempotent.** One challenge settles once, and the client does not pay twice.

  The package keeps the receipt of a settled challenge in a ledger for a short time. `mpp.settlement_replay_ttl`, which is `MPP_SETTLEMENT_REPLAY_TTL`, sets that time, and it defaults to 300 seconds. A retry of a payment that already settled, for example after the client lost the `200` response, now replays that original receipt with a `200`. On a metered route, it replays the same session, and spends no extra credit. The package does not issue a fresh `402` that the client would pay again.

  A retry that arrives while the first settlement is still in progress receives a `409` with `Retry-After`. That response means "wait", and not "pay again". It is not a fresh challenge.

  The lifetime of the settlement lock now has its own config key, `mpp.settle_lock_ttl`, which defaults to 300 seconds. Set it above the time that your slowest rail needs. It was a fixed 10 seconds, which is shorter than an on-chain confirmation on Tempo. The lock could therefore expire during settlement, and two requests could settle one challenge in parallel.

  This adds `Square1\Mpp\Protocol\SettlementLedger`, and the config keys `mpp.settlement_replay_ttl` and `mpp.settle_lock_ttl`. New cases in `SettlementMiddlewareTest` cover replay, the replay of one metered session, and the 409 under contention.
- **A challenge id is now unique for each mint.** This fixes a replay defect. The id is the seven-slot binding HMAC. Every input to it was deterministic: the realm, the method, the intent, the request, the expiry to the second, and the opaque value. Two 402 responses that the package minted for the same route and price within one second therefore had the same id. The new challenge that followed a rejected replay could then mint the burned id again, and one payment could settle twice. That produced a second metered session, or a second free response that the charge idempotency of the rail covered.

  A random 128-bit `nonce` per mint now travels in `opaque`. The package binds it into slot 7, and a conformant client echoes it. Every id is therefore unique, belongs to one payer, and cannot return. This also makes the Stripe idempotency key, which is the challenge id, unique for each mint. `ChallengeFactoryTest` covers the uniqueness of the id, and `SettlementMiddlewareTest` covers the challenge that follows a rejection.

### Added

- `MPP_ACCEPT` (pipe-separated default offered rails) and `MPP_REALM` (protection space, defaults to the request host).
- `MPP_CACHE_STORE`, which is `mpp.cache_store`. It names the cache store for the challenges, the settlement replay ledger and the settlement lock. Null follows the default of the application. A production deployment on more than one node must point it at a shared atomic backend, such as redis, memcached or the database. The `array` and `file` drivers cannot provide single use or locking across nodes.
- `MPP_REPLAY_MAX_BYTES` (`mpp.replay_max_bytes`, default 262144), the largest response body snapshotted for idempotent replay. Streamed, binary, and larger responses are not snapshotted.
- **The discovery document.** The package generates `/openapi.json` from the live router. It resolves the price of each route in the same way as the runtime does, through the price-book entries, the global defaults and the request builder of each rail. It emits `amount: null` for a route that it prices per request. The document therefore matches the live `402`. The config is under `mpp.discovery.{enabled,title,version}`. The package serves the document at `GET /openapi.json`, which the discovery draft requires, on a route named `mpp.discovery`.
- `Square1\Mpp\Support\Jcs`, which canonicalizes JSON as RFC 8785 defines. It rejects a float, so the package can never round a monetary value without a message. This release also adds `Support\Base64Url`.
- The testbench workbench is now also the conformance demonstration in this repository. Run `vendor/bin/testbench serve --port=4242`, and then run `mppx validate` against `GET:/paid`.

### Removed

- `MethodConfigValidator::assertSingleDialect`. The method would have thrown in production on any route that offered stripe and tempo together, and the test fakes of 1.x hid that. The split between dialects that the method enforced no longer exists.
- The unused config keys `methods.tempo.realm`, `methods.tempo.network_id` and `methods.tempo.payment_method_types`. Each tempo challenge now advertises a random `methodDetails.memo`, and the paid transfer must carry that exact value. That is the model of the Tempo charge draft, so the package configures no memo that it derives from the realm. Tempo never advertises the method details of a fiat rail.

### Upgrading

- Most code on your server needs no change. Republish the config, or compare it with the new file, to add `realm`, `accept` and `discovery`.
- Rewrite every 1.x client integration against the format of the spec, or use a standard MPP client such as `npx mppx`. Validate the result with the command above.
- If you offer the Stripe rail, set `STRIPE_NETWORK_ID` to a `profile_…` value from the Stripe Dashboard. To mint a stripe `402` without it now throws, and no longer emits a challenge that is not conformant. A deployment that relied on the earlier warning must set the value before it upgrades.
- On a deployment with more than one node, set `MPP_CACHE_STORE` to a shared atomic backend, such as redis, memcached or the database. The single-use guarantee and the settlement lock need storage that every node can read, and the `array` and `file` drivers cannot provide it.
- Tempo now defaults to the Moderato testnet. For a mainnet deployment, set `TEMPO_CHAIN_ID=4217`, `TEMPO_TOKEN=0x20C000000000000000000000b9537d11c60E8b50` and a mainnet `TEMPO_RPC_URL`, all together.

## [1.2.0] - 2026-08-07

### Added

- **Dynamic pricing.** The price of a route can now depend on the request, and the route definition no longer fixes it. One endpoint can therefore charge $2 to one caller and $5 to another. Register a named resolver under `mpp.pricing.resolvers`, as a `[Class::class, 'method']` pair. The pair receives the `Request` and the resolved `PaymentSpec`. It returns an array of overrides, or `null` to keep the price of the route.
- Attach a resolver in one of four places: `pricing=` on the middleware, as in `mpp:5.00,USD,pricing=tiered`; `pricing:` on the attribute, as in `#[RequiresPayment(amount: '5.00', pricing: ['tiered'])]`; a `pricing` key on a `price_book` entry; or `mpp.pricing.global`, for every guarded route. The global resolvers run first, then the resolvers of the route. Both run in order, without duplicates, and each resolver sees the result of the one before it. An unknown name throws.
- A resolver can override five keys: `amount`, `currency`, `grants`, `scope` and `free`. Any other key throws, and that includes `method` and `methods`. A resolver sets the price, and not the payment terms around it.
- **A resolver can own the price.** A route can now state no amount, and leave the price to its resolvers, as in `mpp:scope=report,pricing=tiered`. The route no longer has to declare a placeholder that nothing reads.

  One rule decides the outcome: something must supply a price before the gate, and that is the route, a global default, or a resolver. When a route states no price and every resolver declines, the request raises the new `UnpriceableRequestException`. That exception names the route and the resolvers that ran. The package does not serve the request, and does not price it at zero.

  To declare an amount is still correct where you have a real list price. That amount is what an unrecognised caller pays, and it is the fallback when you disable a resolver later.
- `Square1\Mpp\Exceptions\UnpriceableRequestException`. It sits beside `InvalidConfigurationException`, under `MppException`. It is a separate exception because a declined price is not a defect in the configuration. The configuration is valid, the outcome depends on the request, and the exception can occur in production long after a deploy. Read it as "a resolver returned null for this caller".
- **Free requests.** A resolver that returns `['free' => true]` serves the route with no challenge, no session and no receipt. A resolver must state this explicitly. An `amount` that is zero, negative, or that the package cannot read, throws instead of giving the resource away. `free => true` beside an `amount` also throws. A waived request still runs its preconditions, and never reaches the payment gate, so it needs no working settlement rail.
- `Money::isValidAmount()`. It is now the one definition of a well-formed decimal amount for the package.
- A `price_book` entry now accepts its `methods`, `preconditions` and `pricing` lists as an array, or as a pipe-separated string such as `'stripe|tempo'`.

### Fixed

- **A defect in the preconditions.** The package skipped the preconditions on a `#[RequiresPayment]` attribute, and gave no message, when the route used automatic enforcement (`MPP_ATTRIBUTES_ENABLED=true`) instead of the `mpp` middleware. Such a route reaches the payment gate without that middleware, and the checks ran inside that middleware. The checks therefore never ran, and the package charged the request as though they had passed. See the Upgrading section below. This changes the responses of those routes.

### Changed

- The `amount` of a price resolver now passes the same format rule that converts it at mint time. The package therefore rejects `'1e2'` or `'+2.00'` where you set it, and not later with a less specific error. This affects only the new pricing feature.
- The documentation no longer describes how to offer several rails from one `402`, through `accept`, `methods=` or `methods:`. The capability itself does not change. The package ships only Stripe and Tempo, and those two cannot form a valid pair, because Tempo uses a different wire format and cannot share a challenge with a native rail. The capability therefore does nothing until you add a custom native `Verifier`. The published config no longer contains the `accept` key. A config that you published earlier, and that sets the key, still works. A new README section, "Can One Route Offer Both Rails?", explains why a 402 quotes one rail, and shows how to negotiate the rail for each request.
- Internal: the pricing, the preconditions and the path for a free request now run in a new `PaymentPipeline`. That class is the one path from a guarded route to the payment gate. Both middlewares share it, so neither route style can omit a step that the other style runs. `PaymentGate` now decides only how a chargeable request pays.
- Internal: the constructors of `RequirePayment` and `EnforcePaymentAttributes` changed. The container resolves both classes. The documented "Stripe and Tempo on One Route" method therefore still works, where you inject `RequirePayment` and call `handle($request, $next, ...$args)`. Only a direct `new RequirePayment(...)` call breaks.
- Internal: `PreconditionRunner`, `PriceResolver`, and a shared `ResolvesNamedCallables` trait extracted. `PaymentGate`'s constructor is unchanged from 1.1.0.
- `PaymentSpec::$amount` is now `?string`. It is null while a route waits for its resolvers. A new `isPriced()` method sits beside it. A price resolver can therefore receive a null amount. A resolver that computes a percentage of `$spec->amount` must handle that case. A precondition check does not change, because the price assertion runs before the checks. A check therefore always receives a real amount.
- The "needs an amount" error moved from `SpecResolver` to the pipeline. The pipeline is the only class that knows whether the resolvers ran and declined. One message that names the route now replaces the two earlier messages.

### Upgrading

The new features need no change from you. Dynamic pricing does nothing until you register a resolver, and every existing route keeps its current price.

The fix to the preconditions can change what a live endpoint returns. It affects you only when both of these are true:

- `mpp.attributes.enabled` is `true` (`MPP_ATTRIBUTES_ENABLED`, default `false`), and
- a controller action carries `#[RequiresPayment(preconditions: [...])]` and is guarded by automatic enforcement rather than by the `mpp` middleware.

On those routes, the checks now run as the documentation describes. A request that previously received a `402` can now receive whatever the check returns, which is usually a `404` or a `403`. Before you upgrade, find those routes. Confirm that each check does what you intended when you wrote it, because it never ran:

```bash
# `preconditions:` is attribute syntax; a config registry entry reads
# `'preconditions' =>`, so this finds the routes and not the definitions.
grep -rn "preconditions:" app/
```

## [1.1.0] - 2026-06-30

### Added

- **Preconditions.** These are named checks that run before the package mints a `402` or settles a payment. The package therefore rejects a request that it can never fulfil, such as a request for a missing resource or a request from a blocked user, without a charge. Each check is a `[Class::class, 'method']` pair under `mpp.preconditions.checks`. The package calls the pair with the `Request` and the resolved `PaymentSpec`. The pair returns a `Response` to reject the request, or `null` to continue.
- `mpp.preconditions.global` runs on every guarded route. A route adds its own checks with `preconditions=` on the middleware, or with `preconditions:` on the attribute. The global checks run first, then the checks of the route. Both run in order, without duplicates. The first `Response` ends the run. An unknown name throws, so a typo fails closed.

## [1.0.0] - 2026-06-30

The first release. It adds HTTP 402 machine payments to Laravel, over two settlement rails.

### Added

- **Stripe rail.** Shared Payment Tokens settled as PaymentIntents, with an optional per-payer Stripe Customer resolver.
- **The Tempo rail.** It settles pathUSD on-chain, in the wire dialect of mppx. A standard `npx mppx` client can pay it. The rail is pure PHP. It needs no Node sidecar, and the server holds no signing key. The client signs the transfer and pays its own gas.
- Challenges that the package signs with an HMAC, over the payment terms and the expiry. The package burns a challenge after settlement, so no one can replay a payment. The package derives the signing key from `APP_KEY`, until you set `MPP_CHALLENGE_SECRET`.
- Receipts on the paid response (`Payment-Receipt`).
- **Metered access.** One payment grants N accesses, through a prepaid session that the `Payment-Session` header carries. The package checks the scope of each spend, and decrements the balance atomically, so it never oversells under concurrency. There are two session stores, one for the cache and one for the database, and both follow the defaults of the application.
- Three ways to protect a route: middleware arguments, `mpp` with a `#[RequiresPayment]` attribute, or automatic attribute enforcement on the route groups that you choose.
- Native challenges for several rails. Each offered method gets one signed `accepts[]` entry, and each signature is valid only for its own method.
- Price book presets, global price defaults, and validation of the rail configuration. The validation throws when the config lacks a value that the package needs to mint a `402`. It warns once when the config lacks a value that only settlement needs.
- Two extension points: the `Verifier` interface, for a new rail, and `SettlementChecker`, for a rail that settles through a transaction that already exists outside your application.

[1.2.0]: https://github.com/square1-io/laravel-mpp/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/square1-io/laravel-mpp/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/square1-io/laravel-mpp/releases/tag/1.0.0
