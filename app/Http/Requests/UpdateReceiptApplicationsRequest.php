<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReceiptApplicationsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['applications' => collect($this->input('applications', []))->map(fn (array $application): array => [...$application, 'amount' => str_replace(',', '.', (string) ($application['amount'] ?? '0'))])->all()]);
    }

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
            'applications' => ['nullable', 'array'],
            'applications.*' => ['required', 'array'],
            'applications.*.source_type' => ['required', 'string', 'in:purchase_allocation,installment_allocation,installment_occurrence,recurrence_occurrence'],
            'applications.*.source_id' => ['required', 'integer', 'min:1'],
            'applications.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
        ];
    }
}
