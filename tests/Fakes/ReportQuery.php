<?php

namespace Square1\Mpp\Tests\Fakes;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A FormRequest on a GET action. Its rules describe the query string, and not a
 * request body.
 */
class ReportQuery extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => 'required|string|in:json,csv',
            'page' => 'integer|min:1',
            'since' => 'nullable|date',
            // A nested rule needs an OpenAPI serialization style, so the
            // package leaves it out of the query parameters.
            'filter.status' => 'string',
        ];
    }
}
