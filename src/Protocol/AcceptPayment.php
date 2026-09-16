<?php

namespace Square1\Mpp\Protocol;

/**
 * The `Accept-Payment` request header of the client.
 *
 * The header is a weighted list of method and intent ranges, and it can contain
 * wildcards. It declares what the caller can pay with. The core spec defines it
 * in its section on client payment preferences.
 *
 *   Accept-Payment: tempo/charge, stripe/charge;q=0.5, solana/*;q=0.3
 *
 * A server keeps the offered challenges that match a range with q>0, and orders
 * them by descending q. When nothing matches, the server ignores the header and
 * returns its normal set. The challenge stays authoritative. This header changes
 * only which challenges the server offers, never their contents.
 */
final class AcceptPayment
{
    /**
     * @param  list<array{method: string, intent: string, q: float, order: int}>  $ranges
     */
    private function __construct(private readonly array $ranges) {}

    public static function parse(?string $header): self
    {
        if (! is_string($header) || trim($header) === '') {
            return new self([]);
        }

        $ranges = [];
        foreach (explode(',', $header) as $order => $entry) {
            $parts = array_map(trim(...), explode(';', trim($entry)));
            $token = array_shift($parts);

            if (! preg_match('#^([a-z*][a-z]*|\*)/([A-Za-z0-9-]+|\*)$#', (string) $token, $m)) {
                continue; // the spec states that a server ignores a malformed entry
            }

            $q = 1.0;
            foreach ($parts as $param) {
                if (preg_match('/^q=([0-9.]+)$/i', $param, $qm)) {
                    $q = max(0.0, min(1.0, (float) $qm[1]));
                }
            }

            $ranges[] = ['method' => $m[1], 'intent' => $m[2], 'q' => $q, 'order' => $order];
        }

        return new self($ranges);
    }

    public function isEmpty(): bool
    {
        return $this->ranges === [];
    }

    /**
     * Filters and ranks an ordered list of offered method names, for one intent.
     *
     * The method returns the list of the server without a change when the header
     * is absent or malformed, or when nothing in it matches with q>0.
     *
     * @param  list<string>  $methods  the order that the server prefers
     * @return list<string>
     */
    public function rank(array $methods, string $intent): array
    {
        if ($this->isEmpty()) {
            return $methods;
        }

        $ranked = [];
        foreach ($methods as $serverOrder => $method) {
            $q = $this->quality($method, $intent);
            if ($q !== null && $q > 0.0) {
                $ranked[] = ['method' => $method, 'q' => $q, 'server' => $serverOrder];
            }
        }

        if ($ranked === []) {
            return $methods;
        }

        usort($ranked, fn ($a, $b) => $b['q'] <=> $a['q'] ?: $a['server'] <=> $b['server']);

        return array_column($ranked, 'method');
    }

    /**
     * Returns the q value of the matching range that is most specific.
     *
     * The method returns null when no range matches. That result is different
     * from an explicit q=0, which excludes the method.
     */
    private function quality(string $method, string $intent): ?float
    {
        $best = null;
        $bestSpecificity = -1;

        foreach ($this->ranges as $range) {
            $methodMatch = $range['method'] === $method || $range['method'] === '*';
            $intentMatch = $range['intent'] === $intent || $range['intent'] === '*';

            if (! $methodMatch || ! $intentMatch) {
                continue;
            }

            $specificity = (int) ($range['method'] !== '*') * 2 + (int) ($range['intent'] !== '*');

            if ($specificity > $bestSpecificity) {
                $bestSpecificity = $specificity;
                $best = $range['q'];
            }
        }

        return $best;
    }
}
