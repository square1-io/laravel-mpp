<?php

namespace Square1\Mpp\Tests\Fakes;

use Square1\Mpp\Tests\Fakes\Nested\{Alpha, Beta as Renamed};

/**
 * A data object whose docblock names two classes that a GROUPED import brings
 * in, the second one under an alias.
 *
 * pint.json excludes this file. The grouped import is the subject of the test,
 * and the `single_import_per_statement` fixer would rewrite it as two plain
 * imports, which removes what the test covers.
 */
final class GroupedImports
{
    /**
     * @param  Alpha[]  $grouped
     * @param  Renamed[]  $aliased
     */
    public function __construct(
        public readonly array $grouped = [],
        public readonly array $aliased = [],
    ) {}
}
