<?php

namespace Square1\Mpp\Payment;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Square1\Mpp\Metering\Session;
use Square1\Mpp\Metering\SessionHeader;
use Square1\Mpp\Metering\SessionStore;
use Square1\Mpp\Protocol\CredentialParser;
use Square1\Mpp\Protocol\Tempo\MppxCodec;
use Square1\Mpp\Protocol\Tempo\ParsedTempoCredential;
use Square1\Mpp\Protocol\Tempo\TempoChallengeFactory;
use Square1\Mpp\Protocol\Tempo\TempoChallengeStore;
use Square1\Mpp\Settlement\TempoVerifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tempo (mppx-dialect) decision engine. The native {@see PaymentGate}
 * delegates here when a route's resolved primary method is `tempo`, because the
 * tempo wire encoding differs from the native dialect and a single 402 offers
 * exactly one dialect.
 *
 *   request
 *     ├─ Authorization: Payment session=…   (native session credential) -> spend a credit
 *     ├─ Authorization: Payment <b64url>    (mppx transaction credential) -> verify+settle, serve + receipt
 *     └─ (no credential)                    -> mint + persist + emit an mppx 402 challenge
 *
 * The challenge binding stays load-bearing: settlement requires the echoed
 * challenge id to be one we issued (store lookup) AND unexpired AND its stateless
 * HMAC to verify AND the signed transaction to pay the challenged amount/token/
 * recipient with a memo bound to that exact challenge id under our realm.
 */
final class TempoGate
{
    public function __construct(
        private readonly MppxCodec $codec,
        private readonly TempoChallengeFactory $factory,
        private readonly TempoChallengeStore $challenges,
        private readonly TempoVerifier $verifier,
        private readonly SessionStore $sessions,
        private readonly CacheFactory $cache,
        private readonly CredentialParser $parser,
        private readonly int $sessionTtl = 3600,
    ) {}

    public function process(Request $request, Closure $next, PaymentSpec $spec): Response
    {
        $authorization = $request->header('Authorization');

        // A prepaid session (issued by a prior tempo payment for a metered bundle)
        // reuses the native session credential form (`Payment session="…"`).
        $native = $this->parser->parse($authorization);
        if ($native?->isSession()) {
            return $this->spendSession($request, $next, $spec, (string) $native->session);
        }

        $credential = $this->codec->parseCredential($authorization);

        if ($credential !== null && $credential->signature !== '') {
            return $this->settle($request, $next, $spec, $credential);
        }

        return $this->challenge($spec, $this->realmFor($request));
    }

    private function spendSession(Request $request, Closure $next, PaymentSpec $spec, string $sessionId): Response
    {
        $session = $this->sessions->consume($sessionId, $spec->scope);

        if (! $session) {
            return $this->challenge($spec, $this->realmFor($request), 'The session is invalid, out of scope, exhausted or expired. A new challenge has been issued.');
        }

        return $this->serve($request, $next, session: $session);
    }

    private function settle(Request $request, Closure $next, PaymentSpec $spec, ParsedTempoCredential $credential): Response
    {
        $realm = $this->realmFor($request);

        if ($credential->challengeId === '') {
            return $this->challenge($spec, $realm, 'The credential did not echo a challenge id.');
        }

        $state = $this->challenges->find($credential->challengeId);

        if (! $state) {
            return $this->challenge($spec, $realm, 'The challenge is unknown, already used or expired. A new challenge has been issued.');
        }

        if ($state->isExpired()) {
            $this->challenges->burn($state->id);

            return $this->challenge($spec, $realm, 'The challenge has expired. A new challenge has been issued.');
        }

        // Defence-in-depth: the stateless HMAC over the issued binding fields must
        // still verify, so a challenge minted under a rotated secret cannot settle.
        if (! $this->factory->verifyId($state)) {
            return $this->challenge($spec, $realm, 'The challenge signature is invalid.');
        }

        // Serialise concurrent retries of the same challenge so a single payment
        // settles exactly once.
        $lock = $this->cache->store()->lock('mpp:tempo:settle:'.$state->id, 30);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return $this->challenge($spec, $realm, 'Could not acquire a settlement lock; please retry.');
        }

        try {
            if (! $this->challenges->exists($state->id)) {
                return $this->challenge($spec, $realm, 'The challenge has already been used. A new challenge has been issued.');
            }

            $result = $this->verifier->verifyTempo($credential, $state);

            if (! $result->succeeded) {
                return $this->challenge($spec, $realm, 'Payment was not settled: '.$result->failureReason);
            }

            $reference = (string) $result->settlementRef;

            // Metered: one payment grants N accesses — issue a prepaid session.
            $session = null;
            if ($state->isMetered()) {
                $created = $this->sessions->create(
                    scope: $state->scope,
                    remaining: $state->grants,
                    ttl: $this->sessionTtl,
                    settlementRef: $reference,
                );
                $session = $this->sessions->consume($created->id, $state->scope) ?? $created;
            }

            // Single-use: burn the challenge so the same payment cannot be replayed.
            $this->challenges->burn($state->id);
        } finally {
            optional($lock)->release();
        }

        return $this->serve($request, $next, receiptReference: $reference, session: $session);
    }

    private function serve(Request $request, Closure $next, ?string $receiptReference = null, ?Session $session = null): Response
    {
        $response = $next($request);

        if ($receiptReference !== null) {
            $response->headers->set('Payment-Receipt', $this->codec->receiptHeader($receiptReference));
        }

        if ($session !== null) {
            $response->headers->set('Payment-Session', SessionHeader::for($session));
        }

        return $response;
    }

    private function challenge(PaymentSpec $spec, string $realm, ?string $detail = null): Response
    {
        $methodConfig = (array) config('mpp.methods.tempo', []);
        $state = $this->factory->mint($spec, $methodConfig, $realm);
        $this->challenges->put($state);

        $document = $this->codec->problemDocument($state, $detail);

        return response()
            ->json($document, Response::HTTP_PAYMENT_REQUIRED)
            ->header('WWW-Authenticate', $this->codec->wwwAuthenticate($state))
            ->header('Content-Type', 'application/problem+json')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * The realm advertised in the 402 and bound into the attribution memo. Uses
     * the configured realm, else the request host (mppx uses the host by default;
     * the captured exchange used "localhost").
     */
    private function realmFor(Request $request): string
    {
        $configured = config('mpp.methods.tempo.realm');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $request->getHost() ?: 'localhost';
    }
}
