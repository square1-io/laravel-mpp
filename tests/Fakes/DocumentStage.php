<?php

namespace Square1\Mpp\Tests\Fakes;

/**
 * Discovery pipeline stages: one that adds to the document, one that throws,
 * and one that returns something that is not a document.
 */
class DocumentStage
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function components(array $document): array
    {
        $document['components'] = ['schemas' => ['Clip' => ['type' => 'object']]];

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function explode(array $document): array
    {
        throw new \RuntimeException('stage failed');
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function wrongReturn(array $document): string
    {
        return 'not a document';
    }
}
