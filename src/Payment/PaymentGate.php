<?php

namespace Square1\Mpp\Payment;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Square1\Mpp\Metering\Session;
use Square1\Mpp\Metering\SessionHeader;
use Square1\Mpp\Metering\SessionStore;
use Square1\Mpp\Protocol\ChallengeFactory;
use Square1\Mpp\Protocol\ChallengeStore;
use Square1\Mpp\Protocol\Credential;
use Square1\Mpp\Protocol\CredentialParser;
use Square1\Mpp\Protocol\Receipt;
use Square1\Mpp\Settlement\VerifierFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MPP decision engine, shared by the middleware and the attribute enforcer.
 *
 *   request
 *     ├─ Authorization: Payment session="…"            -> spend a credit, serve (no charge)
 *     ├─ Authorization: Payment proof/spt="…"+sig      -> verify the offered accept for the
 *     │                                                   credential's method, settle, serve + receipt
 *     └─ (no credential)                               -> mint + sign a 402 challenge
 *
 * A challenge may offer several settlement methods. On a paid retry the gate
 * routes by the credential's `method`: it finds THAT method's offered accept,
 * verifies THAT accept's per-method signature, and only then hands off to the
 * method's Verifier. A signature minted for one method does not validate
 * another's accept, so the signature stays load-bearing per method.
 */
class PaymentGate
{
    public function __construct(
        private readonly ChallengeFactory $factory,
        private readonly CredentialParser $parser,
        private readonly ChallengeStore $challenges,
        private readonly VerifierFactory $verifiers,
        private readonly SessionStore $sessions,
        private readonly CacheFactory $cache,
        private readonly TempoGate $tempo,
        private readonly MethodConfigValidator $configValidator,
        private readonly int $sessionTtl = 3600,
    ) {}

    public function process(Request $request, Closure $next, PaymentSpec $spec): Response
    {
        // Fail fast on a misconfigured rail before anything is minted, so a
        // missing required setting surfaces on the first request rather than as
        // a confusing settlement failure later (recommended-but-absent settings
        // only warn — see MethodConfigValidator).
        $this->configValidator->validate($spec);

        // The tempo rail speaks the mppx wire dialect (a base64url request blob +
        // plain problem+json, no signed accepts[]), which differs from this
        // native dialect. A single 402 can only offer one dialect, so a route
        // whose resolved primary method is tempo is handled entirely by the
        // tempo gate; stripe (and any other native rail) stays on this path.
        if ($spec->method === 'tempo') {
            return $this->tempo->process($request, $next, $spec);
        }

        $credential = $this->parser->parse($request->header('Authorization'));

        if ($credential?->isSession()) {
            return $this->spendSession($request, $next, $spec, $credential);
        }

        if ($credential?->isSettlementProof()) {
            return $this->settle($request, $next, $spec, $credential);
        }

        return $this->challenge($spec);
    }

    private function spendSession(Request $request, Closure $next, PaymentSpec $spec, Credential $credential): Response
    {
        // Scope is enforced inside consume(): a session for one scope cannot be
        // spent on another endpoint.
        $session = $this->sessions->consume((string) $credential->session, $spec->scope);

        if (! $session) {
            return $this->challenge($spec, 'The session is invalid, out of scope, exhausted or expired. A new challenge has been issued.');
        }

        return $this->serve($request, $next, session: $session);
    }

