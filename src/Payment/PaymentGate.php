<?php

namespace Square1\Mpp\Payment;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Exceptions\MalformedCredentialException;
use Square1\Mpp\Http\Middleware\EnforceHttps;
use Square1\Mpp\Metering\Session;
use Square1\Mpp\Metering\SessionHeader;
use Square1\Mpp\Metering\SessionStore;
use Square1\Mpp\Protocol\AcceptPayment;
use Square1\Mpp\Protocol\ChallengeBinding;
use Square1\Mpp\Protocol\ChallengeFactory;
use Square1\Mpp\Protocol\ChallengeStore;
use Square1\Mpp\Protocol\Credential;
use Square1\Mpp\Protocol\CredentialParser;
use Square1\Mpp\Protocol\Receipt;
use Square1\Mpp\Protocol\SettlementLedger;
use Square1\Mpp\Protocol\SettlementRecord;
use Square1\Mpp\Settlement\VerifierFactory;
use Square1\Mpp\Support\Digest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 *
 * The spec arrives already priced and already past its preconditions — the
 * PaymentPipeline does that for every guarded route, whichever middleware got it
 * here — so this class only ever decides HOW a chargeable request pays. A spec a
 * price resolver waived never reaches it.
 */
class PaymentGate
{
    public function __construct(
        private readonly ChallengeFactory $factory,
        private readonly ChallengeBinding $binding,
        private readonly CredentialParser $parser,
        private readonly ChallengeStore $challenges,
        private readonly VerifierFactory $verifiers,
        private readonly SessionStore $sessions,
        private readonly CacheFactory $cache,
        private readonly MethodConfigValidator $configValidator,
        private readonly SettlementLedger $ledger,
        private readonly int $sessionTtl = 3600,
        private readonly int $settleLockTtl = 300,
        private readonly int $replayMaxBytes = 262144,
    ) {}

    public function process(Request $request, Closure $next, PaymentSpec $spec): Response
    {
        // The MPP spec forbids issuing a Payment challenge or accepting a
        // credential over unencrypted HTTP — the 402 and the credential carry
        // payment terms and proofs. The same guard protects the discovery route,
        // so no MPP surface serves over a transport the others refuse.
        EnforceHttps::assert($request);

        // The pipeline serves a waived request itself and never routes one here,
        // so this cannot fire today. It stays because "never sees a free spec" is
        // an assumption about a caller, and the last time this package assumed
        // something about its callers, a second entry path quietly broke it. If a
        // future caller does hand us one, charging it is the worst outcome.
        if ($spec->free) {
            throw new InvalidConfigurationException(
                "A waived (free) spec reached the payment gate for scope '{$spec->scope}'. "
                .'Free requests are served by the PaymentPipeline and must not be charged.'
            );
        }

        // Fail fast on a misconfigured rail before anything is minted, so a
        // missing required setting surfaces on the first request rather than as
        // a confusing settlement failure later (recommended-but-absent settings
        // only warn — see MethodConfigValidator).
        $this->configValidator->validate($spec);

        try {
            $credential = $this->parser->parse($request->header('Authorization'));
        } catch (MalformedCredentialException $e) {
            // A Payment credential was offered but could not be parsed. This is a
            // malformed request, distinct from an absent credential (which just
            // asks the price) — answer with the typed problem plus a fresh challenge.
            return $this->challenge($request, $spec, 'The Payment credential could not be parsed. A new challenge has been issued.', 'malformed-credential');
        }

        if ($credential?->isSession()) {
            return $this->spendSession($request, $next, $spec, $credential);
        }

        if ($credential?->isSettlementProof()) {
            return $this->settle($request, $next, $spec, $credential);
        }

        return $this->challenge($request, $spec);
    }

    private function spendSession(Request $request, Closure $next, PaymentSpec $spec, Credential $credential): Response
    {
        // Scope is enforced inside consume(): a session for one scope cannot be
        // spent on another endpoint.
        $session = $this->sessions->consume((string) $credential->session, $spec->scope);

        if (! $session) {
            return $this->challenge($request, $spec, 'The session is invalid, out of scope, exhausted or expired. A new challenge has been issued.', 'invalid-challenge');
        }

        return $this->serve($request, $next, session: $session);
    }

