<?php

namespace Square1\Mpp\Tests\Fakes;

use DateTimeImmutable;

/**
 * A response object that states its shape in its types alone.
 *
 * The properties cover each branch that ObjectSchema reads: a scalar, a backed
 * enum, a nested object, a nullable scalar, a default, an array with no member
 * type, a date, and a `mixed` that states nothing.
 */
final class ClipResult
{
    /** @param list<string> $tags */
    public function __construct(
        public readonly string $url,
        public readonly ClipFormat $format,
        public readonly ClipSource $source,
        public readonly ?int $durationMs = null,
        public readonly bool $cached = false,
        public readonly array $tags = [],
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly mixed $extra = null,
    ) {}
}
