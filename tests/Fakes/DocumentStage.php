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
     * Adds a key to every offer that the draft's schema forbids — the exact
     * shape of mpp.tl's own non-conformance, and what the conformance test
     * exists to catch.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function addRecipient(array $document): array
    {
        foreach ($document['paths'] as $path => $verbs) {
            foreach ($verbs as $verb => $operation) {
                if (! isset($operation['x-payment-info']['offers'])) {
                    continue;
                }

                foreach ($operation['x-payment-info']['offers'] as $i => $offer) {
                    $document['paths'][$path][$verb]['x-payment-info']['offers'][$i]['recipient'] = '0x0dcd';
                }
            }
        }

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
