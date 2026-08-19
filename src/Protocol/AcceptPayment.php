<?php

namespace Square1\Mpp\Protocol;

/**
 * The client's `Accept-Payment` request header: a weighted list of
 * method/intent ranges (wildcards allowed) declaring what the caller can pay
 * with, per the core spec's client payment preferences section.
 *
 *   Accept-Payment: tempo/charge, stripe/charge;q=0.5, solana/*;q=0.3
 *
 * Servers filter their offered challenges to ranges with q>0 and order them by
 * descending q; when nothing matches, the header is ignored and the normal set
 * is returned. The challenge stays authoritative — this only shapes which
 * challenges are offered, never their contents.
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
                continue; // malformed entries are ignored, per spec
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
     * Filter and rank an ordered list of offered method names for a given
     * intent. Returns the server's own list untouched when the header is
     * absent, malformed, or matches nothing with q>0.
     *
     * @param  list<string>  $methods  server-preferred order
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
     * The q value of the most specific matching range, or null when no range
     * matches (distinct from an explicit q=0 exclusion).
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
