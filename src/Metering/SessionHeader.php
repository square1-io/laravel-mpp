<?php

namespace Square1\Mpp\Metering;

/**
 * Renders the `Payment-Session` response header.
 */
class SessionHeader
{
    public static function for(Session $session): string
    {
        $parts = [
            'id' => $session->id,
            'remaining' => (string) $session->remaining,
            'scope' => $session->scope,
            'expiresAt' => $session->expiresAt->toIso8601ZuluString(),
        ];

        return implode(', ', array_map(
            fn ($key, $value) => sprintf('%s="%s"', $key, $value),
            array_keys($parts),
            array_values($parts),
        ));
    }
}
