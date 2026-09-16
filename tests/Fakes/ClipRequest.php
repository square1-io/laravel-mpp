<?php

namespace Square1\Mpp\Tests\Fakes;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A stock FormRequest, written the way an application would write one, so the
 * discovery tests derive an input schema from a real rule set rather than a
 * shape invented for them.
 */
class ClipRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => 'required|url',
            'seconds' => 'required|integer|min:1|max:60',
            'format' => 'required|in:mp4,webm',
            'caption' => 'nullable|string|max:120',
            'tags' => 'array|max:5',
            'tags.*' => 'string',
            'watermark.text' => 'required|string',
            'watermark.opacity' => 'numeric|between:0,1',
        ];
    }
}
