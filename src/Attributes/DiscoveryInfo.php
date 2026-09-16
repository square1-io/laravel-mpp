<?php

namespace Square1\Mpp\Attributes;

use Attribute;
use Square1\Mpp\Discovery\OperationInfo;

/**
 * Describes a payment-gated route in the `/openapi.json` discovery document.
 *
 * `#[RequiresPayment]` states what a route COSTS. This attribute states what
 * the route IS. The two are deliberately separate. The package enforces the
 * price at runtime, and a typo in it is a defect. Everything in this attribute
 * is advisory documentation, which a registry or an agent reads before it calls
 * you. No field of this attribute can change the amount that you charge.
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
 * Every field is optional. The package derives an omitted field from the
 * application where it can: the route name becomes the `operationId`, and a
 * `FormRequest` type-hint becomes the input schema. The package leaves any
 * other omitted field out of the document. The same fields are available on a
 * route, as `->discovery(summary: '…')`, and in
 * `config('mpp.discovery.operations')` for a route that you cannot annotate.
 *
 * @see OperationInfo for what each field becomes
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class DiscoveryInfo
{
    /**
     * @param  string|null  $summary  the one-line title of the operation, which a registry
     *                                shows in its listings
     * @param  string|null  $description  longer text. OpenAPI allows Markdown.
     * @param  string|array<string, string>|null  $priceNote  the `description` of the offers. Pass one
     *                                                        note for every rail, or a map keyed by
     *                                                        method name.
     * @param  list<string>  $tags  the OpenAPI tags of the operation
     * @param  string|null  $operationId  the default is the name of the route
     * @param  class-string|array<string, mixed>|null  $request  the input schema. Pass a JSON Schema array,
     *                                                           a full OpenAPI requestBody array, or the name
     *                                                           of a FormRequest class or of a class with a
     *                                                           `schema()` method.
     * @param  class-string|array<string, mixed>|null  $response  the output schema. Pass a JSON Schema array
     *                                                            for the 200 response, a map keyed by status
     *                                                            code, or a class name as above.
     * @param  array<string, string|array<string, mixed>>  $parameters  a map of path parameter name to
     *                                                                  description, or to a full OpenAPI
     *                                                                  parameter array to merge
     * @param  array<string, string|array<string, mixed>>  $query  a map of query parameter name to
     *                                                             description, or to a full OpenAPI
     *                                                             parameter array
     * @param  bool|null  $deprecated  marks the operation as deprecated in the document
     * @param  bool|null  $hidden  keeps the route out of the document. The route stays payable.
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
        // These two fields are nullable, like every other field here. Null
        // means "stated nothing", and `false` therefore states something.
        // `#[DiscoveryInfo(hidden: false)]` overrides a `hidden: true` in the
        // config. That is the rule that the other nine fields follow: the
        // source nearest to the route wins.
        public readonly ?bool $deprecated = null,
        public readonly ?bool $hidden = null,
    ) {}
}
