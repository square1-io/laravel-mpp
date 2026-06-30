<?php

use Square1\Mpp\Tests\AttributesEnabledTestCase;
use Square1\Mpp\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit', 'Integration');
uses(AttributesEnabledTestCase::class)->in('Provider');

/**
 * Fetch a 402 and return the challenge id + signature the client echoes.
 *
 * @return array{id: string, sig: string}
 */
function getChallenge(TestCase $test, string $uri): array
{
    $response = $test->get($uri);

    return [
        'id' => (string) $response->json('challengeId'),
        'sig' => (string) $response->json('accepts.0.sig'),
    ];
}

/**
 * @param  array{id: string, sig: string}  $challenge
 */
function payWithSpt(TestCase $test, array $challenge, string $uri, string $spt = 'spt_test')
{
    return $test->withHeaders([
        'Authorization' => sprintf(
            'Payment method="stripe", challengeId="%s", sig="%s", spt="%s"',
            $challenge['id'],
            $challenge['sig'],
            $spt
        ),
    ])->get($uri);
}

function spendWithSession(TestCase $test, string $sessionId, string $uri)
{
    return $test->withHeaders([
        'Authorization' => sprintf('Payment method="stripe", session="%s"', $sessionId),
    ])->get($uri);
}

function sessionIdFromHeader(?string $header): string
{
    preg_match('/id="([^"]+)"/', (string) $header, $m);

    return $m[1] ?? '';
}
