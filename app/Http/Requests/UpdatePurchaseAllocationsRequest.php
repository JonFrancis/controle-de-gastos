<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseAllocationsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $allocations = collect($this->input('allocations', []))
            ->map(function (array $row): array {
                foreach (['amount', 'percentage'] as $field) {
                    if (isset($row[$field]) && is_string($row[$field])) {
                        $row[$field] = str_replace(',', '.', $row[$field]);
                    }
                }

                return $row;
            })
            ->all();

        $this->merge(['allocations' => $allocations]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'allocation_mode' => ['required', Rule::in(['equal', 'amount', 'percentage'])],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*' => ['required', 'array'],
            'allocations.*.participant_id' => ['required', Rule::exists('participants', 'id')->where('active', true)],
            'allocations.*.amount' => ['nullable', 'numeric', 'min:0'],
            'allocations.*.percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
