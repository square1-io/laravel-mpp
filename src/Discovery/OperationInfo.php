<?php

namespace Square1\Mpp\Discovery;

use Square1\Mpp\Attributes\DiscoveryInfo;

/**
 * The documentation a site owner states about one payable operation, normalised
 * out of whichever of the three surfaces it was written on.
 *
 * A site owner can say the same thing in three places, and which one they reach
 * for is a matter of where the route lives rather than what they want to say:
 *
 *   1. `->discovery(summary: '…')` on the route — closures and route files;
 *   2. `#[DiscoveryInfo(summary: '…')]` on the action — controllers;
 *   3. `config('mpp.discovery.operations')`, keyed by route name or
 *      `"GET /uri"` — routes from a package you do not edit.
 *
 * They all produce one of these, and `mergeUnder()` settles a route that uses
 * more than one: the nearer the route a thing is written, the more it wins, so
 * the order above is also the precedence. Merging is per FIELD, not per source —
 * a summary in config survives a route macro that only sets a price note.
 *
 * Nothing here is authoritative for payment. A registry may show it, an agent
 * may plan against it, and the 402 still decides what is owed.
 */
final class OperationInfo
{
    /**
     * @param  string|array<string, string>|null  $priceNote
     * @param  list<string>  $tags
     * @param  class-string|array<string, mixed>|null  $request
     * @param  class-string|array<string, mixed>|null  $response
     * @param  array<string, string|array<string, mixed>>  $parameters
     * @param  array<string, string|array<string, mixed>>  $query
     */
    public function __construct(
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly string|array|null $priceNote = null,
        public readonly array $tags = [],
        public readonly ?string $operationId = null,
        public readonly string|array|null $request = null,
        public readonly string|array|null $response = null,
        public readonly array $parameters = [],
        public readonly array $query = [],
        public readonly ?bool $deprecated = null,
        public readonly ?bool $hidden = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * The attribute declares the same eleven fields under the same names, and
     * `fromArray()` already coerces each of them, so the mapping is the
     * property list itself. Enumerating it again would assert a correspondence
     * nothing checks, and a renamed attribute field would go quietly unread.
     */
    public static function fromAttribute(DiscoveryInfo $attribute): self
    {
        return self::fromArray(get_object_vars($attribute));
    }

    /**
     * Build from the loose array shape the route macro and config both take.
     * Unknown keys are ignored rather than rejected: a discovery document is
     * advisory, and a typo in it must never take an endpoint's documentation
     * down with it.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $string = fn (string $key): ?string => is_string($values[$key] ?? null) && $values[$key] !== ''
            ? $values[$key]
            : null;

        $bool = fn (string $key): ?bool => isset($values[$key]) ? (bool) $values[$key] : null;

        $schema = fn (string $key): string|array|null => is_string($values[$key] ?? null) || is_array($values[$key] ?? null)
            ? $values[$key]
            : null;

        return new self(
            summary: $string('summary'),
            description: $string('description'),
            priceNote: $schema('priceNote') ?? $schema('price_note'),
            tags: array_values(array_map('strval', (array) ($values['tags'] ?? []))),
            operationId: $string('operationId') ?? $string('operation_id'),
            request: $schema('request'),
            response: $schema('response'),
            parameters: (array) ($values['parameters'] ?? []),
            query: (array) ($values['query'] ?? []),
            deprecated: $bool('deprecated'),
            hidden: $bool('hidden'),
        );
    }

    /**
     * Fill every field this one leaves unstated from `$fallback`. The receiver
     * is the higher-precedence source, and it wins field by field.
     */
    public function mergeUnder(self $fallback): self
    {
        return new self(
            summary: $this->summary ?? $fallback->summary,
            description: $this->description ?? $fallback->description,
            priceNote: $this->priceNote ?? $fallback->priceNote,
            tags: $this->tags !== [] ? $this->tags : $fallback->tags,
            operationId: $this->operationId ?? $fallback->operationId,
            request: $this->request ?? $fallback->request,
            response: $this->response ?? $fallback->response,
            parameters: $this->parameters !== [] ? $this->parameters : $fallback->parameters,
            query: $this->query !== [] ? $this->query : $fallback->query,
            deprecated: $this->deprecated ?? $fallback->deprecated,
            hidden: $this->hidden ?? $fallback->hidden,
        );
    }

    /**
     * The same value with a different `operationId` — the one case where the
     * document settles a field the sources did not: a route serving several
     * OpenAPI paths cannot give them all one id, which OpenAPI requires to be
     * unique. Returning a settled value object keeps a single answer to "what
     * is this operation's id" in flight, rather than passing the corrected one
     * alongside the stale one.
     */
    public function withOperationId(?string $operationId): self
    {
        return new self(
            summary: $this->summary,
            description: $this->description,
            priceNote: $this->priceNote,
            tags: $this->tags,
            operationId: $operationId,
            request: $this->request,
            response: $this->response,
            parameters: $this->parameters,
            query: $this->query,
            deprecated: $this->deprecated,
            hidden: $this->hidden,
        );
    }

    /**
     * The `description` for one rail's offer. A bare string documents every
     * offer on the route; a map documents each rail in its own words, which is
     * what a route wants when the rails differ in more than price ("settles
     * instantly" vs "card, 3-day refund window").
     */
    public function priceNoteFor(string $method): ?string
    {
        if (is_string($this->priceNote)) {
            return $this->priceNote !== '' ? $this->priceNote : null;
        }

        $note = $this->priceNote[$method] ?? null;

        return is_string($note) && $note !== '' ? $note : null;
    }
}
