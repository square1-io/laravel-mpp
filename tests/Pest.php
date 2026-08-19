<?php

use Illuminate\Testing\TestResponse;
use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Support\Jcs;
use Square1\Mpp\Support\Money;
use Square1\Mpp\Tests\AttributesEnabledTestCase;
use Square1\Mpp\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit', 'Integration');
uses(AttributesEnabledTestCase::class)->in('Provider');

/**
 * Parse a (possibly comma-combined) `WWW-Authenticate` value into one param
 * map per `Payment …` challenge entry.
 *
 * @return list<array<string, string>>
 */
function parseChallenges(?string $header): array
{
    $challenges = [];

    foreach (preg_split('/(?:^|,\s*)Payment\s+/', (string) $header, flags: PREG_SPLIT_NO_EMPTY) as $entry) {
        preg_match_all('/([a-z][a-z0-9_-]*)="((?:[^"\\\\]|\\\\.)*)"/i', $entry, $m, PREG_SET_ORDER);

        $params = [];
        foreach ($m as $pair) {
            $params[$pair[1]] = stripcslashes($pair[2]);
        }

        if ($params !== []) {
            $challenges[] = $params;
        }
    }

    return $challenges;
}

/**
 * Fetch a 402 and return the (first, or method-matching) challenge's wire
 * params — the exact map a conformant client echoes back in its credential.
 *
 * @return array<string, string>
 */
function getChallenge(TestCase $test, string $uri, ?string $method = null): array
{
    $response = $test->get($uri);

    foreach (parseChallenges($response->headers->get('WWW-Authenticate')) as $challenge) {
        if ($method === null || ($challenge['method'] ?? null) === $method) {
            return $challenge;
        }
    }

    return [];
}

/**
 * Build a spec-format `Authorization: Payment <base64url>` credential value.
 *
 * @param  array<string, string>  $challenge  echoed challenge params
 * @param  array<string, mixed>  $payload  rail settlement proof
 */
function paymentCredential(array $challenge, array $payload, ?string $source = null): string
{
    $body = ['challenge' => $challenge, 'payload' => $payload];

    if ($source !== null) {
        $body['source'] = $source;
    }

    return 'Payment '.Base64Url::encode(Jcs::encode($body));
}

/**
 * @param  array<string, string>  $challenge
 */
function payWithSpt(TestCase $test, array $challenge, string $uri, string $spt = 'spt_test')
{
    return $test->withHeaders([
        'Authorization' => paymentCredential($challenge, ['spt' => $spt]),
    ])->get($uri);
}

function spendWithSession(TestCase $test, string $sessionId, string $uri)
{
    return $test->withHeaders([
        'Authorization' => sprintf('Payment session="%s"', $sessionId),
    ])->get($uri);
}

function sessionIdFromHeader(?string $header): string
{
    preg_match('/id="([^"]+)"/', (string) $header, $m);

    return $m[1] ?? '';
}

/**
 * Decode a base64url `Payment-Receipt` header into its JSON fields.
 *
 * @return array<string, mixed>
 */
function decodeReceipt(?string $header): array
{
    $json = Base64Url::decode((string) $header);
    $decoded = $json === null ? null : json_decode($json, associative: true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * The challenged decimal amount, read from a 402's (first) challenge request
 * and rendered back from minor units for readable price assertions.
 */
function challengedAmount(TestResponse $response): ?string
{
    $challenges = parseChallenges($response->headers->get('WWW-Authenticate'));

    if ($challenges === []) {
        return null;
    }

    $request = json_decode((string) Base64Url::decode($challenges[0]['request'] ?? ''), true);

    if (! is_array($request) || ! isset($request['amount'], $request['currency'])) {
        return null;
    }

    return Money::fromMinorUnits((int) $request['amount'], (string) $request['currency']);
}

/**
 * A named opaque field from a 402's (first) challenge.
 */
function challengedOpaque(TestResponse $response, string $key): mixed
{
    $challenges = parseChallenges($response->headers->get('WWW-Authenticate'));
    $opaque = json_decode((string) Base64Url::decode($challenges[0]['opaque'] ?? ''), true);

    return is_array($opaque) ? ($opaque[$key] ?? null) : null;
}
