<?php

namespace Square1\Mpp\Tests\Fakes;

use Illuminate\Http\JsonResponse;
use Square1\Mpp\Attributes\DiscoveryInfo;
use Square1\Mpp\Attributes\RequiresPayment;

class DocumentedController
{
    #[RequiresPayment(amount: '0.50', currency: 'USD', methods: ['stripe', 'tempo'])]
    #[DiscoveryInfo(
        summary: 'Clip a video',
        description: 'Returns a clip of the source video.',
        priceNote: ['stripe' => 'Card, per clip.', 'tempo' => 'On-chain, per clip.'],
        tags: ['media'],
        request: ClipRequest::class,
        response: ['type' => 'object', 'required' => ['url'], 'properties' => [
            'url' => ['type' => 'string', 'format' => 'uri'],
        ]],
    )]
    public function clip(ClipRequest $request): JsonResponse
    {
        return response()->json(['url' => 'https://example.test/clip.mp4']);
    }

    /**
     * Summarise a transcript.
     *
     * Reads the whole transcript and returns a paragraph.
     * Internal note: this is the docblock the docblocks setting publishes.
     *
     * @param  ClipRequest  $request  ignored by the summary reader
     */
    #[RequiresPayment(amount: '1.00', currency: 'USD')]
    public function summarise(): JsonResponse
    {
        return response()->json(['summary' => 'ok']);
    }

    #[RequiresPayment(amount: '0.50', currency: 'USD')]
    #[DiscoveryInfo(hidden: true)]
    public function unlisted(): JsonResponse
    {
        return response()->json(['secret' => true]);
    }

    /**
     * An action typed against a FormRequest but saying nothing else: the input
     * schema has to come from the rules alone.
     */
    #[RequiresPayment(amount: '0.50', currency: 'USD')]
    public function derived(ClipRequest $request): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    /**
     * An action that type-hints its path parameter, so the published parameter
     * can state a type rather than the string that every segment is on the
     * wire.
     */
    #[RequiresPayment(amount: '0.50', currency: 'USD')]
    public function item(int $id): JsonResponse
    {
        return response()->json(['id' => $id]);
    }

    /**
     * A GET action whose FormRequest validates the query string.
     */
    #[RequiresPayment(amount: '0.50', currency: 'USD')]
    public function report(ReportQuery $request): JsonResponse
    {
        return response()->json(['report' => 'ok']);
    }

    #[RequiresPayment(amount: '0.50', currency: 'USD')]
    #[DiscoveryInfo(response: [
        '200' => ScoreSchema::class,
        '404' => NotFoundSchema::class,
    ])]
    public function score(): JsonResponse
    {
        return response()->json(['score' => 1]);
    }
}
