<?php

namespace Square1\Mpp;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Square1\Mpp\Http\Middleware\EnforcePaymentAttributes;
use Square1\Mpp\Http\Middleware\RequirePayment;
use Square1\Mpp\Metering\SessionStore;
use Square1\Mpp\Metering\Stores\CacheSessionStore;
use Square1\Mpp\Metering\Stores\DatabaseSessionStore;
use Square1\Mpp\Payment\MethodConfigValidator;
use Square1\Mpp\Payment\PaymentGate;
use Square1\Mpp\Payment\TempoGate;
use Square1\Mpp\Protocol\ChallengeFactory;
use Square1\Mpp\Protocol\ChallengeStore;
use Square1\Mpp\Protocol\CredentialParser;
use Square1\Mpp\Protocol\Tempo\MppxCodec;
use Square1\Mpp\Protocol\Tempo\TempoChallengeFactory;
use Square1\Mpp\Protocol\Tempo\TempoChallengeStore;
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

        $this->app->singleton(ChallengeFactory::class, fn ($app) => new ChallengeFactory(
            secret: ChallengeSecret::resolve(config('mpp.secret'), config('app.key')),
            ttl: (int) config('mpp.challenge_ttl', 300),
        ));

        $this->app->singleton(ChallengeStore::class, fn ($app) => new ChallengeStore(
            cache: $app['cache']->store(),
            ttl: (int) config('mpp.challenge_ttl', 300),
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

        $this->app->singleton(SessionStore::class, fn ($app) => $this->makeSessionStore($app));

        // ── Tempo rail (mppx dialect, pure-PHP on-chain settlement) ──────────
        $this->app->singleton(MppxCodec::class, fn () => new MppxCodec);

        $this->app->singleton(TempoChallengeFactory::class, fn ($app) => new TempoChallengeFactory(
            codec: $app->make(MppxCodec::class),
            secret: ChallengeSecret::resolve(config('mpp.secret'), config('app.key')),
            ttl: (int) config('mpp.challenge_ttl', 300),
        ));

        $this->app->singleton(TempoChallengeStore::class, fn ($app) => new TempoChallengeStore(
            cache: $app['cache']->store(),
            ttl: (int) config('mpp.challenge_ttl', 300),
        ));

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

        // Resolve TempoVerifier with its checker + method config injected for
        // the mppx Tempo gate.
        $this->app->bind(TempoVerifier::class, fn ($app) => new TempoVerifier(
            checker: $app->make(SettlementChecker::class),
            methodConfig: (array) config('mpp.methods.tempo', []),
        ));

        $this->app->singleton(TempoGate::class, fn ($app) => new TempoGate(
            codec: $app->make(MppxCodec::class),
            factory: $app->make(TempoChallengeFactory::class),
            challenges: $app->make(TempoChallengeStore::class),
            verifier: $app->make(TempoVerifier::class),
            sessions: $app->make(SessionStore::class),
            cache: $app->make(CacheFactory::class),
            parser: $app->make(CredentialParser::class),
            sessionTtl: (int) config('mpp.session_ttl', 3600),
        ));

        $this->app->singleton(PaymentGate::class, fn ($app) => new PaymentGate(
            factory: $app->make(ChallengeFactory::class),
            parser: $app->make(CredentialParser::class),
            challenges: $app->make(ChallengeStore::class),
            verifiers: $app->make(VerifierFactory::class),
            sessions: $app->make(SessionStore::class),
            cache: $app->make(CacheFactory::class),
            tempo: $app->make(TempoGate::class),
            configValidator: $app->make(MethodConfigValidator::class),
            sessionTtl: (int) config('mpp.session_ttl', 3600),
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

        // Opt-in: auto-enforce #[RequiresPayment] on the configured route groups.
        if (config('mpp.attributes.enabled')) {
            foreach ((array) config('mpp.attributes.middleware_groups', ['web', 'api']) as $group) {
                $router->pushMiddlewareToGroup($group, EnforcePaymentAttributes::class);
            }
        }
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
