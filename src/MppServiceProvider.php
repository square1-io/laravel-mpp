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

        // The macros are registered here and not in boot(). The register()
        // method of every provider runs before any boot() method, and a boot()
        // method loads the route files of the application. A macro that the
        // package registered later would not exist when a route file calls
        // it.
        $this->registerRouteMacros();

        $this->app->singleton(ChallengeBinding::class, fn () => new ChallengeBinding(
            secret: ChallengeSecret::resolve(config('mpp.secret'), config('app.key')),
        ));

        $this->app->singleton(ChallengeFactory::class, fn ($app) => new ChallengeFactory(
            binding: $app->make(ChallengeBinding::class),
            ttl: (int) config('mpp.challenge_ttl', 300),
        ));

        // The protocol state, which is the challenges and the replay ledger,
        // uses `mpp.cache_store`. Null follows the default store of the
        // application. A production deployment on more than one node must name
        // a shared atomic store. See the config comment.
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

        // This is a singleton so that the package logs the "recommended config
        // missing" warning once per process, and not on every request.
        $this->app->singleton(MethodConfigValidator::class, fn () => new MethodConfigValidator);

        // This is a singleton for the same reason. The package logs the
        // "metered scope priced per request" warning once per scope per
        // process, and not on every request.
        $this->app->singleton(PriceResolver::class, fn () => new PriceResolver);

        $this->app->singleton(PreconditionRunner::class, fn () => new PreconditionRunner);

        $this->app->singleton(SessionStore::class, fn ($app) => $this->makeSessionStore($app));

        // ── Tempo rail (pure-PHP on-chain settlement) ────────────────────────
        // The on-chain checker broadcasts through this JSON-RPC client. The
        // client holds no key and signs nothing. It relays the transaction that
        // the client signed, and it reads the receipt. Bind it to a fake in
        // tests.
        $this->app->bind(RpcClient::class, fn ($app) => new HttpRpcClient(
            http: $app->make(Factory::class),
            rpcUrl: (string) (config('mpp.methods.tempo.rpc_url') ?? config('mpp.methods.tempo.rpc') ?? ''),
        ));

        // The live Tempo settlement checker, in pure PHP. It broadcasts the
        // transaction and then confirms it.
        $this->app->bind(SettlementChecker::class, fn ($app) => new TempoRpcSettlementChecker(
            rpc: $app->make(RpcClient::class),
        ));

        // TempoVerifier resolves with its checker and its method config
        // injected. The VerifierFactory builds it by class name, from
        // `mpp.methods.tempo.verifier`.
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

        // This is the one path from a guarded route to the gate. Both
        // middlewares share it, so neither route style can omit a step that the
        // other style runs.
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

        // MPP discovery serves an advisory OpenAPI document. The package
        // generates it from the live router, so it always matches the real
        // price of each route. The discovery draft requires the document at GET
        // /openapi.json, so the path is not configurable. An application that
        // serves its own OpenAPI document disables this route and adds the
        // x-payment-info extension to its own document.
        if (config('mpp.discovery.enabled', true)) {
            $router->get('/openapi.json', fn () => $this->discoveryResponse())
                ->name('mpp.discovery')
                ->middleware(EnforceHttps::class)
                // The document does not list itself. This is stated here, with
                // the route, and not as a route name that the generator skips.
                // `hidden` is the mechanism that already exists for this
                // purpose. `x-service-info.docs.apiReference` is the field that
                // states where the document is.
                ->mppDiscovery(hidden: true);
        }

        // This is opt-in. It enforces #[RequiresPayment] automatically, on the
        // configured route groups.
        if (config('mpp.attributes.enabled')) {
            foreach ((array) config('mpp.attributes.middleware_groups', ['web', 'api']) as $group) {
                $router->pushMiddlewareToGroup($group, EnforcePaymentAttributes::class);
            }
        }
    }

    /**
     * Returns the discovery document and the two response headers that the
     * draft asks for.
     *
     * A registry crawls each service that it lists again at intervals. The
     * draft therefore recommends a `Cache-Control` header, and suggests five
     * minutes. It also recommends CORS headers, for a browser client that reads
     * the document across origins.
     *
     * Both headers are advisory metadata on a public document, so both are on
     * by default. To turn one off, set its config key to null.
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
     * Registers `->discovery(...)` on a route.
     *
     * This macro is the route-file equivalent of `#[DiscoveryInfo]`. It serves a
     * closure or a route-file definition that has no action class to annotate.
     *
     *   Route::get('/clip', ClipController::class)
     *       ->middleware('mpp:0.50,USD')
     *       ->discovery(summary: 'Clip a video', priceNote: 'Per clip.');
     *
     * The arguments are the arguments of `DiscoveryInfo`. You can also spread an
     * array of them into the call, as `->discovery(...$stated)`.
     *
     * Use the short name. The package does not register it when the application
     * already defines a `discovery()` macro. To replace the macro of an
     * application without a message would break that application. The package
     * always registers `mppDiscovery()`, so a name collision has a solution.
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
            // The macro stores only the arguments that the caller passed. An
            // argument that keeps its default states nothing. A field that the
            // route did not state must not outrank the same field in the action
            // or in the config.
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
     * Builds the configured session store.
     *
     * The store follows the cache and database preferences of the application,
     * unless the config overrides them.
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
