<?php

namespace Square1\Mpp\Tests\Fakes;

/**
 * A backed enum, whose cases are the members of a schema.
 */
enum ClipFormat: string
{
    case Mp4 = 'mp4';
    case Webm = 'webm';
}
