<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInstallmentRequest extends FormRequest
{
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
            'start_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:160'],
            'card_name' => ['nullable', 'string', 'max:160'],
            'total' => ['required', 'numeric', 'min:0.01'],
            'installment_count' => ['required', 'integer', 'min:2', 'max:120'],
            'allocation_mode' => ['sometimes', 'required', 'string', 'in:equal,amount,percentage'],
            'allocations' => ['sometimes', 'required', 'array', 'min:1'],
            'allocations.*' => ['required', 'array'],
            'allocations.*.participant_id' => ['nullable', Rule::exists('participants', 'id')->where('active', true)],
            'allocations.*.category_id' => ['nullable', Rule::exists('categories', 'id')->where('active', true)],
            'allocations.*.amount' => ['nullable', 'numeric', 'min:0'],
            'allocations.*.percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payer_id' => ['nullable', Rule::exists('participants', 'id')->where('active', true)],
            'participant_id' => ['nullable', Rule::exists('participants', 'id')->where('active', true)],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('active', true)],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('active', true)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = ['total' => is_string($this->total) ? str_replace(',', '.', $this->total) : $this->total];

        if ($this->has('allocations')) {
            $data['allocations'] = collect($this->input('allocations', []))->map(fn (array $allocation): array => [
                ...$allocation,
                'amount' => is_string($allocation['amount'] ?? null) ? str_replace(',', '.', $allocation['amount']) : ($allocation['amount'] ?? null),
                'percentage' => is_string($allocation['percentage'] ?? null) ? str_replace(',', '.', $allocation['percentage']) : ($allocation['percentage'] ?? null),
            ])->all();
        }

        $this->merge($data);
    }

    public function messages(): array
    {
        return [
            'total.numeric' => 'Informe um valor total válido.',
            'total.min' => 'O valor total deve ser maior que zero.',
            'installment_count.min' => 'Informe pelo menos duas parcelas.',
        ];
    }
}
