<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportSelectionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'mode' => $this->query('mode', 'period'),
            'start_date' => $this->query('start_date', now()->startOfMonth()->toDateString()),
            'end_date' => $this->query('end_date', now()->endOfMonth()->toDateString()),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['period', 'history'])],
            'start_date' => ['nullable', 'required_if:mode,period', 'date'],
            'end_date' => ['nullable', 'required_if:mode,period', 'date', 'after_or_equal:start_date'],
        ];
    }
}
