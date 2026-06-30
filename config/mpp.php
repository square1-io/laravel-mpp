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
    | Default (primary) settlement method
    |--------------------------------------------------------------------------
    |
    | The primary method, used for ordering and for single-method back-compat:
    | when a challenge offers exactly one method, its wire shape is identical to
    | a pre-multi-rail challenge. It must be one of the `methods` keys below.
    |
    */
    'default_method' => env('MPP_DEFAULT_METHOD', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Offered methods (multi-rail)
    |--------------------------------------------------------------------------
    |
    | The ordered set of native-dialect settlement methods a challenge offers by
    | default, as an array of `methods` keys. A native challenge emits one signed
    | `accepts[]` entry per offered method (each independently signed for THAT
    | method), and a client picks the first it can satisfy.
    |
    | Leave this null/unset to offer just the `default_method` — which keeps the
    | single-method wire shape byte-identical to before. Set it to offer several
    | native rails at once. Tempo speaks the separate mppx dialect and must be
    | selected as the primary/single method with `method=tempo` or
    | `default_method=tempo`. A route can override native offers per-request via
    | the middleware (`mpp:0.50,USD,methods=stripe|other`) or the
    | #[RequiresPayment(methods: ['stripe', 'other'])] attribute.
    |
    */
    'accept' => null, // e.g. ['stripe', 'other-native-rail']

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
    | already live in `default_method` / `accept` / `methods.*` above.
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
    | Each native method maps to a Verifier implementation plus its configuration.
    | The native protocol layer is rail-agnostic: settlement sits behind the
    | Verifier interface so additional native rails can be added without touching
    | it. Tempo is configured here too, but it is handled by the mppx Tempo gate.
    |
    | ADDING A RAIL is two steps:
    |   1. Implement Square1\Mpp\Settlement\Verifier (verify a settlement PROOF
    |      against the Challenge — never trust the client's word). For a rail
    |      whose settlement is a pre-existing external transaction (rather than a
    |      synchronous API call you initiate), implement a
    |      Square1\Mpp\Settlement\SettlementChecker and reuse the matching logic
    |      pattern in TempoVerifier.
    |   2. Add a `methods.<name>` block here with at least a `verifier`, and list
    |      `<name>` in `accept` (above) to offer it.
    | Nothing in the native protocol layer needs to change.
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
            'secret_key' => env('STRIPE_SECRET_KEY'),   // sk_test_... / sk_live_... — needed to SETTLE; the gate warns if unset
            'network_id' => env('STRIPE_NETWORK_ID'),    // profile_... — a Link/agent wallet needs it to scope an SPT; the gate warns if unset
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

        // Second rail: Tempo (on-chain stablecoin), speaking the mppx wire
        // dialect so a stock `npx mppx <url> --network testnet` agent can pay a
        // route gated with `mpp:…,method=tempo`. Pure-PHP, no Node sidecar and no
        // server signing key: the client signs a complete pathUSD transfer and
        // pays its own gas; the package only verifies the signed transaction
        // against the challenge, broadcasts it via eth_sendRawTransaction, and
        // confirms it mined. Set `default_method=tempo` or use
        // `method=tempo` on a route to offer it.
        'tempo' => [
            'verifier' => TempoVerifier::class,

            // The Tempo JSON-RPC endpoint the package broadcasts through.
            'rpc_url' => env('TEMPO_RPC_URL', 'https://rpc.moderato.tempo.xyz'),

            // The chain id the signed transaction must target (Tempo testnet).
            'chain_id' => (int) env('TEMPO_CHAIN_ID', 42431),

            // The TIP-20 token (pathUSD) the transfer must be denominated in, and
            // its decimals (used to convert the route's decimal amount to minor
            // units). `currency` is accepted as an alias for `token`.
            'token' => env('TEMPO_TOKEN', '0x20c0000000000000000000000000000000000000'),
            'decimals' => (int) env('TEMPO_DECIMALS', 6),

            // The address funds must settle to. Funds cannot be diverted: the
            // transfer is validated against this before broadcast. Required: the
            // gate refuses to mint a Tempo 402 without recipient, token + chain_id.
            'recipient' => env('TEMPO_RECIPIENT'),

            // Finality: confirmations required before the resource is served.
            'confirmations' => (int) env('TEMPO_MIN_CONFIRMATIONS', 1),

            // The realm advertised in the 402 and bound into the on-chain
            // attribution memo. Null follows the request host (mppx's default).
            'realm' => env('TEMPO_REALM'),

            // Receipt polling: how long to wait for the broadcast tx to mine.
            'poll_attempts' => (int) env('TEMPO_POLL_ATTEMPTS', 40),
            'poll_delay_ms' => (int) env('TEMPO_POLL_DELAY_MS', 500),

            // Advertised in the native dialect's accept entry (unused when tempo
            // is the sole/primary method and the mppx dialect is emitted).
            'network_id' => env('TEMPO_NETWORK_ID'),
            'payment_method_types' => ['stablecoin'],
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
    | ->middleware('mpp:report.basic').
    |
    */
    'price_book' => [
        // 'report.basic' => ['amount' => '0.50', 'currency' => 'USD', 'grants' => 10],
    ],
];
