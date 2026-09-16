<?php

namespace Square1\Mpp\Tests\Fakes;

use DateTimeImmutable as Stamp;

/**
 * A data object whose docblock names a class through an aliased import.
 */
final class ImportedList
{
    /**
     * @param  list<Stamp>  $stamps
     */
    public function __construct(public readonly array $stamps = []) {}
}
