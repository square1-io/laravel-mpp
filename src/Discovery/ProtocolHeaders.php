<?php

namespace Square1\Mpp\Discovery;

/**
 * Declares the response headers that the package sets itself.
 *
 * PaymentGate adds four headers to the responses of a payable route. A site
 * owner does not choose them, cannot change them, and cannot remove them. To
 * write them into a response map by hand would therefore be work with no
 * decision in it, and the document would state them only for the routes that
 * somebody remembered.
 *
 * The generator adds them here instead, on the conditions that the gate uses:
 *
 *   - `Payment-Receipt` on a 2xx, after a settlement;
 *   - `Payment-Session` on a 2xx, when the route is metered;
 *   - `WWW-Authenticate` on the 402, which carries the challenge;
 *   - `Retry-After` on the 409, which reports a settlement in progress.
 *
 * A header that the site owner stated wins. This class fills what nobody
 * described.
 *
 * Every header here is optional in OpenAPI, because `required` is absent. That
 * is correct for all four. A 2xx that spent an existing session carries no
 * receipt, and a pricing resolver can meter a route that states no grants. The
 * live response stays authoritative, as it is for the price.
 */
final class ProtocolHeaders
{
    /**
     * Adds the protocol headers to the responses of one payable operation.
     *
     * @param  array<string, array<string, mixed>>  $responses
     * @return array<string, array<string, mixed>>
     */
    public static function apply(array $responses, bool $metered): array
    {
        foreach ($responses as $status => $response) {
            if (((string) $status)[0] !== '2') {
                continue;
            }

            // The stated headers are on the left, so they win. The package
            // describes a header that the site owner did not describe, and
            // never rewrites one that the site owner did.
            $responses[$status]['headers'] = ($response['headers'] ?? []) + self::forSuccess($metered);
        }

        return $responses + [
            '402' => [
                'description' => 'Payment Required',
                'headers' => self::forChallenge(),
            ],
            '409' => [
                'description' => 'Settlement In Progress',
                'headers' => self::forConflict(),
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function forSuccess(bool $metered): array
    {
        $headers = [
            'Payment-Receipt' => [
                'description' => 'The receipt for the settled payment, as base64url(JCS JSON). '
                    .'It states status, method, timestamp and reference. '
                    .'A response that spent an existing session carries no receipt.',
                'schema' => ['type' => 'string'],
            ],
        ];

        if ($metered) {
            $headers['Payment-Session'] = [
                'description' => 'The prepaid session after this request, as '
                    .'id, remaining, scope and expiresAt. '
                    .'Send the id as the credential of the next request, until remaining reaches zero.',
                'schema' => ['type' => 'string'],
            ];
        }

        return $headers;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function forChallenge(): array
    {
        return [
            'WWW-Authenticate' => [
                'description' => 'One Payment challenge for each offered method. '
                    .'The challenge states the price, and it is authoritative.',
                'schema' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function forConflict(): array
    {
        return [
            'Retry-After' => [
                'description' => 'The seconds to wait before you send the same request again. '
                    .'Do not send a second payment.',
                'schema' => ['type' => 'string'],
            ],
        ];
    }
}
