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
 * The MPP decision engine. The middleware and the attribute enforcer share it.
 *
 *   request
 *     ├─ Authorization: Payment session="…"            -> spend a credit, serve (no charge)
 *     ├─ Authorization: Payment proof/spt="…"+sig      -> verify the offered accept for the
 *     │                                                   credential's method, settle, serve + receipt
 *     └─ (no credential)                               -> mint + sign a 402 challenge
 *
 * A challenge can offer several settlement methods. On a paid retry, the gate
 * routes by the `method` of the credential. It finds the offered accept for
 * THAT method. It verifies the signature of THAT accept. Only then does it call
 * the Verifier of the method. A signature that the server minted for one method
 * does not validate the accept of another method. Each signature therefore
 * stays significant for its own method.
 *
 * The spec arrives priced, and its preconditions have already passed. The
 * PaymentPipeline does both for every guarded route, whichever middleware
 * received the request. This class therefore decides only HOW a chargeable
 * request pays. A spec that a price resolver waived never reaches this class.
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
        // The MPP spec forbids a Payment challenge or a credential over
        // unencrypted HTTP. The 402 and the credential carry payment terms and
        // proofs. The same guard protects the discovery route, so every MPP
        // surface refuses the same transport.
        EnforceHttps::assert($request);

        // The pipeline serves a waived request itself, and never routes one
        // here, so this branch cannot run today. It stays because "this class
        // never receives a free spec" is an assumption about a caller. An
        // earlier assumption about a caller became false when the package added
        // a second entry path. If a future caller passes a free spec, to charge
        // for it is the worst result.
        if ($spec->free) {
            throw new InvalidConfigurationException(
                "A waived spec reached the payment gate, for the scope '{$spec->scope}'. "
                .'The PaymentPipeline serves a free request, and the gate must not charge for it.'
            );
        }

        // Fail on a misconfigured rail before the gate mints anything. A
        // missing required setting then appears on the first request, and not
        // later as a settlement failure that is hard to diagnose. A setting that
        // is recommended but absent produces a warning instead. See
        // MethodConfigValidator.
        $this->configValidator->validate($spec);

        try {
            $credential = $this->parser->parse($request->header('Authorization'));
        } catch (MalformedCredentialException $e) {
            // The request offered a Payment credential that the parser cannot
            // read. The request is malformed, which is different from a request
            // with no credential. A request with no credential asks for the price.
            // Answer with the typed problem and a fresh challenge.
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
        // consume() enforces the scope. A client cannot spend a session for one
        // scope on another endpoint.
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
            // The challenge no longer exists. The client can still have paid it and
            // then lost the 200 response. In that case, replay the original receipt
            // instead of a fresh 402 that the client would pay a second time.
            // Replay only for the resource that the client paid for. A settled
            // receipt is not a bearer token for the rest of the site, so the gate
            // treats a record from another route as no record. The client then gets
            // a fresh 402, and the gate serves nothing.
            if ($challengeId && ($replay = $this->replayIfSettled($challengeId, $credential, $request))) {
                return $replay;
            }

            return $this->challenge($request, $spec, 'The challenge is unknown, already used or expired. A new challenge has been issued.', 'invalid-challenge');
        }

        if ($challenge->isExpired()) {
            $this->challenges->burn($challenge->id);

            return $this->challenge($request, $spec, 'The challenge has expired. A new challenge has been issued.', 'payment-expired');
        }

        // This check is significant. The id IS the HMAC over the seven slots of
        // the stored challenge. To recompute the id and compare it proves that
        // the terms the client answers are the terms that this server minted,
        // under the current secret. A challenge that the server minted under a
        // rotated secret fails here.
        if (! $this->binding->verify($challenge)) {
            return $this->challenge($request, $spec, 'The challenge binding is invalid.', 'invalid-challenge');
        }

        // A challenge authorises the resource that the server minted it for,
        // and nothing else. The check above proves that the terms are ours. It
        // does not prove that they are the terms of THIS route. The
        // offered-methods check applies the same rule to the rail. A route that
        // no longer offers tempo must not settle a tempo challenge that the
        // server minted while the route did offer it.
        if ($challenge->scope() !== $spec->scope || ! in_array($challenge->method, $spec->offeredMethods, true)) {
            return $this->challenge($request, $spec, 'This challenge was not issued for this resource. A new challenge has been issued.', 'invalid-challenge');
        }

        // This binds the challenge to the identity of the route. Scope is not a
        // reliable request identity, because an application can share one scope
        // across routes with different prices. A 0.50 challenge could otherwise
        // unlock a 5.00 route at the same scope. The gate binds the resource,
        // which is the HTTP method, the path and the normalized query, into
        // `opaque` at mint. The gate enforces the resource when it is present. A
        // challenge from before this feature has no such key, and the server
        // chooses every body and route, so it is safe to allow an absent key.
        $resource = $challenge->opaque['resource'] ?? null;
        if ($resource !== null && $resource !== $this->resourceIdentity($request)) {
            return $this->challenge($request, $spec, 'This challenge was not issued for this resource. A new challenge has been issued.', 'invalid-challenge');
        }

        // This binds the body of the request (RFC 9530 digest). The paid retry
        // must carry the body that the server minted the challenge for. The gate
        // enforces the digest only when the stored challenge advertises one. A
        // request without a body carries none, and so does a challenge from
        // before this feature. A client therefore cannot change the body after
        // the quote.
        if ($challenge->digestParam() !== '' && $challenge->digestParam() !== (Digest::forRequest($request) ?? '')) {
            return $this->challenge($request, $spec, 'The request body does not match the challenge. A new challenge has been issued.', 'invalid-challenge');
        }

        // The credential must echo the challenge that it names, without a
        // change. A field that is present but altered, such as a changed method,
        // is a malformed credential.
        if (! $credential->echoes($challenge)) {
            return $this->challenge($request, $spec, 'The credential does not echo the challenge it names.', 'malformed-credential');
        }

        // The stored challenge is authoritative for the settlement method. The
        // gate matches the echoed copy in the credential by id, and never trusts
        // it for the terms.
        $method = $challenge->method;

        // This serialises concurrent retries of the same challenge, so that one
        // payment settles exactly once. The TTL (mpp.settle_lock_ttl) must be
        // longer than the slowest verifier. A Tempo on-chain confirmation can
        // take poll_attempts × poll_delay_ms, which is tens of seconds. If the
        // lock expired during settlement, a second request could settle in
        // parallel. The Stripe idempotency key and the ledger below are the
        // other defences.
        $lock = $this->cache->store(config('mpp.cache_store'))->lock('mpp:settle:'.$challenge->id, $this->settleLockTtl);

        try {
            $lock->block(5);
        } catch (LockTimeoutException $e) {
            // Another request holds the lock and is settling this same challenge.
            // Tell the client to wait and to retry. Do NOT tell it to pay again.
            return $this->settlementInProgress($challenge->id);
        }

        try {
            // The challenge no longer exists, so another request settled it while
            // this request waited for the lock. Replay its receipt instead of
            // charging again. The exists() call comes first, which keeps the common
            // first-payment path to one lookup. The gate always burns a settled id,
            // so a live challenge can never have a ledger record. A ledger read here
            // would always miss.
            if (! $this->challenges->exists($challenge->id)) {
                if ($replay = $this->replayIfSettled($challenge->id, $credential, $request)) {
                    return $replay;
                }

                return $this->challenge($request, $spec, 'The challenge has already been used. A new challenge has been issued.', 'invalid-challenge');
            }

            // Compute the idempotency fingerprint BEFORE the gate charges or runs
            // the action. The gate then rejects a credential that it cannot
            // fingerprint at the start, and not after settlement. After settlement,
            // the ledger write would fail, and the challenge would stay unburned and
            // could settle again. The parser already rejects a number that it cannot
            // represent, so this call cannot throw today. The order here keeps that
            // guarantee in force.
            $fingerprint = $this->credentialFingerprint($credential, $request);

            $result = $this->verifiers->make($method)->verify($credential, $challenge, [
                'customer' => $this->resolvePayerCustomer($request, $method),
            ]);

            if (! $result->succeeded) {
                return $this->challenge($request, $spec, 'Payment was not settled: '.$result->failureReason, 'verification-failed');
            }

            $receipt = Receipt::fromSettlement($challenge, $result, $method);

            // This is a metered endpoint, where one payment grants N accesses.
            // Issue the prepaid session with THIS request already counted as the
            // first access, so remaining = grants - 1. The gate creates the session
            // at the balance after the first access. There is therefore no separate
            // first-credit consume, which could allow the request on failure and
            // return a session with a full balance, that is grants + 1 accesses.
            $session = null;
            if ($challenge->isMetered()) {
                $session = $this->sessions->create(
                    scope: $challenge->scope(),
                    remaining: $challenge->grants() - 1,
                    ttl: $this->sessionTtl,
                    settlementRef: $result->settlementRef,
                );
            }

            // Run the protected action exactly once, and capture its response.
            $response = $this->serve($request, $next, receipt: $receipt, session: $session);

            // Snapshot the response, so that a retry replays it without a change. A
            // retry happens when the client loses the 200 response, or when a retry
            // races the burn. The gate then does not run the action again, and the
            // client does not pay again. The gate records the snapshot BEFORE the
            // burn, so the record outlives the challenge.
            //
            // Only a buffered response within the size limit replays correctly. The
            // gate does not snapshot a streamed response, a binary response, or a
            // response over the limit. A retry of such a request takes a fresh
            // challenge. See mpp.replay_max_bytes.
            //
            // The fingerprint restricts the replay to the same payer and the same
            // request, so the challenge id alone cannot retrieve it.
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

            // The challenge is single-use. Burn it, so that the same payment
            // cannot be replayed.
            $this->challenges->burn($challenge->id);

            return $response;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Returns the idempotent replay of a settled challenge, or null.
     *
     * The method returns a replay when a settled record exists for this
     * challenge, AND the presented credential and request match the fingerprint
     * that the gate stored at settlement.
     *
     * The fingerprint is the authorization boundary. The challenge id is not
     * secret. Only the same payer that repeats the same request, with the same
     * proof, body and target, can retrieve the response.
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
     * Replays a settled challenge idempotently.
     *
     * The method reconstructs the recorded response without a change: the
     * status, the body, every header, and the cookies. It takes no payment. It
     * does NOT run the action again, which would repeat the side effects of the
     * action. It refreshes the `Payment-Session` header from the live session,
     * so that `remaining` states the current balance.
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

        // The replayed response carries the original receipt, and it can carry
        // a session. Keep it out of shared caches, as the gate does for the
        // live response.
        $response->setPrivate();

        return $response;
    }

    /**
     * Binds WHO paid and WHAT they sent, in a form that no one can reverse.
     *
     * The rail proof states who paid. The request-body digest and the concrete
     * target state what they sent. A replay needs an exact match. The challenge
     * id alone therefore cannot retrieve the paid response, and the id is not
     * secret. A different credential or a changed body cannot retrieve it
     * either.
     */
    private function credentialFingerprint(Credential $credential, Request $request): string
    {
        // The fingerprint binds the replay to the COMPLETE credential. That is
        // the echoed challenge and every payload field, which includes the
        // Stripe SPT, the Tempo signature, the payer source and any data of a
        // custom rail. It also includes the credential `source`. The gate
        // combines all of it with the digest of the request body and the
        // concrete target. A fingerprint over a known list of proof field names
        // would omit the other fields. The whole parsed credential omits
        // nothing.
        //
        // The credential carries a canonical encoding that the parser computed
        // from the TYPED JSON graph. JCS sorts the keys of an object, so a
        // credential with reordered keys still matches. JCS also keeps a nested
        // {} distinct from a nested [], which the flattened array form of the
        // credential cannot do. To derive the encoding from the arrays here
        // would return that collision.
        return hash('sha256', implode("\0", [
            (string) $credential->canonical,
            (string) (Digest::forRequest($request) ?? ''),
            $this->resourceIdentity($request),
        ]));
    }

    /**
     * Returns the buffered response body to snapshot for replay, or null.
     *
     * The method returns null when the gate cannot replay the response
     * correctly. That is a streamed or binary response, whose body the gate
     * never buffers, or a response over mpp.replay_max_bytes. The gate records
     * a null result as no snapshot. A retry after a lost response then takes a
     * fresh challenge, instead of a replay of an empty or truncated body.
     */
    private function replayableBody(Response $response): ?string
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            Log::warning('[mpp] The package cannot snapshot a paid '.$response::class.' response for an idempotent replay. A retry after a lost response takes a fresh challenge.');

            return null;
        }

        $content = (string) $response->getContent();

        if (strlen($content) > $this->replayMaxBytes) {
            Log::warning('[mpp] A paid response was larger than mpp.replay_max_bytes, so the package did not snapshot it for an idempotent replay. A retry after a lost response takes a fresh challenge.');

            return null;
        }

        return $content;
    }

    /**
     * Returns a 409 response.
     *
     * The response tells the client that a settlement for this challenge is
     * already in progress, that the client is to retry soon, and that it is not
     * to pay again. A 402 means "pay", so the two responses are different. A
     * conformant agent therefore waits instead of paying twice.
     */
    private function settlementInProgress(string $challengeId): Response
    {
        return $this->problem([
            'type' => 'https://paymentauth.org/problems/settlement-in-progress',
            'title' => 'Settlement In Progress',
            'status' => Response::HTTP_CONFLICT,
            'detail' => 'A payment for this challenge is already settling. Retry the same request shortly. Do not send a new payment.',
            'challengeId' => $challengeId,
        ], Response::HTTP_CONFLICT)->header('Retry-After', '2');
    }

    /**
     * Returns an RFC 9457 problem+json response with the shared MPP framing.
     *
     * The framing is the application/problem+json media type, and no caching.
     * The caller adds the headers for its own response: WWW-Authenticate on a
     * 402, and Retry-After on a 409.
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

        // A receipt attests a SUCCESSFUL exchange, so the gate attaches one only
        // to a 2xx response. A controller that fails after settlement must not
        // return a success receipt. The same rule applies to the session
        // header.
        if ($response->isSuccessful()) {
            if ($receipt !== null) {
                $response->headers->set('Payment-Receipt', $receipt->header());
            }

            if ($session !== null) {
                $response->headers->set('Payment-Session', SessionHeader::for($session));
            }

            // A receipt and a session are sensitive values that belong to one
            // payer. A cache must not share a response that carries either one.
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

        // This binds the challenge to the body of this request, as an RFC 9530
        // digest, and to the route that the server mints it for, as the
        // resource. The paid retry therefore cannot change the body, and it
        // cannot settle against a different route.
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
            ->header('WWW-Authenticate', $this->factory->wwwAuthenticateLines($challenges));
    }

    /**
     * Returns the identity of the route that the server mints a challenge for.
     *
     * The identity is the HTTP method and the route pattern. It is stable
     * across path parameters, so a metered pass that covers `/x/{a}` and
     * `/x/{b}` settles on its own pattern. It is also distinct across routes,
     * so a shared scope cannot let the challenge of one route settle another
     * route.
     */
    private function resourceIdentity(Request $request): string
    {
        // This is the concrete request target: the method, the path, and the
        // normalized (sorted) query. It binds the exact resource, which includes
        // a parameterized path and any price or selection query parameter. It
        // binds more than the route pattern, so a challenge for one target
        // cannot settle or replay another target.
        $query = $request->query->all();
        ksort($query);
        $queryString = http_build_query($query);

        return $request->getMethod().' '.$request->getPathInfo().($queryString === '' ? '' : '?'.$queryString);
    }

    /**
     * Resolves an optional seller-account customer for this request.
     *
     * The method uses the `customer_resolver` that the config states for the
     * settlement method. It never stops settlement.
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
