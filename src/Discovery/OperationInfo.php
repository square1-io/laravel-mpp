<?php

namespace Square1\Mpp\Discovery;

use Square1\Mpp\Attributes\DiscoveryInfo;

/**
 * The documentation that a site owner states about one payable operation.
 *
 * A site owner can state the same data in three places. Which place to use
 * depends on where the route is defined, not on what the site owner wants to
 * state:
 *
 *   1. `->discovery(summary: '…')` on the route, for closures and route files;
 *   2. `#[DiscoveryInfo(summary: '…')]` on the action, for controllers;
 *   3. `config('mpp.discovery.operations')`, keyed by route name or
 *      `"GET /uri"`, for routes from a package that you do not edit.
 *
 * Each place produces one of these objects. `mergeUnder()` settles a route that
 * uses more than one place. The place that is nearest to the route wins, so the
 * order above is also the precedence.
 *
 * The merge works on each FIELD, not on each source. A summary in the config
 * therefore survives a route macro that sets only a price note.
 *
 * Nothing here is authoritative for payment. A registry can show it, and an
 * agent can plan against it. The 402 decides what the client owes.
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
     * Builds the value object from the attribute.
     *
     * The attribute declares the same eleven fields under the same names, and
     * `fromArray()` coerces each of them. The property list is therefore the
     * whole mapping. A second list here would state a correspondence that
     * nothing checks, and the package would not read a renamed attribute field.
     */
    public static function fromAttribute(DiscoveryInfo $attribute): self
    {
        return self::fromArray(get_object_vars($attribute));
    }

    /**
     * Builds the value object from the loose array that the route macro and the
     * config both take.
     *
     * The package ignores an unknown key and does not reject it. A discovery
     * document is advisory, and a typo must not remove the documentation of an
     * endpoint.
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
     * Fills every field that this object leaves unstated from `$fallback`.
     *
     * The receiver is the source with the higher precedence. It wins field by
     * field.
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
     * Returns the same value with a different `operationId`.
     *
     * This is the one field that the document settles and the sources do not. A
     * route can serve several OpenAPI paths, and those paths cannot share one
     * id. OpenAPI requires the id to be unique.
     *
     * The method returns a settled value object. One answer to "what is the id
     * of this operation" is then in use, instead of a corrected answer beside a
     * stale one.
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
     * Returns the `description` for the offer of one rail.
     *
     * A plain string documents every offer on the route. A map documents each
     * rail separately. Use a map when the rails differ in more than price, for
     * example "settles immediately" against "card, 3-day refund window".
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
