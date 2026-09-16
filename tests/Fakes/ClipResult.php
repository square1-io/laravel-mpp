<?php

namespace Square1\Mpp\Tests\Fakes;

use DateTimeImmutable;

/**
 * A response object that states its shape in its types alone.
 *
 * The properties cover each branch that ObjectSchema reads: a scalar, a backed
 * enum, a nested object, a nullable scalar that the caller must still supply, a
 * default, an array of scalars, an array of objects, a map, a date, and a
 * `mixed` that states nothing.
 */
final class ClipResult
{
    /**
     * @param  list<string>  $tags
     * @param  list<ClipSource>  $sources
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public readonly string $url,
        public readonly ClipFormat $format,
        public readonly ClipSource $source,
        public readonly ?string $session,
        public readonly ?int $durationMs = null,
        public readonly bool $cached = false,
        public readonly array $tags = [],
        public readonly array $sources = [],
        public readonly array $counts = [],
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly mixed $extra = null,
    ) {}
}