    private function settle(Request $request, Closure $next, PaymentSpec $spec, Credential $credential): Response
    {
        $challenge = $credential->challengeId ? $this->challenges->find($credential->challengeId) : null;

        if (! $challenge) {
            return $this->challenge($spec, 'The challenge is unknown, already used or expired. A new challenge has been issued.');
        }

        if ($challenge->isExpired()) {
            $this->challenges->burn($challenge->id);

            return $this->challenge($spec, 'The challenge has expired. A new challenge has been issued.');
        }

        // Route by the credential's method: it must be one the challenge offered.
        // (Default to the challenge's primary method for back-compat with clients
        // that omit `method`.)
        $method = ($credential->method !== '') ? $credential->method : $challenge->method;

        if ($challenge->offerFor($method) === null) {
            return $this->challenge($spec, "Payment method '{$method}' was not offered for this challenge.");
        }

        // The challenge binding is load-bearing PER METHOD: settlement requires a
        // valid HMAC signature over THAT method's accept entry, so a challenge id
        // alone is not enough, a signature minted for another offered method will
        // not validate this one, and a challenge signed under a rotated secret
        // will not settle.
        if ($credential->signature === null || ! $this->factory->verifyOffer($challenge, $method, $credential->signature)) {
            return $this->challenge($spec, 'The challenge signature is missing or invalid.');
        }

        // Serialise concurrent retries of the same challenge so a single payment
        // settles exactly once (the Stripe idempotency key is a second defence).
        $lock = $this->cache->store()->lock('mpp:settle:'.$challenge->id, 10);

        try {
            $lock->block(5);
        } catch (LockTimeoutException $e) {
            return $this->challenge($spec, 'Could not acquire a settlement lock; please retry.');
        }

        try {
            if (! $this->challenges->exists($challenge->id)) {
                return $this->challenge($spec, 'The challenge has already been used. A new challenge has been issued.');
            }

            $result = $this->verifiers->make($method)->verify($credential, $challenge, [
                'customer' => $this->resolvePayerCustomer($request, $method),
            ]);

            if (! $result->succeeded) {
                return $this->challenge($spec, 'Payment was not settled: '.$result->failureReason);
            }

            $receipt = Receipt::fromSettlement($challenge, $result, $method);

            // Metered endpoint: one payment grants N accesses — issue a prepaid
            // session and spend the first credit for this request.
            $session = null;
            if ($challenge->isMetered()) {
                $created = $this->sessions->create(
                    scope: $challenge->scope,
                    remaining: $challenge->grants,
                    ttl: $this->sessionTtl,
                    settlementRef: $result->settlementRef,
                );
                $session = $this->sessions->consume($created->id, $challenge->scope) ?? $created;
            }

            // Single-use: burn the challenge so the same payment cannot be replayed.
            $this->challenges->burn($challenge->id);
        } finally {
            optional($lock)->release();
        }

        return $this->serve($request, $next, receipt: $receipt, session: $session);
    }

    private function serve(Request $request, Closure $next, ?Receipt $receipt = null, ?Session $session = null): Response
    {
        $response = $next($request);

        if ($receipt !== null) {
            $response->headers->set('Payment-Receipt', $receipt->header());
        }

        if ($session !== null) {
            $response->headers->set('Payment-Session', SessionHeader::for($session));
        }

        return $response;
    }

    private function challenge(PaymentSpec $spec, ?string $detail = null): Response
    {
        $challenge = $this->factory->mint($spec->toChallengeSpec());
        $this->challenges->put($challenge);

        $document = $this->factory->problemDocument($challenge);

        if ($detail !== null) {
            $document['detail'] = $detail;
        }

        return response()
            ->json($document, Response::HTTP_PAYMENT_REQUIRED)
            ->header('WWW-Authenticate', $this->factory->wwwAuthenticate($challenge))
            ->header('Content-Type', 'application/problem+json')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Resolve an optional seller-account customer for this request via the
     * method's configured `customer_resolver`. Never blocks settlement.
     */
    private function resolvePayerCustomer(Request $request, string $method): ?string
    {
        $resolver = config("mpp.methods.{$method}.customer_resolver");

        if ($resolver === null) {
            return null;
        }

        try {
            if (is_array($resolver) && count($resolver) === 2 && is_string($resolver[0])) {
                [$class, $callable] = $resolver;
                $customer = app($class)->{$callable}($request);
            } elseif (is_callable($resolver)) {
                $customer = $resolver($request);
            } else {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($customer) && $customer !== '' ? $customer : null;
    }
}
