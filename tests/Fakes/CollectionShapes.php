<?php

namespace Square1\Mpp\Tests\Fakes;

/**
 * One property for each collection type that the README states.
 *
 * The class exists so that the documented support matrix and the tests stay
 * the same size. A form that the README names has a property here.
 */
final class CollectionShapes
{
    /**
     * A tag on the property itself, which is where a property that the
     * constructor does not promote carries its type.
     *
     * @var list<string>
     */
    public array $fromVarTag = [];

    /**
     * @param  ClipSource[]  $suffix
     * @param  array<int, ClipSource>  $intKeyed
     * @param  non-empty-list<int>  $nonEmpty
     * @param  list<string>  $nullable
     * @param  array<string, list<int>>  $mapOfLists
     * @param  array<array-key, string>  $arrayKeyed
     * @param  list<int|string>  $mixedMembers
     * @param  Collection<int, ClipSource>  $unmodelled
     */
    public function __construct(
        public readonly array $suffix = [],
        public readonly array $intKeyed = [],
        public readonly array $nonEmpty = [],
        public readonly ?array $nullable = null,
        public readonly array $mapOfLists = [],
        public readonly array $arrayKeyed = [],
        public readonly array $mixedMembers = [],
        public readonly array $unmodelled = [],
    ) {}
}
