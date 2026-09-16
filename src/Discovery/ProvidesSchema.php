<?php

namespace Square1\Mpp\Discovery;

/**
 * A class of your own that describes one request or response shape.
 *
 * `#[DiscoveryInfo(request: …)]` and `#[DiscoveryInfo(response: …)]` accept a
 * JSON Schema array written inline. An inline array is the shortest form for a
 * small shape, and the wrong form for a shape that several routes share, or for
 * one long enough to hide the rest of the attribute. Name a class that
 * implements this interface instead:
 *
 *   final class ScoreSchema implements ProvidesSchema
 *   {
 *       public static function schema(): array
 *       {
 *           return [
 *               'type' => 'object',
 *               'required' => ['score'],
 *               'properties' => ['score' => ['type' => 'integer']],
 *           ];
 *       }
 *   }
 *
 *   #[DiscoveryInfo(response: ['200' => ScoreSchema::class, '404' => NotFound::class])]
 *
 * The method is static, so the package reads a schema without building the
 * class. A schema describes a shape, and a shape does not depend on the state
 * of an instance.
 *
 * A `FormRequest` cannot implement this interface, because it belongs to
 * Laravel. The package therefore reads the rules of a FormRequest directly. See
 * SchemaResolver.
 */
interface ProvidesSchema
{
    /**
     * Returns the JSON Schema for this shape.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array;
}
