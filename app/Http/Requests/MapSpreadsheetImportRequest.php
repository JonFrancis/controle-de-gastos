<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MapSpreadsheetImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ];
    }
}
