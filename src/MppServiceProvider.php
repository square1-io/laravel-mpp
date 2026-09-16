<?php

namespace Square1\Mpp;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Square1\Mpp\Discovery\DiscoveryDocument;
use Square1\Mpp\Http\Middleware\EnforceHttps;
use Square1\Mpp\Http\Middleware\EnforcePaymentAttributes;
use Square1\Mpp\Http\Middleware\RequirePayment;
use Square1\Mpp\Metering\SessionStore;
use Square1\Mpp\Metering\Stores\CacheSessionStore;
use Square1\Mpp\Metering\Stores\DatabaseSessionStore;
use Square1\Mpp\Payment\MethodConfigValidator;
use Square1\Mpp\Payment\PaymentGate;
use Square1\Mpp\Payment\PaymentPipeline;
use Square1\Mpp\Payment\PreconditionRunner;
use Square1\Mpp\Payment\PriceResolver;
use Square1\Mpp\Payment\SpecResolver;
use Square1\Mpp\Protocol\ChallengeBinding;
use Square1\Mpp\Protocol\ChallengeFactory;
use Square1\Mpp\Protocol\ChallengeStore;
use Square1\Mpp\Protocol\CredentialParser;
use Square1\Mpp\Protocol\SettlementLedger;
use Square1\Mpp\Settlement\SettlementChecker;
use Square1\Mpp\Settlement\StripeVerifier;
use Square1\Mpp\Settlement\Tempo\HttpRpcClient;
use Square1\Mpp\Settlement\Tempo\RpcClient;
use Square1\Mpp\Settlement\TempoRpcSettlementChecker;
use Square1\Mpp\Settlement\TempoVerifier;
use Square1\Mpp\Settlement\VerifierFactory;
use Square1\Mpp\Support\ChallengeSecret;

class MppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mpp.php', 'mpp');

        // Registered here rather than in boot(): every provider's register()
        // runs before any boot(), and the application's route files are loaded
        // from a boot(). A macro registered later would not exist yet for the
        // routes that want to call it.
        $this->registerRouteMacros();

        $this->app->singleton(ChallengeBinding::class, fn () => new ChallengeBinding(
            secret: ChallengeSecret::resolve(config('mpp.secret'), config('app.key')),
        ));

        $this->app->singleton(ChallengeFactory::class, fn ($app) => new ChallengeFactory(
            binding: $app->make(ChallengeBinding::class),
            ttl: (int) config('mpp.challenge_ttl', 300),
        ));

        // Protocol state (challenges, replay ledger) lives on `mpp.cache_store`;
        // null follows the app's default store. Production multi-node setups must
        // name a shared atomic store — see the config comment.
        $this->app->singleton(ChallengeStore::class, fn ($app) => new ChallengeStore(
            cache: $app['cache']->store(config('mpp.cache_store')),
            ttl: (int) config('mpp.challenge_ttl', 300),
        ));

        $this->app->singleton(SettlementLedger::class, fn ($app) => new SettlementLedger(
            cache: $app['cache']->store(config('mpp.cache_store')),
            ttl: (int) config('mpp.settlement_replay_ttl', 300),
        ));

        $this->app->bind(StripeVerifier::class, fn ($app) => new StripeVerifier(
            secretKey: (string) config('mpp.methods.stripe.secret_key'),
            apiVersion: (string) config('mpp.methods.stripe.api_version', '2026-05-27.preview'),
            networkId: config('mpp.methods.stripe.network_id'),
        ));

        $this->app->bind(VerifierFactory::class, fn ($app) => new VerifierFactory($app));

        // Singleton so the once-per-process "recommended config missing" warning
        // is logged once, not on every request.
        $this->app->singleton(MethodConfigValidator::class, fn () => new MethodConfigValidator);

        // Singleton for the same reason: the "metered scope priced per request"
        // warning is logged once per scope per process, not per request.
        $this->app->singleton(PriceResolver::class, fn () => new PriceResolver);

        $this->app->singleton(PreconditionRunner::class, fn () => new PreconditionRunner);

        $this->app->singleton(SessionStore::class, fn ($app) => $this->makeSessionStore($app));

        // ── Tempo rail (pure-PHP on-chain settlement) ────────────────────────
        // The JSON-RPC client the on-chain checker broadcasts through. Holds no
        // key and signs nothing — it relays the client-signed transaction and
        // reads its receipt. Rebind in tests to a fake.
        $this->app->bind(RpcClient::class, fn ($app) => new HttpRpcClient(
            http: $app->make(Factory::class),
            rpcUrl: (string) (config('mpp.methods.tempo.rpc_url') ?? config('mpp.methods.tempo.rpc') ?? ''),
        ));

        // The live, pure-PHP Tempo settlement checker: broadcast + confirm.
        $this->app->bind(SettlementChecker::class, fn ($app) => new TempoRpcSettlementChecker(
            rpc: $app->make(RpcClient::class),
        ));

        // Resolve TempoVerifier with its checker + method config injected; the
        // VerifierFactory makes it by class name from `mpp.methods.tempo.verifier`.
        $this->app->bind(TempoVerifier::class, fn ($app) => new TempoVerifier(
            checker: $app->make(SettlementChecker::class),
            methodConfig: (array) config('mpp.methods.tempo', []),
        ));

        $this->app->singleton(PaymentGate::class, fn ($app) => new PaymentGate(
            factory: $app->make(ChallengeFactory::class),
            binding: $app->make(ChallengeBinding::class),
            parser: $app->make(CredentialParser::class),
            challenges: $app->make(ChallengeStore::class),
            verifiers: $app->make(VerifierFactory::class),
            sessions: $app->make(SessionStore::class),
            cache: $app->make(CacheFactory::class),
            configValidator: $app->make(MethodConfigValidator::class),
            ledger: $app->make(SettlementLedger::class),
            sessionTtl: (int) config('mpp.session_ttl', 3600),
            settleLockTtl: (int) config('mpp.settle_lock_ttl', 300),
            replayMaxBytes: (int) config('mpp.replay_max_bytes', 262144),
        ));

        // The one path from a guarded route to the gate, shared by both
        // middlewares so neither route style can skip a step the other runs.
        $this->app->singleton(PaymentPipeline::class, fn ($app) => new PaymentPipeline(
            specs: $app->make(SpecResolver::class),
            pricing: $app->make(PriceResolver::class),
            preconditions: $app->make(PreconditionRunner::class),
            gate: $app->make(PaymentGate::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/mpp.php' => config_path('mpp.php'),
        ], 'mpp-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_mpp_sessions_table.php.stub' => $this->migrationPath('create_mpp_sessions_table'),
        ], 'mpp-migrations');

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('mpp', RequirePayment::class);

        // MPP discovery: an advisory OpenAPI document generated from the live
        // router, so it can never drift from the routes' actual pricing. The
        // discovery draft requires it at GET /openapi.json specifically, so the
        // path is fixed; an app serving its own OpenAPI doc disables this and
        // merges the x-payment-info extension itself.
        if (config('mpp.discovery.enabled', true)) {
            $router->get('/openapi.json', fn () => $this->discoveryResponse())
                ->name('mpp.discovery')
                ->middleware(EnforceHttps::class)
                // The document does not list itself. Said here, with the route,
                // rather than as a name the generator remembers to skip:
                // `hidden` is the mechanism that already exists for exactly
                // this, and `x-service-info.docs.apiReference` is where a
                // document points at where it lives.
                ->mppDiscovery(hidden: true);
        }

        // Opt-in: auto-enforce #[RequiresPayment] on the configured route groups.
        if (config('mpp.attributes.enabled')) {
            foreach ((array) config('mpp.attributes.middleware_groups', ['web', 'api']) as $group) {
                $router->pushMiddlewareToGroup($group, EnforcePaymentAttributes::class);
            }
        }
    }

    /**
     * The discovery document and the two response headers the draft asks for.
     *
     * A registry re-crawls each service it lists, so the draft recommends a
     * `Cache-Control` (5 minutes is its suggestion) and, for browser-based
     * clients reading the document cross-origin, CORS. Both are advisory
     * metadata on a public document, so both default to on; either is turned
     * off by setting its config key to null.
     */
    private function discoveryResponse(): JsonResponse
    {
        $response = response()->json($this->app->make(DiscoveryDocument::class)->toArray());

        $cacheControl = config('mpp.discovery.cache_control', 'public, max-age=300');
        $origin = config('mpp.discovery.allow_origin', '*');

        if (is_string($cacheControl) && $cacheControl !== '') {
            $response->headers->set('Cache-Control', $cacheControl);
        }

        if (is_string($origin) && $origin !== '') {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        return $response;
    }

    /**
     * `->discovery(...)` on a route: the route-file half of `#[DiscoveryInfo]`,
     * for the closures and route-file definitions that have no action class to
     * annotate.
     *
     *   Route::get('/clip', ClipController::class)
     *       ->middleware('mpp:0.50,USD')
     *       ->discovery(summary: 'Clip a video', priceNote: 'Per clip.');
     *
     * The arguments are `DiscoveryInfo`'s, and an array of them can be spread
     * into the call — `->discovery(...$stated)`.
     *
     * The short name is the one to use, and it is left alone if the application
     * already defines a `discovery()` macro of its own: silently replacing an
     * app's macro would break the app to document it. `mppDiscovery()` is
     * always registered, so a collision has a way out.
     */
    private function registerRouteMacros(): void
    {
        $macro = function (
            ?string $summary = null,
            ?string $description = null,
            string|array|null $priceNote = null,
            array $tags = [],
            ?string $operationId = null,
            string|array|null $request = null,
            string|array|null $response = null,
            array $parameters = [],
            array $query = [],
            ?bool $deprecated = null,
            ?bool $hidden = null,
        ) {
            /** @var Route $this */
            // Only what was actually passed is stored: an argument left at its
            // default said nothing, and something the route said nothing about
            // must not out-rank what the action or config did say.
            $this->action['mpp_discovery'] = array_filter(compact(
                'summary', 'description', 'priceNote', 'tags', 'operationId',
                'request', 'response', 'parameters', 'query', 'deprecated', 'hidden',
            ), fn (mixed $value) => $value !== null && $value !== []);

            return $this;
        };

        if (! Route::hasMacro('discovery')) {
            Route::macro('discovery', $macro);
        }

        Route::macro('mppDiscovery', $macro);
    }

    /**
     * Build the configured session store, inheriting the app's cache / database
     * preferences unless explicitly overridden.
     */
    private function makeSessionStore($app): SessionStore
    {
        $config = config('mpp.sessions', []);
        $ttl = (int) config('mpp.session_ttl', 3600);

        if (($config['driver'] ?? 'cache') === 'database') {
            return new DatabaseSessionStore(
                connection: $app['db']->connection($config['connection'] ?? null),
                table: $config['table'] ?? 'mpp_sessions',
                defaultTtl: $ttl,
            );
        }

        return new CacheSessionStore(
            cache: $app['cache']->store($config['cache_store'] ?? null),
            defaultTtl: $ttl,
            prefix: $config['prefix'] ?? 'mpp:session:',
        );
    }

    private function migrationPath(string $name): string
    {
        return database_path('migrations/'.date('Y_m_d_His').'_'.$name.'.php');
    }
}