    private function settle(Request $request, Closure $next, PaymentSpec $spec, Credential $credential): Response
    {
        $challengeId = $credential->challengeId();
        $challenge = $challengeId ? $this->challenges->find($challengeId) : null;

        if (! $challenge) {
            // The challenge is gone — but if it settled and the client simply
            // never received the 200 (lost response), replay the original
            // receipt rather than issuing a fresh 402 it would pay again. Only
            // for the resource that was paid for: a settled receipt is not a
            // bearer token for the rest of the site, so a record from another
            // route is treated as no record at all (fresh 402, nothing served).
            if ($challengeId && ($replay = $this->replayIfSettled($challengeId, $credential, $request))) {
                return $replay;
            }

            return $this->challenge($request, $spec, 'The challenge is unknown, already used or expired. A new challenge has been issued.', 'invalid-challenge');
        }

        if ($challenge->isExpired()) {
            $this->challenges->burn($challenge->id);

            return $this->challenge($request, $spec, 'The challenge has expired. A new challenge has been issued.', 'payment-expired');
        }

        // The binding is load-bearing: the id IS the HMAC over the stored
        // challenge's seven slots, so recompute-and-compare proves the terms
        // the client is answering are the terms this server minted — under the
        // current secret. A challenge minted under a rotated secret fails here.
        if (! $this->binding->verify($challenge)) {
            return $this->challenge($request, $spec, 'The challenge binding is invalid.', 'invalid-challenge');
        }

        // A challenge authorises the resource it was minted for, and nothing
        // else. The binding above proves the terms are ours; it does not prove
        // they are THIS route's terms. The offered-methods check is the same rule
        // for the rail: a route that no longer offers tempo must not settle a
        // tempo challenge minted while it did.
        if ($challenge->scope() !== $spec->scope || ! in_array($challenge->method, $spec->offeredMethods, true)) {
            return $this->challenge($request, $spec, 'This challenge was not issued for this resource. A new challenge has been issued.', 'invalid-challenge');
        }

        // Route-identity binding. Scope is not a reliable request identity: an
        // application can share one scope across differently-priced routes, so a
        // 0.50 challenge could otherwise unlock a 5.00 route at the same scope.
        // The resource (HTTP method, path, and normalized query) is bound into `opaque` at
        // mint. Enforce it when present (a pre-deploy challenge simply lacks the
        // key, and every body/route is server-chosen, so lenient-when-absent is
        // safe).
        $resource = $challenge->opaque['resource'] ?? null;
        if ($resource !== null && $resource !== $this->resourceIdentity($request)) {
            return $this->challenge($request, $spec, 'This challenge was not issued for this resource. A new challenge has been issued.', 'invalid-challenge');
        }

        // Body-integrity binding (RFC 9530): the paid retry must carry the same
        // body the challenge was minted for. Enforced only when the stored
        // challenge advertises a digest (body-less requests and pre-deploy
        // challenges carry none), so a body cannot be swapped after the quote.
        if ($challenge->digestParam() !== '' && $challenge->digestParam() !== (Digest::forRequest($request) ?? '')) {
            return $this->challenge($request, $spec, 'The request body does not match the challenge. A new challenge has been issued.', 'invalid-challenge');
        }

        // The credential must echo the challenge it names unchanged; a present
        // but altered field (e.g. a swapped method) is a malformed credential.
        if (! $credential->echoes($challenge)) {
            return $this->challenge($request, $spec, 'The credential does not echo the challenge it names.', 'malformed-credential');
        }

        // The stored challenge is authoritative for the settlement method; the
        // credential's echoed copy is matched by id and never trusted for terms.
        $method = $challenge->method;

        // Serialise concurrent retries of the same challenge so a single payment
        // settles exactly once. The TTL (mpp.settle_lock_ttl) must outlive the
        // slowest verifier (a Tempo on-chain confirm can take poll_attempts ×
        // poll_delay_ms, tens of seconds) or the lock would lapse mid-settlement
        // and a second request could settle in parallel. The Stripe idempotency
        // key and the ledger below are the further defences.
        $lock = $this->cache->store(config('mpp.cache_store'))->lock('mpp:settle:'.$challenge->id, $this->settleLockTtl);

        try {
            $lock->block(5);
        } catch (LockTimeoutException $e) {
            // Another request holds the lock and is settling this same
            // challenge. Tell the client to wait and retry — NOT to pay again.
            return $this->settlementInProgress($challenge->id);
        }

        try {
            // If the challenge is gone, another request settled it while we
            // blocked on the lock: replay its receipt rather than charge again.
            // Checking exists() first keeps the common first-payment path to one
            // lookup — a settled id is always burned, so a live challenge can
            // never have a ledger record, making a ledger read here a guaranteed
            // miss.
            if (! $this->challenges->exists($challenge->id)) {
                if ($replay = $this->replayIfSettled($challenge->id, $credential, $request)) {
                    return $replay;
                }

                return $this->challenge($request, $spec, 'The challenge has already been used. A new challenge has been issued.', 'invalid-challenge');
            }

            // Compute the idempotency fingerprint BEFORE charging or running the
            // action, so a credential we cannot fingerprint is rejected up front
            // rather than after settlement (when the ledger write would fail and
            // leave the challenge unburned and re-settleable). The parser already
            // rejects non-representable numbers, so this cannot throw today; doing
            // it here keeps that guarantee load-bearing.
            $fingerprint = $this->credentialFingerprint($credential, $request);

            $result = $this->verifiers->make($method)->verify($credential, $challenge, [
                'customer' => $this->resolvePayerCustomer($request, $method),
            ]);

            if (! $result->succeeded) {
                return $this->challenge($request, $spec, 'Payment was not settled: '.$result->failureReason, 'verification-failed');
            }

            $receipt = Receipt::fromSettlement($challenge, $result, $method);

            // Metered endpoint: one payment grants N accesses. Issue the prepaid
            // session already reflecting THIS request as the first access
            // (remaining = grants - 1). Creating it at the post-first-access
            // balance means there is no separate first-credit consume that could
            // fail open and hand back a full-credit session (grants + 1 accesses).
            $session = null;
            if ($challenge->isMetered()) {
                $session = $this->sessions->create(
                    scope: $challenge->scope(),
                    remaining: $challenge->grants() - 1,
                    ttl: $this->sessionTtl,
                    settlementRef: $result->settlementRef,
                );
            }

            // Run the protected action exactly once and capture its response.
            $response = $this->serve($request, $next, receipt: $receipt, session: $session);

            // Snapshot the response so a retry (a lost 200, or one that races the
            // burn) replays it verbatim instead of re-running the action or paying
            // again — recorded BEFORE the burn so the record outlives the
            // challenge. Only a buffered response within the size limit can be
            // replayed faithfully; a streamed/binary or over-limit response is not
            // snapshotted, so its retries take a fresh challenge (see
            // mpp.replay_max_bytes). The fingerprint gates the replay to the same
            // payer + request, so the challenge id alone cannot retrieve it.
            $content = $this->replayableBody($response);

            if ($content !== null) {
                $this->ledger->record(
                    $challenge->id,
                    $fingerprint,
                    $session?->id,
                    $response->getStatusCode(),
                    $content,
                    $response->headers->all(),
                    $response->headers->getCookies(),
                );
            }

            // Single-use: burn the challenge so the same payment cannot be replayed.
            $this->challenges->burn($challenge->id);

            return $response;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * If a settled record exists for this challenge AND the presented credential
     * and request match the fingerprint stored at settlement, return its
     * idempotent replay; otherwise null. The fingerprint is the authorization
     * boundary: the challenge id is not secret, so only the same payer repeating
     * the same request (same proof, body, and target) may retrieve the response.
     */
    private function replayIfSettled(string $challengeId, Credential $credential, Request $request): ?Response
    {
        $record = $this->ledger->find($challengeId);

        if ($record !== null && hash_equals($record->fingerprint, $this->credentialFingerprint($credential, $request))) {
            return $this->replaySettled($record);
        }

        return null;
    }

    /**
     * Replay a settled challenge idempotently: reconstruct the recorded response
     * verbatim — status, body, every header, and cookies — taking no payment and
     * NOT re-running the action (which would duplicate side effects). The
     * `Payment-Session` header is refreshed from the live session so its
     * `remaining` reflects reality.
     */
    private function replaySettled(SettlementRecord $record): Response
    {
        $response = new Response($record->content, $record->status);
        $response->headers->replace($record->headers);

        foreach ($record->cookies as $cookie) {
            $response->headers->setCookie($cookie);
        }

        if ($record->sessionId && ($session = $this->sessions->find($record->sessionId))) {
            $response->headers->set('Payment-Session', SessionHeader::for($session));
        }

        // The replayed response carries the original receipt (and maybe a
        // session); keep it out of shared caches, exactly as the live one is.
        $response->setPrivate();

        return $response;
    }

    /**
     * A non-reversible bind of WHO paid (the rail proof) and WHAT they sent (the
     * request-body digest and the concrete target). Replay requires an exact
     * match, so the challenge id alone — which is not secret — cannot retrieve
     * the paid response, and a different credential or a changed body cannot
     * either.
     */
    private function credentialFingerprint(Credential $credential, Request $request): string
    {
        // Bind replay to the COMPLETE credential — the echoed challenge and every
        // payload field (the Stripe SPT, the Tempo signature and payer source, any
        // custom-rail data) plus the credential `source` — combined with the
        // request body digest and the concrete target. Fingerprinting a known list
        // of proof field names would miss the rest; the whole parsed credential
        // misses nothing.
        //
        // The credential carries a canonical encoding computed by the parser from
        // the TYPED JSON graph. JCS sorts object keys (so a reordered credential
        // still matches) and keeps a nested {} distinct from a nested [] (which
        // the credential's flattened array form cannot). Re-deriving it from the
        // arrays here would reintroduce that collision.
        return hash('sha256', implode("\0", [
            (string) $credential->canonical,
            (string) (Digest::forRequest($request) ?? ''),
            $this->resourceIdentity($request),
        ]));
    }

    /**
     * The buffered response body to snapshot for replay, or null when the
     * response cannot be replayed faithfully — a streamed/binary response (its
     * body is never buffered) or one over mpp.replay_max_bytes. A null result is
     * recorded as no snapshot, so a lost-response retry takes a fresh challenge
     * rather than replaying an empty or truncated body.
     */
    private function replayableBody(Response $response): ?string
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            Log::warning('[mpp] A paid '.$response::class.' response cannot be snapshotted for idempotent replay; a lost-response retry will take a fresh challenge.');

            return null;
        }

        $content = (string) $response->getContent();

        if (strlen($content) > $this->replayMaxBytes) {
            Log::warning('[mpp] A paid response exceeded mpp.replay_max_bytes and was not snapshotted for idempotent replay; a lost-response retry will take a fresh challenge.');

            return null;
        }

        return $content;
    }

