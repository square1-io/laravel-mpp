<?php

use Square1\Mpp\Settlement\StripeVerifier;
use Square1\Mpp\Settlement\TempoVerifier;

return [

    /*
    |--------------------------------------------------------------------------
    | Challenge signing secret
    |--------------------------------------------------------------------------
    |
    | HMAC key used to sign payment challenges so a client cannot tamper with the
    | price, scope or grant count between the 402 and the paid retry. Optional:
    | when MPP_CHALLENGE_SECRET is unset the package derives a domain-separated
    | key from APP_KEY, so it works out of the box. Set an explicit, strong,
    | stable string in production so you can rotate it independently — rotating
    | the challenge key only invalidates in-flight 402s, never issued sessions.
    |
    */
    'secret' => env('MPP_CHALLENGE_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Lifetimes (seconds)
    |--------------------------------------------------------------------------
    */
    'challenge_ttl' => (int) env('MPP_CHALLENGE_TTL', 300),
    'session_ttl' => (int) env('MPP_SESSION_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Settlement replay window (retry idempotency)
    |--------------------------------------------------------------------------
    |
    | How long a settled challenge's receipt is remembered so a RETRY of the
    | same payment replays that receipt instead of being charged again. Covers
    | the "the 200 never reached the client, so it retried" case: settlement
    | burns the challenge, and without this the retry would get a fresh 402 and
    | pay twice. Set it to comfortably exceed your clients' retry/timeout window
    | (default 5 min); larger just retains more records, it is never unsafe.
    |
    */
    'settlement_replay_ttl' => (int) env('MPP_SETTLEMENT_REPLAY_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Replayable response size limit (bytes)
    |--------------------------------------------------------------------------
    |
    | The largest response body the replay ledger will snapshot for idempotent
    | retries (default 256 KB). A larger response, or a streamed/binary one whose
    | body is never buffered, is not snapshotted: a lost-response retry of such an
    | endpoint takes a fresh challenge instead of replaying. So a paid endpoint
    | returning a big download or a stream is NOT lost-response-idempotent — its
    | buyer could be charged again on a dropped connection. Keep such endpoints
    | buffered and within this limit if you need replay, or make them idempotent
    | in the application.
    |
    */
    'replay_max_bytes' => (int) env('MPP_REPLAY_MAX_BYTES', 262144),

    /*
    |--------------------------------------------------------------------------
    | Settlement lock lifetime
    |--------------------------------------------------------------------------
    |
    | How long the per-challenge settlement lock is held, serialising concurrent
    | retries so one payment settles exactly once. It MUST outlive your slowest
    | verifier's worst-case runtime, or the lock lapses mid-settlement and two
    | requests can settle in parallel. The bound is the Tempo rail's on-chain
    | confirm: up to `methods.tempo.poll_attempts × poll_delay_ms` (~20s at the
    | defaults). The default here clears that comfortably; raise it if you raise
    | the Tempo poll budget.
    |
    */
    'settle_lock_ttl' => (int) env('MPP_SETTLE_LOCK_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Protocol cache store
    |--------------------------------------------------------------------------
    |
    | The cache store holding the package's protocol state: issued challenges,
    | the settlement replay ledger, and the per-challenge settlement lock. Null
    | follows your application's default cache store, which is fine for local
    | development and single-process testing.
    |
    | PRODUCTION MULTI-NODE DEPLOYMENTS MUST POINT THIS AT A SHARED, ATOMIC
    | BACKEND (redis / memcached / database). The single-use guarantee (a
    | challenge settles exactly once) and the settlement lock both depend on
    | atomic operations against storage every node can see. The `file` and
    | `array` drivers provide neither: `array` is per-process, and `file` cannot
    | lock across nodes — with either, two concurrent nodes can settle the same
    | challenge twice.
    |
    */
    'cache_store' => env('MPP_CACHE_STORE'),     // null = app default cache store

    /*
    |--------------------------------------------------------------------------
    | Allow MPP over unencrypted HTTP
    |--------------------------------------------------------------------------
    |
    | The MPP spec forbids issuing a Payment challenge (or accepting a credential)
    | over plain HTTP: the 402 and the credential carry payment terms and proofs.
    | The gate therefore refuses a non-HTTPS request by default. Set this to true
    | ONLY for local development and tests served over HTTP.
    |
    | Behind a TLS-terminating load balancer or proxy, do NOT set this: instead
    | configure Laravel's trusted proxies (bootstrap/app.php `trustProxies`) so
    | `$request->isSecure()` reflects the real client scheme via X-Forwarded-Proto.
    |
    */
    'allow_insecure' => (bool) env('MPP_ALLOW_INSECURE', false),

    /*
    |--------------------------------------------------------------------------
    | Default (primary) settlement method
    |--------------------------------------------------------------------------
    |
    | The rail a route settles over unless it names its own with `method=` on the
    | middleware or `method:` on the #[RequiresPayment] attribute. It must be one
    | of the `methods` keys below.
    |
    | To offer SEVERAL rails from one route, set `accept` below (or per-route
    | `methods=`): the 402 then carries one Payment challenge per rail and the
    | client answers exactly one. See "Offering Both Rails on One Route" in the
    | README.
    |
    */
    'default_method' => env('MPP_DEFAULT_METHOD', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Protection realm
    |--------------------------------------------------------------------------
    | The `realm` parameter minted into every challenge (RFC 9110 protection
    | space). Defaults to the request host when unset, which is right for
    | almost everyone; set it when serving one payment surface across several
    | hostnames.
    */
    'realm' => env('MPP_REALM'),

    /*
    |--------------------------------------------------------------------------
    | Default offered methods
    |--------------------------------------------------------------------------
    | The ordered set of rails offered on a 402 when a route does not name its
    | own (`method=` / `methods=`). One WWW-Authenticate Payment challenge is
    | minted per field line, one line per method; clients pick via
    | Accept-Payment. Unset, only `default_method` is offered — byte-identical
    | to single-rail behaviour.
    |
    | ORDER MATTERS. The first rail listed leads the challenge set, and a client
    | that sends no Accept-Payment answers whichever leads — some take it
    | without checking they can pay it. List the rail your typical caller can
    | pay first. `default_method` chooses the rail for routes that name none; it
    | does not reorder this list.
    */
    'accept' => env('MPP_ACCEPT') ? explode('|', (string) env('MPP_ACCEPT')) : null,

    /*
    |--------------------------------------------------------------------------
    | Discovery document
    |--------------------------------------------------------------------------
    | The advisory OpenAPI document MPP agents use to find payable endpoints.
    | Generated from the live router — never hand-maintained, never stale.
    | The discovery draft requires it at GET /openapi.json, so the path is not
    | configurable. Disable it if the app serves its own OpenAPI document, and
    | merge the x-payment-info extension there instead.
    */
    'discovery' => [
        'enabled' => (bool) env('MPP_DISCOVERY', true),
        'title' => env('MPP_DISCOVERY_TITLE'),
        'version' => env('MPP_DISCOVERY_VERSION', '1.0.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global price defaults
    |--------------------------------------------------------------------------
    |
    | Fallbacks for routes that don't state a price themselves. A route's own
    | middleware args or #[RequiresPayment] always win — these only fill what's
    | omitted — so you can set a house price once and write `mpp:scope=clip`
    | (or a bare attribute) instead of repeating the amount on every route.
    | Leave `amount` null to keep an explicit price mandatory per route (the
    | default: nothing changes unless you set one). The method/network defaults
    | already live in `default_method` / `methods.*` above.
    |
    */
    'defaults' => [
        'amount' => env('MPP_DEFAULT_AMOUNT'),                  // e.g. '0.50'; null = no global price
        'currency' => env('MPP_DEFAULT_CURRENCY', 'USD'),
        'grants' => (int) env('MPP_DEFAULT_GRANTS', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Settlement methods (rails)
    |--------------------------------------------------------------------------
    |
    | Each method maps to a Verifier implementation plus its configuration.
    | The protocol layer is rail-agnostic: every rail shares the one MPP wire
    | format, and settlement sits behind the Verifier interface so additional
    | rails can be added without touching it.
    |
    | ADDING A RAIL is up to three steps:
    |   1. Implement Square1\Mpp\Settlement\Verifier (verify a settlement PROOF
    |      against the Challenge — never trust the client's word). For a rail
    |      whose settlement is a pre-existing external transaction (rather than a
    |      synchronous API call you initiate), implement a
    |      Square1\Mpp\Settlement\SettlementChecker and reuse the matching logic
    |      pattern in TempoVerifier.
    |   2. If the rail's 402 `request` payload is not the default fiat shape
    |      (amount in minor units, currency, methodDetails), implement a
    |      Square1\Mpp\Protocol\Requests\RailRequestBuilder and set it as
    |      `request_builder` in the method block. Omit this only for a genuinely
    |      Stripe-shaped fiat rail; otherwise the fiat builder mints the wrong
    |      request shape for your rail.
    |   3. Add a `methods.<name>` block here with at least a `verifier` (and the
    |      `request_builder` from step 2 if you wrote one). Use it on a route with
    |      `method=<name>`, or make it the house rail with `default_method`.
    | Nothing in the protocol layer needs to change.
    |
    | VALIDATION: the gate checks a rail's config the first time a route offers it
    | (keyed on the verifier). A shipped rail missing a value its 402 cannot be
    | minted or paid without — Tempo's recipient/token/chain_id — throws
    | InvalidConfigurationException so the mistake surfaces immediately. A merely
    | recommended value (Stripe's secret_key/network_id, Tempo's rpc_url) logs a
    | one-time warning instead, so "emit the 402 now, settle once configured" stays
    | a valid workflow. Custom verifiers are skipped — validate their own config.
    |
    */
    'methods' => [
        'stripe' => [
            'verifier' => StripeVerifier::class,
            'secret_key' => env('STRIPE_SECRET_KEY'),   // sk_test_... / sk_live_..., needed to SETTLE. The gate warns if unset; the 402 still mints.
            'network_id' => env('STRIPE_NETWORK_ID'),    // profile_..., advertised in the 402 so a wallet can scope an SPT to you. REQUIRED: the gate refuses to mint a stripe 402 without it.
            'api_version' => env('STRIPE_API_VERSION', '2026-05-27.preview'),
            'payment_method_types' => ['card'],

            // Optional. Map an incoming request to a Stripe Customer id on YOUR
            // (seller) account, attached to the PaymentIntent so charges are
            // grouped per payer instead of appearing as guest charges. Use a
            // [Class::class, 'method'] pair (resolved via the container) that
            // receives the Request and returns a `cus_...` id or null — NOT a
            // closure, which would break `php artisan config:cache`.
            'customer_resolver' => null,
        ],

        // Second rail: Tempo (on-chain stablecoin), payable by a stock
        // `npx mppx <url>` agent. Pure-PHP, no Node sidecar and no server
        // signing key: the client signs a complete pathUSD transfer and
        // pays its own gas; the package only verifies the signed transaction
        // against the challenge, broadcasts it via eth_sendRawTransaction, and
        // confirms it mined. Offer it alone (`method=tempo` / `default_method`)
        // or alongside stripe (`accept` above, or `methods=stripe|tempo`).
        'tempo' => [
            'verifier' => TempoVerifier::class,

            // The three network values below (rpc_url, chain_id, token) DEFAULT
            // TO MODERATO TESTNET, so the rail is payable out of the box with
            // only a `recipient` set. FOR MAINNET, SET ALL THREE TOGETHER:
            //   rpc_url  = your Tempo mainnet JSON-RPC endpoint
            //   chain_id = 4217
            //   token    = 0x20C000000000000000000000b9537d11c60E8b50
            // NEVER MIX NETWORKS — a mainnet token on the testnet chain (or
            // vice versa) reverts `TIP20: Uninitialized`.

            // The Tempo JSON-RPC endpoint the package broadcasts through.
            'rpc_url' => env('TEMPO_RPC_URL', 'https://rpc.moderato.tempo.xyz'),

            // The chain id the signed transaction must target. Moderato testnet
            // is 42431; Tempo mainnet is 4217.
            'chain_id' => (int) env('TEMPO_CHAIN_ID', 42431),

            // The TIP-20 token (pathUSD) the transfer must be denominated in, and
            // its decimals (used to convert the route's decimal amount to minor
            // units). Defaults to Moderato testnet pathUSD; mainnet pathUSD is
            // 0x20C000000000000000000000b9537d11c60E8b50.
            'token' => env('TEMPO_TOKEN', '0x20c0000000000000000000000000000000000000'),
            'decimals' => (int) env('TEMPO_DECIMALS', 6),

            // The address funds must settle to. Funds cannot be diverted: the
            // transfer is validated against this before broadcast. Required: the
            // gate refuses to mint a Tempo 402 without recipient, token + chain_id.
            'recipient' => env('TEMPO_RECIPIENT'),

            // Finality: confirmations required before the resource is served.
            'confirmations' => (int) env('TEMPO_MIN_CONFIRMATIONS', 1),

            // Receipt polling: how long to wait for the broadcast tx to mine.
            'poll_attempts' => (int) env('TEMPO_POLL_ATTEMPTS', 40),
            'poll_delay_ms' => (int) env('TEMPO_POLL_DELAY_MS', 500),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metered session storage
    |--------------------------------------------------------------------------
    |
    | One payment can grant N accesses; the server tracks a prepaid "session"
    | (credit balance) and decrements it atomically per request. Storage inherits
    | your application's own preferences by default:
    |
    |   driver = 'cache'    -> your default cache store. If your app's cache is
    |                          Redis, sessions live in Redis automatically. Leave
    |                          `cache_store` null to follow the app default, or
    |                          name a specific store from config/cache.php.
    |   driver = 'database' -> a dedicated table on your default DB connection
    |                          (publish the migration). Leave `connection` null to
    |                          follow the app default.
    |
    | Both drivers decrement atomically and are oversell-proof under concurrency.
    |
    */
    'sessions' => [
        'driver' => env('MPP_SESSION_DRIVER', 'cache'),
        'cache_store' => env('MPP_SESSION_CACHE_STORE'),     // null = app default cache store
        'connection' => env('MPP_SESSION_DB_CONNECTION'),    // null = app default db connection
        'table' => 'mpp_sessions',
        'prefix' => 'mpp:session:',
    ],

    /*
    |--------------------------------------------------------------------------
    | #[RequiresPayment] attribute enforcement
    |--------------------------------------------------------------------------
    |
    | When enabled, the package registers a middleware on the named route groups
    | that enforces payment on any controller action carrying the
    | #[RequiresPayment] attribute — no per-route wiring needed. The attribute is
    | read at request time, so route caching is unaffected.
    |
    | This is opt-in. With it disabled you can still apply payments explicitly:
    |   - ->middleware('mpp:0.50,USD')                 // arguments
    |   - ->middleware('mpp')  + #[RequiresPayment(...)] on the action
    |
    */
    'attributes' => [
        'enabled' => (bool) env('MPP_ATTRIBUTES_ENABLED', false),
        'middleware_groups' => ['web', 'api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Price book
    |--------------------------------------------------------------------------
    |
    | Optional named pricing presets referenced by scope key, e.g.
    | ->middleware('mpp:report.basic'). An entry may also carry its own
    | `preconditions` and `pricing` lists (array, or a pipe-separated string),
    | which a route's own option overrides.
    |
    */
    'price_book' => [
        // 'report.basic' => ['amount' => '0.50', 'currency' => 'USD', 'grants' => 10],
        // 'report.pro'   => ['amount' => '5.00', 'pricing' => ['tiered']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dynamic pricing
    |--------------------------------------------------------------------------
    |
    | Named resolvers that set the price per REQUEST rather than per route, so
    | one endpoint can charge $2 to one caller and $5 to another. Each is a
    | [Class::class, 'method'] pair (resolved via the container, so
    | config:cache-safe) called with the Request and the resolved PaymentSpec,
    | returning an array of overrides or null to keep the route's static price:
    |
    |     return ['amount' => '2.00'];                 // cheaper for this caller
    |     return ['amount' => '2.00', 'grants' => 20, 'scope' => 'report.pro'];
    |     return ['free' => true];                     // waive the charge entirely
    |     return null;                                 // leave the price alone
    |
    | Overridable keys: amount, currency, grants, scope, free. Anything else
    | throws, as does a zero/negative/non-numeric amount — waiving a charge has
    | to be said out loud with `free => true`, so a resolver that miscomputes an
    | amount fails instead of giving the resource away. Rail selection
    | (method/methods) is not a resolver's to change.
    |
    | `global` resolvers apply to every gated route. A route adds its own with
    | `pricing=` on the middleware (`mpp:5.00,USD,pricing=tiered`) or
    | `pricing: [...]` on the attribute. Globals run first, then the route's own,
    | in order and de-duplicated, each seeing the result of the last. An unknown
    | name throws, so a typo can never silently fall back to the static price.
    |
    | Something must supply a price before the gate: the route, the global
    | default above, or a resolver. Declare an amount on the route when a list
    | price is real — it is what unrecognised callers pay, and the fallback if a
    | resolver is disabled. Omit it (`mpp:scope=report,pricing=tiered`) when there
    | is no list price to state, and the resolvers own it; if they all decline
    | then, the request throws rather than being served.
    |
    | Note `null` means "no opinion", NOT "no charge". Waiving is `free => true`.
    |
    | The price a buyer pays is the one bound into the signed 402 — settlement
    | verifies against the stored challenge, never a re-resolved spec — so a
    | resolver whose answer changes between the 402 and the paid retry cannot
    | alter what that buyer was quoted.
    |
    */
    'pricing' => [
        'resolvers' => [
            // 'tiered' => [\App\Mpp\Pricing\TieredPrice::class, 'price'],
        ],

        'global' => [
            // 'tiered',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Preconditions
    |--------------------------------------------------------------------------
    |
    | Named checks that run BEFORE a 402 is minted or a payment settled, so a
    | request that can never be fulfilled (a missing resource, a blocked user)
    | is rejected without charging. Each check is a [Class::class, 'method'] pair
    | (resolved via the container, so config:cache-safe) called with the Request
    | and the resolved PaymentSpec; it returns a Response to reject (e.g. a 404)
    | or null to proceed.
    |
    | `global` checks run on every gated route. A route adds its own, additively,
    | with `preconditions=` on the middleware (`mpp:1.00,USD,preconditions=postexists`)
    | or `preconditions: [...]` on the attribute. Globals run first, then the
    | route's own, in order and de-duplicated; the first Response wins. An unknown
    | name throws, so a typo can never silently skip a check.
    |
    */
    'preconditions' => [
        'checks' => [
            // 'postexists'     => [\App\Mpp\Checks\PostExists::class, 'check'],
            // 'usernotblocked' => [\App\Mpp\Checks\UserNotBlocked::class, 'check'],
        ],

        'global' => [
            // 'usernotblocked',
        ],
    ],
];
