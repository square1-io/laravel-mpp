<?php

namespace Square1\Mpp\Attributes;

use Attribute;
use Square1\Mpp\Discovery\OperationInfo;

/**
 * Describes a payment-gated route in the `/openapi.json` discovery document.
 *
 * `#[RequiresPayment]` states what a route COSTS; this states what it IS. The
 * two are deliberately separate: pricing is enforced at runtime and a typo in it
 * is a bug, while everything here is advisory documentation a registry or an
 * agent reads before it ever calls you. Nothing on this attribute can change
 * what is charged.
 *
 *   #[RequiresPayment(amount: '0.50', currency: 'USD')]
 *   #[DiscoveryInfo(
 *       summary: 'Clip a video',
 *       description: 'Returns a 10-second clip starting at the requested offset.',
 *       priceNote: 'Per clip, whatever its length.',
 *       request: ClipRequest::class,
 *       response: ['type' => 'object', 'required' => ['url'], 'properties' => [
 *           'url' => ['type' => 'string', 'format' => 'uri'],
 *       ]],
 *   )]
 *   public function clip(ClipRequest $request) { … }
 *
 * Every field is optional, and anything omitted is either derived from the
 * application (the route name becomes `operationId`, a `FormRequest` type-hint
 * becomes the input schema) or simply left out of the document. The same fields
 * are available on a route — `->discovery(summary: '…')` — and in
 * `config('mpp.discovery.operations')`, for routes you cannot annotate.
 *
 * @see OperationInfo for what each field becomes
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class DiscoveryInfo
{
    /**
     * @param  string|null  $summary  one-line operation title, shown in registry listings
     * @param  string|null  $description  longer prose; Markdown is allowed by OpenAPI
     * @param  string|array<string, string>|null  $priceNote  the offers' `description` — one note for
     *                                                        every rail, or a map keyed by method name
     * @param  list<string>  $tags  OpenAPI operation tags
     * @param  string|null  $operationId  defaults to the route's name
     * @param  class-string|array<string, mixed>|null  $request  input schema: a JSON Schema array, a full
     *                                                           OpenAPI requestBody array, or the class name of
     *                                                           a FormRequest / a class with a `schema()` method
     * @param  class-string|array<string, mixed>|null  $response  output schema: a JSON Schema array for the 200
     *                                                            response, a map keyed by status code, or a class
     *                                                            name as above
     * @param  array<string, string|array<string, mixed>>  $parameters  path parameter name => description, or a
     *                                                                  full OpenAPI parameter array to merge
     * @param  array<string, string|array<string, mixed>>  $query  query parameter name => description, or a full
     *                                                             OpenAPI parameter array
     * @param  bool|null  $deprecated  marks the operation deprecated in the document
     * @param  bool|null  $hidden  keeps the route out of the document entirely; it stays payable
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
        // Nullable, like every other field here, so that null means "said
        // nothing" and `false` can say something: `#[DiscoveryInfo(hidden:
        // false)]` overrides a `hidden: true` in config, which is what the
        // nearest-to-the-route-wins rule promises for the other nine fields.
        public readonly ?bool $deprecated = null,
        public readonly ?bool $hidden = null,
    ) {}
}