    /**
     * A 409 telling the client that a settlement for this challenge is already
     * in progress: retry shortly, do not pay again. Distinct from a 402 (which
     * means "pay"), so a conformant agent waits instead of double-paying.
     */
    private function settlementInProgress(string $challengeId): Response
    {
        return $this->problem([
            'type' => 'https://paymentauth.org/problems/settlement-in-progress',
            'title' => 'Settlement In Progress',
            'status' => Response::HTTP_CONFLICT,
            'detail' => 'A payment for this challenge is already being settled. Retry the same request shortly; do not submit a new payment.',
            'challengeId' => $challengeId,
        ], Response::HTTP_CONFLICT)->header('Retry-After', '2');
    }

    /**
     * An RFC 9457 problem+json response with MPP's shared framing —
     * application/problem+json, never cached. Callers add the response-specific
     * headers (WWW-Authenticate on a 402, Retry-After on a 409).
     *
     * @param  array<string, mixed>  $body
     */
    private function problem(array $body, int $status): Response
    {
        return response()
            ->json($body, $status)
            ->header('Content-Type', 'application/problem+json')
            ->header('Cache-Control', 'no-store');
    }

    private function serve(Request $request, Closure $next, ?Receipt $receipt = null, ?Session $session = null): Response
    {
        $response = $next($request);

        // A receipt attests a SUCCESSFUL exchange, so it is attached only to a
        // 2xx response — a controller that errors after settlement must not carry
        // a success receipt. The same gate applies to the session header.
        if ($response->isSuccessful()) {
            if ($receipt !== null) {
                $response->headers->set('Payment-Receipt', $receipt->header());
            }

            if ($session !== null) {
                $response->headers->set('Payment-Session', SessionHeader::for($session));
            }

            // Receipts and sessions are sensitive per-payer values, so a
            // response carrying either must not be shared from a cache.
            if ($receipt !== null || $session !== null) {
                $response->setPrivate();
            }
        }

        return $response;
    }

