<?php

use Square1\Mpp\Settlement\StripeVerifier;
use Square1\Mpp\Settlement\TempoVerifier;

return [

    /*
    |--------------------------------------------------------------------------
    | Challenge signing secret
    |--------------------------------------------------------------------------
    |
    | The HMAC key that signs a payment challenge. It stops a client from changing
    | the price, the scope or the grant count between the 402 and the paid retry.
    |
    | The key is optional. When MPP_CHALLENGE_SECRET is unset, the package derives
    | a domain-separated key from APP_KEY, so the package works with no config.
    |
    | Set an explicit, strong and stable string in production. You can then rotate
    | it separately from APP_KEY. A rotation invalidates only the 402 responses
    | that are in flight. It never invalidates a session that the server issued.
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
    | How long the package remembers the receipt of a settled challenge, so that a
    | RETRY of the same payment replays that receipt instead of paying again.
    |
    | This covers the case where the 200 response never reached the client and the
    | client retried. Settlement burns the challenge. Without this window, the
    | retry would receive a fresh 402 and would pay a second time.
    |
    | Set the window comfortably above the retry and timeout window of your
    | clients. The default is five minutes. A larger value only retains more
    | records, and it is never unsafe.
    |
    */
    'settlement_replay_ttl' => (int) env('MPP_SETTLEMENT_REPLAY_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Replayable response size limit (bytes)
    |--------------------------------------------------------------------------
    |
    | The largest response body that the replay ledger snapshots for an idempotent
    | retry. The default is 256 KB.
    |
    | The ledger does not snapshot a larger response. It also does not snapshot a
    | streamed or binary response, because the package never buffers such a body. A
    | retry after a lost response then takes a fresh challenge, and does not
    | replay.
    |
    | A paid endpoint that returns a large download or a stream is therefore NOT
    | idempotent after a lost response. Its buyer can pay again when a connection
    | drops. Keep such an endpoint buffered and within this limit if you need
    | replay. Otherwise make the endpoint idempotent in your application.
    |
    */
    'replay_max_bytes' => (int) env('MPP_REPLAY_MAX_BYTES', 262144),

    /*
    |--------------------------------------------------------------------------
    | Settlement lock lifetime
    |--------------------------------------------------------------------------
    |
    | How long the package holds the settlement lock for one challenge. The lock
    | serialises concurrent retries, so that one payment settles exactly once.
    |
    | The lifetime MUST be longer than the worst-case runtime of your slowest
    | verifier. If the lock expires during settlement, two requests can settle in
    | parallel.
    |
    | The Tempo rail sets the bound, through its on-chain confirmation. That takes
    | up to `methods.tempo.poll_attempts × poll_delay_ms`, which is about 20
    | seconds at the default values. The default here is comfortably above that.
    | Raise it if you raise the poll budget of Tempo.
    |
    */
    'settle_lock_ttl' => (int) env('MPP_SETTLE_LOCK_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Protocol cache store
    |--------------------------------------------------------------------------
    |
    | The cache store that holds the protocol state of the package: the issued
    | challenges, the settlement replay ledger, and the settlement lock for each
    | challenge.
    |
    | Null follows the default cache store of your application. That is correct for
    | local development and for testing in one process.
    |
    | A PRODUCTION DEPLOYMENT ON MORE THAN ONE NODE MUST NAME A SHARED ATOMIC
    | BACKEND, such as redis, memcached or database. Two guarantees depend on
    | atomic operations against storage that every node can read: that a challenge
    | settles exactly once, and that the settlement lock works.
    |
    | The `file` and `array` drivers provide neither guarantee. `array` is per
    | process, and `file` cannot lock across nodes. With either driver, two nodes
    | can settle the same challenge at the same time.
    |
    */
    'cache_store' => env('MPP_CACHE_STORE'),     // null = the default cache store of the app

    /*
    |--------------------------------------------------------------------------
    | Allow MPP over unencrypted HTTP
    |--------------------------------------------------------------------------
    |
    | The MPP spec forbids a server to issue a Payment challenge, or to accept a
    | credential, over plain HTTP. The 402 and the credential carry payment terms
    | and proofs. The gate therefore refuses a request that does not use HTTPS.
    |
    | Set this to true ONLY for local development, and for tests that run over
    | HTTP.
    |
    | Behind a load balancer or proxy that terminates TLS, do NOT set this.
    | Configure the trusted proxies of Laravel instead, in the `trustProxies` call
    | of bootstrap/app.php. `$request->isSecure()` then reports the real scheme of
    | the client, through X-Forwarded-Proto.
    |
    */
    'allow_insecure' => (bool) env('MPP_ALLOW_INSECURE', false),

    /*
    |--------------------------------------------------------------------------
    | Default (primary) settlement method
    |--------------------------------------------------------------------------
    |
    | The rail that a route settles over, unless the route names its own rail. A
    | route names one with `method=` on the middleware, or with `method:` on the
    | #[RequiresPayment] attribute. The value must be one of the `methods` keys
    | below.
    |
    | To offer SEVERAL rails from one route, set `accept` below, or set `methods=`
    | on the route. The 402 then carries one Payment challenge per rail, and the
    | client answers exactly one of them. See "Offering Both Rails on One Route" in
    | the README.
    |
    */
    'default_method' => env('MPP_DEFAULT_METHOD', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Protection realm
    |--------------------------------------------------------------------------
    |
    | The `realm` parameter that the package mints into every challenge. RFC 9110
    | calls it the protection space.
    |
    | The default is the host of the request, which is correct for almost every
    | application. Set it when you serve one payment surface across several host
    | names.
    |
    */
    'realm' => env('MPP_REALM'),

    /*
    |--------------------------------------------------------------------------
    | Default offered methods
    |--------------------------------------------------------------------------
    |
    | The ordered set of rails that a 402 offers, when a route does not name its
    | own set with `method=` or `methods=`.
    |
    | The package mints one WWW-Authenticate Payment challenge per field line, and
    | one line per method. A client chooses with Accept-Payment. When this value is
    | unset, the 402 offers only `default_method`, which is byte-identical to the
    | behaviour of a single rail.
    |
    | ORDER MATTERS. The first rail in the list leads the challenge set. A client
    | that sends no Accept-Payment answers the rail that leads, and some clients
    | answer it without a check that they can pay it. List the rail that your usual
    | caller can pay first.
    |
    | `default_method` chooses the rail for a route that names none. It does not
    | reorder this list.
    |
    */
    'accept' => env('MPP_ACCEPT') ? explode('|', (string) env('MPP_ACCEPT')) : null,

    /*
    |--------------------------------------------------------------------------
    | Discovery document
    |--------------------------------------------------------------------------
    |
    | The advisory OpenAPI document that MPP agents read to find payable endpoints.
    | The package generates it from the live router, so no one maintains it by hand
    | and it is never out of date.
    |
    | The discovery draft requires the document at GET /openapi.json, so the path is
    | not configurable. Disable the document if your application serves its own
    | OpenAPI document, and add the x-payment-info extension to that document
    | instead.
    |
    | PRICES ARE NEVER CONFIGURED HERE. The package reads everything under
    | x-payment-info from the live router, and from the request builders that mint
    | the 402. The document therefore cannot advertise a price that the gate does
    | not charge. What you configure here is the rest of the draft: who operates
    | the service, where it is, and what it does.
    |
    | Documentation for one OPERATION belongs with the route, and not here. That
    | includes a summary, an input or output schema, and a note on an offer. Write
    | it on the route, as `->discovery(summary: '…')`, or on the action, as
    | `#[DiscoveryInfo(summary: '…')]`. The `operations` key below serves a route
    | that you cannot annotate, because you did not define it.
    |
    */
    'discovery' => [
        'enabled' => (bool) env('MPP_DISCOVERY', true),

        // ── The OpenAPI `info` object ────────────────────────────────────────
        // `title` follows `app.name` when it is unset. `version` is the version
        // of YOUR API. It is not the version of the package or of the protocol.
        'title' => env('MPP_DISCOVERY_TITLE'),
        'version' => env('MPP_DISCOVERY_VERSION', '1.0.0'),
        'summary' => env('MPP_DISCOVERY_SUMMARY'),           // one line
        'description' => env('MPP_DISCOVERY_DESCRIPTION'),   // Markdown is allowed
        'terms_of_service' => env('MPP_DISCOVERY_TERMS'),    // a URL

        // Whom to contact about the API, and the licence that you offer it
        // under. Both are standard OpenAPI objects. The package omits an empty
        // key.
        'contact' => [
            'name' => env('MPP_DISCOVERY_CONTACT_NAME'),
            'url' => env('MPP_DISCOVERY_CONTACT_URL'),
            'email' => env('MPP_DISCOVERY_CONTACT_EMAIL'),
        ],
        'license' => [
            'name' => env('MPP_DISCOVERY_LICENSE'),          // e.g. 'MIT'
            'identifier' => env('MPP_DISCOVERY_LICENSE_ID'), // SPDX; or use `url`
        ],

        // ── Where the API is ─────────────────────────────────────────────────
        // The OpenAPI `servers` array. Every discovered path is relative to this
        // base URL. It follows `app.url` when it is unset, which is correct
        // unless another host serves the API, or a path prefix does. Pass plain
        // URLs, or the full OpenAPI form:
        //   ['https://api.example.com']
        //   [['url' => 'https://api.example.com', 'description' => 'Production']]
        'servers' => null,

        // ── The x-service-info extension of the draft ────────────────────────
        // These fields state what the service does, and where to read more about
        // it. Registries index both. A search over a registry matches against
        // them, so it is worth setting them.
        //
        // The categories are free-form. The draft suggests communication,
        // compute, data, developer-tools, media, search, social, storage and
        // travel. It also asks a registry to allow at most five.
        'categories' => array_filter(explode(',', (string) env('MPP_DISCOVERY_CATEGORIES', ''))),
        'docs' => [
            'homepage' => env('MPP_DISCOVERY_HOMEPAGE'),
            'api_reference' => env('MPP_DISCOVERY_API_REFERENCE'),
            'llms' => env('MPP_DISCOVERY_LLMS'),             // llms.txt, for agents
        ],

        // ── What the package reads from the application ──────────────────────
        // The action of a route already describes the operation. These two keys
        // decide how much of that description the package publishes.
        //
        // `form_requests` derives the input schema of an operation from the
        // rules of the FormRequest that its action type-hints. It is on by
        // default, because the rules are the schema. A schema that you keep
        // apart from the validator it describes becomes incorrect. Turn the key
        // off to publish only the permissive `{"type": "object"}` that the
        // package has always emitted.
        'form_requests' => (bool) env('MPP_DISCOVERY_FORM_REQUESTS', true),
        //
        // `docblocks` turns the docblock of the action into the summary and the
        // description of the operation. It is OFF by default, and deliberately.
        // An author writes a docblock for colleagues. It can state things that
        // the author would not publish to an unauthenticated endpoint that
        // registries crawl. Read your docblocks, and then turn the key on.
        'docblocks' => (bool) env('MPP_DISCOVERY_DOCBLOCKS', false),

        // ── Response headers ─────────────────────────────────────────────────
        // The draft recommends a cache of five minutes, for a service whose
        // capabilities change rarely. It also recommends CORS, for a browser
        // client that reads the document across origins. Set either key to null
        // to omit that header.
        'cache_control' => env('MPP_DISCOVERY_CACHE_CONTROL', 'public, max-age=300'),
        'allow_origin' => env('MPP_DISCOVERY_ALLOW_ORIGIN', '*'),

        // ── Documentation for a route that you cannot annotate ───────────────
        // Key each entry by route name, which is the preferred form because it
        // survives a change of URL. You can also key an entry by "GET /uri" or
        // by "/uri". The value takes the same fields as #[DiscoveryInfo].
        //
        // A route that states the same field nearer to itself wins. This block
        // fills the fields that the route does not state.
        //
        //   'reports.show' => [
        //       'summary' => 'Fetch a report',
        //       'priceNote' => 'Per report, whatever its length.',
        //       'response' => ['type' => 'object', 'properties' => [
        //           'id' => ['type' => 'string'],
        //       ]],
        //   ],
        //
        'operations' => [],

        // ── Free routes ──────────────────────────────────────────────────────
        // A paid API usually has free parts, such as a redirect, a status
        // endpoint, or the free tier of a paid endpoint. An agent that plans a
        // call needs to know about them, or it pays for a request to find them.
        //
        // The document lists only payment-gated routes by default. Name the free
        // routes here to list them too. The package documents them in the same
        // way as any other route, with a summary and schemas. They carry no
        // x-payment-info and no 402.
        //
        // The package matches the patterns against the same keys as
        // `operations`, which are the route name, "GET /uri" and "/uri". The
        // patterns accept `*` wildcards:
        //
        //   'include' => ['links.redirect', 'GET /health', 'api/public/*'],
        //
        // The list is EMPTY BY DEFAULT, and it is worth keeping the patterns
        // specific. A broad pattern publishes your route table on an
        // unauthenticated endpoint that registries crawl. That is a decision
        // about disclosure, and not a convenience. The discovery document never
        // lists itself.
        'include' => [],

        // ── The last word ────────────────────────────────────────────────────
        // OpenAPI is larger than the part of it that the payment drafts use.
        // Each entry here is a [Class::class, 'method'] pair. The container
        // resolves it, so the list survives `config:cache`. The pair receives
        // the finished document as an array, and returns it. Use a stage to add
        // `components`, a security scheme, free routes, or anything else.
        //
        // The stages run in order. The package logs a stage that throws, or that
        // returns a value other than an array, and then skips it. A
        // post-processor that fails must not stop discovery.
        'pipeline' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global price defaults
    |--------------------------------------------------------------------------
    |
    | The fallbacks for a route that states no price of its own.
    |
    | The middleware arguments of a route, and its #[RequiresPayment] attribute,
    | always win. These values fill only what the route omits. You can therefore
    | set a house price once, and write `mpp:scope=clip`, or a bare attribute,
    | instead of the amount on every route.
    |
    | Leave `amount` null to require an explicit price on each route. That is the
    | default, and nothing changes until you set a value. The defaults for the
    | method and the network are in `default_method` and `methods.*` above.
    |
    */
    'defaults' => [
        'amount' => env('MPP_DEFAULT_AMOUNT'),                  // for example '0.50'. Null means no global price.
        'currency' => env('MPP_DEFAULT_CURRENCY', 'USD'),
        'grants' => (int) env('MPP_DEFAULT_GRANTS', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Settlement methods (rails)
    |--------------------------------------------------------------------------
    |
    | Each method maps to a Verifier implementation and its configuration.
    |
    | The protocol layer does not depend on the rail. Every rail shares the one MPP
    | wire format, and settlement sits behind the Verifier interface. You can
    | therefore add a rail without a change to the protocol layer.
    |
    | TO ADD A RAIL, follow up to three steps:
    |   1. Implement Square1\Mpp\Settlement\Verifier. It verifies a settlement
    |      PROOF against the Challenge, and never trusts the word of the client.
    |      Some rails settle through a transaction that already exists outside your
    |      application, and not through a synchronous API call that you start. For
    |      such a rail, also implement Square1\Mpp\Settlement\SettlementChecker,
    |      and follow the matching logic of TempoVerifier.
    |   2. The default fiat shape of a 402 `request` payload is an amount in minor
    |      units, a currency, and methodDetails. If the payload of your rail has
    |      another shape, implement
    |      Square1\Mpp\Protocol\Requests\RailRequestBuilder, and set it as
    |      `request_builder` in the method block. Omit this step only for a fiat
    |      rail that has the shape of Stripe. For any other rail, the fiat builder
    |      mints the wrong request shape.
    |   3. Add a `methods.<name>` block here, with at least a `verifier`. Add the
    |      `request_builder` from step 2 if you wrote one. Use the rail on a route
    |      with `method=<name>`, or make it your house rail with `default_method`.
    |
    | VALIDATION: the gate checks the config of a rail the first time that a route
    | offers it, and uses the verifier as the key.
    |
    | A rail that the package ships throws InvalidConfigurationException when it
    | lacks a value that the 402 cannot be minted or paid without. Those values are
    | the recipient, the token and the chain_id of Tempo. The mistake therefore
    | appears at once.
    |
    | A value that is only recommended logs a warning once instead. Those values
    | are the secret_key and network_id of Stripe, and the rpc_url of Tempo. You
    | can therefore emit the 402 now, and settle once you have configured the rail.
    |
    | The gate skips a custom verifier, which validates its own config.
    |
    */
    'methods' => [
        'stripe' => [
            'verifier' => StripeVerifier::class,
            'secret_key' => env('STRIPE_SECRET_KEY'),   // sk_test_... or sk_live_... The gate needs it to SETTLE. It warns when the key is unset, and the 402 still mints.
            'network_id' => env('STRIPE_NETWORK_ID'),    // profile_... The 402 advertises it, so that a wallet can restrict an SPT to you. REQUIRED: the gate refuses to mint a stripe 402 without it.
            'api_version' => env('STRIPE_API_VERSION', '2026-05-27.preview'),
            'payment_method_types' => ['card'],

            // Optional. Maps an incoming request to a Stripe Customer id on YOUR
            // account, which is the seller account. Stripe attaches the customer
            // to the PaymentIntent, so that it groups the charges by payer
            // instead of recording them as guest charges.
            //
            // Use a [Class::class, 'method'] pair, which the container resolves.
            // The pair receives the Request, and returns a `cus_...` id or null.
            // Do NOT use a closure, which would break
            // `php artisan config:cache`.
            'customer_resolver' => null,
        ],

        // The second rail is Tempo, an on-chain stablecoin. A stock
        // `npx mppx <url>` agent can pay it. The rail is pure PHP. It needs no
        // Node sidecar, and the server holds no signing key. The client signs a
        // complete pathUSD transfer and pays its own gas. The package verifies
        // the signed transaction against the challenge, broadcasts it with
        // eth_sendRawTransaction, and confirms that the network mined it.
        //
        // Offer the rail alone, with `method=tempo` or `default_method`. You can
        // also offer it with stripe, through `accept` above or
        // `methods=stripe|tempo`.
        'tempo' => [
            'verifier' => TempoVerifier::class,

            // The three network values below, which are rpc_url, chain_id and
            // token, DEFAULT TO THE MODERATO TESTNET. A client can therefore pay
            // the rail with only a `recipient` set.
            //
            // FOR MAINNET, SET ALL THREE TOGETHER:
            //   rpc_url  = your Tempo mainnet JSON-RPC endpoint
            //   chain_id = 4217
            //   token    = 0x20C000000000000000000000b9537d11c60E8b50
            //
            // NEVER MIX THE NETWORKS. A mainnet token on the testnet chain, or a
            // testnet token on the mainnet chain, reverts with
            // `TIP20: Uninitialized`.

            // The Tempo JSON-RPC endpoint that the package broadcasts through.
            'rpc_url' => env('TEMPO_RPC_URL', 'https://rpc.moderato.tempo.xyz'),

            // The chain id that the signed transaction must target. The Moderato
            // testnet is 42431. The Tempo mainnet is 4217.
            'chain_id' => (int) env('TEMPO_CHAIN_ID', 42431),

            // The TIP-20 token that the transfer must use, which is pathUSD, and
            // its number of decimals. The package uses the decimals to convert the
            // decimal amount of the route to minor units. The default is pathUSD on
            // the Moderato testnet. On mainnet, pathUSD is
            // 0x20C000000000000000000000b9537d11c60E8b50.
            'token' => env('TEMPO_TOKEN', '0x20c0000000000000000000000000000000000000'),
            'decimals' => (int) env('TEMPO_DECIMALS', 6),

            // The address that the funds must settle to. No one can divert the
            // funds, because the package validates the transfer against this
            // address before it broadcasts. The address is required: the gate
            // refuses to mint a Tempo 402 without a recipient, a token and a
            // chain_id.
            'recipient' => env('TEMPO_RECIPIENT'),

            // Finality: the confirmations that the package requires before it
            // serves the resource.
            'confirmations' => (int) env('TEMPO_MIN_CONFIRMATIONS', 1),

            // Receipt polling: how long the package waits for the network to mine
            // the transaction that it broadcast.
            'poll_attempts' => (int) env('TEMPO_POLL_ATTEMPTS', 40),
            'poll_delay_ms' => (int) env('TEMPO_POLL_DELAY_MS', 500),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metered session storage
    |--------------------------------------------------------------------------
    |
    | One payment can grant N accesses. The server tracks a prepaid "session",
    | which is a credit balance, and decrements it atomically on each request.
    |
    | The storage follows the preferences of your application by default:
    |
    |   driver = 'cache'    -> your default cache store. When the cache of your
    |                          application is Redis, the sessions live in Redis
    |                          automatically. Leave `cache_store` null to follow
    |                          the default of the application, or name a store
    |                          from config/cache.php.
    |   driver = 'database' -> a table of its own, on your default database
    |                          connection. Publish the migration first. Leave
    |                          `connection` null to follow the default of the
    |                          application.
    |
    | Both drivers decrement atomically, and neither oversells under concurrency.
    |
    */
    'sessions' => [
        'driver' => env('MPP_SESSION_DRIVER', 'cache'),
        'cache_store' => env('MPP_SESSION_CACHE_STORE'),     // null = the default cache store of the app
        'connection' => env('MPP_SESSION_DB_CONNECTION'),    // null = the default db connection of the app
        'table' => 'mpp_sessions',
        'prefix' => 'mpp:session:',
    ],

    /*
    |--------------------------------------------------------------------------
    | #[RequiresPayment] attribute enforcement
    |--------------------------------------------------------------------------
    |
    | When you enable this, the package registers a middleware on the named route
    | groups. That middleware enforces payment on any controller action that
    | carries the #[RequiresPayment] attribute, and you wire no middleware for each
    | route. The package reads the attribute at request time, so route caching
    | still works.
    |
    | The feature is opt-in. With it disabled, you can still apply payment
    | explicitly:
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
    | Optional named pricing presets. A route refers to one by its scope key, as in
    | ->middleware('mpp:report.basic').
    |
    | An entry can also carry its own `preconditions` and `pricing` lists. Each list
    | is an array, or a pipe-separated string. The option of a route overrides the
    | list of the entry.
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
    | Named resolvers that set the price per REQUEST, and not per route. One
    | endpoint can therefore charge $2 to one caller and $5 to another.
    |
    | Each resolver is a [Class::class, 'method'] pair, which the container
    | resolves, so the list survives `config:cache`. The package calls the pair with
    | the Request and the resolved PaymentSpec. The pair returns an array of
    | overrides, or null to keep the static price of the route:
    |
    |     return ['amount' => '2.00'];                 // cheaper for this caller
    |     return ['amount' => '2.00', 'grants' => 20, 'scope' => 'report.pro'];
    |     return ['free' => true];                     // waive the charge
    |     return null;                                 // leave the price alone
    |
    | A resolver can override amount, currency, grants, scope and free. Any other
    | key throws, and so does an amount that is zero, negative or not numeric. To
    | waive a charge, a resolver must state `free => true`. A resolver that computes
    | an amount incorrectly therefore fails, and does not give the resource away. A
    | resolver cannot change the rail, which is the method and methods keys.
    |
    | A `global` resolver applies to every gated route. A route adds its own with
    | `pricing=` on the middleware, as in `mpp:5.00,USD,pricing=tiered`, or with
    | `pricing: [...]` on the attribute. The global resolvers run first, then the
    | resolvers of the route. Both run in order, without duplicates, and each one
    | sees the result of the one before it. An unknown name throws, so a typo cannot
    | fall back to the static price without a message.
    |
    | Something must supply a price before the gate: the route, the global default
    | above, or a resolver. Declare an amount on the route when you have a real list
    | price. That price is what an unrecognised caller pays, and it is the fallback
    | when you disable a resolver. Omit the amount, as in
    | `mpp:scope=report,pricing=tiered`, when you have no list price to state and
    | the resolvers own the price. If every resolver then declines, the request
    | throws, and the package does not serve it.
    |
    | Note that `null` means "no opinion", and NOT "no charge". To waive a charge is
    | `free => true`.
    |
    | The price that a buyer pays is the price in the signed 402. Settlement
    | verifies against the stored challenge, and never against a spec that the
    | package resolves again. A resolver whose answer changes between the 402 and
    | the paid retry therefore cannot change the price that you quoted to that
    | buyer.
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
    | Named checks that run BEFORE the package mints a 402 or settles a payment. The
    | package therefore rejects a request that it can never fulfil, such as a
    | request for a missing resource or a request from a blocked user, without a
    | charge.
    |
    | Each check is a [Class::class, 'method'] pair, which the container resolves,
    | so the list survives `config:cache`. The package calls the pair with the
    | Request and the resolved PaymentSpec. The pair returns a Response to reject
    | the request, for example a 404, or null to continue.
    |
    | A `global` check runs on every gated route. A route adds its own checks, which
    | the package adds to the global ones, with `preconditions=` on the middleware,
    | as in `mpp:1.00,USD,preconditions=postexists`, or with `preconditions: [...]`
    | on the attribute. The global checks run first, then the checks of the route.
    | Both run in order, without duplicates. The first Response wins. An unknown
    | name throws, so a typo cannot skip a check without a message.
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