    private function challenge(Request $request, PaymentSpec $spec, ?string $detail = null, string $type = 'payment-required'): Response
    {
        $realm = config('mpp.realm') ?? $request->getHost();
        $accept = AcceptPayment::parse($request->header('Accept-Payment'));

        // Bind the challenge to this request's body (RFC 9530 digest) and to the
        // route it is minted for (resource), so the paid retry cannot swap the
        // body or settle against a different route.
        $challenges = $this->factory->mintAll(
            $spec,
            $realm,
            $accept,
            Digest::forRequest($request),
            $this->resourceIdentity($request),
        );

        foreach ($challenges as $challenge) {
            $this->challenges->put($challenge);
        }

        $document = $this->factory->problemDocument($challenges, $detail, $type);

        return $this->problem($document, (int) $document['status'])
            ->header('WWW-Authenticate', $this->factory->wwwAuthenticate($challenges));
    }

    /**
     * The identity of the route a challenge is minted for: HTTP method + route
     * pattern. Stable across path parameters (so a metered pass spanning
     * `/x/{a}` and `/x/{b}` settles on its own pattern) and distinct across
     * routes (so a shared scope cannot let one route's challenge settle another).
     */
    private function resourceIdentity(Request $request): string
    {
        // The concrete request target: method + path + normalized (sorted) query.
        // Binds the exact resource — parameterized paths and price/selection
        // query params included — not just the route pattern, so a challenge for
        // one target cannot settle or replay another.
        $query = $request->query->all();
        ksort($query);
        $queryString = http_build_query($query);

        return $request->getMethod().' '.$request->getPathInfo().($queryString === '' ? '' : '?'.$queryString);
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
