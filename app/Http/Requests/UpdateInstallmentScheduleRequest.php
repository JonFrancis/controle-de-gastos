<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateInstallmentScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'description' => ['sometimes', 'required', 'string', 'max:160'],
            'card_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'total' => ['required', 'numeric', 'min:0.01'],
            'payer_id' => ['sometimes', 'nullable', Rule::exists('participants', 'id')->where('active', true)],
            'participant_id' => ['sometimes', 'nullable', Rule::exists('participants', 'id')->where('active', true)],
            'payment_method_id' => ['sometimes', 'required', Rule::exists('payment_methods', 'id')->where('active', true)],
            'category_id' => ['sometimes', 'nullable', Rule::exists('categories', 'id')->where('active', true)],
            'allocation_mode' => ['sometimes', 'required', 'string', 'in:equal,amount,percentage'],
            'allocations' => ['sometimes', 'required', 'array', 'min:1'],
            'allocations.*' => ['required', 'array'],
            'allocations.*.participant_id' => ['nullable', Rule::exists('participants', 'id')->where('active', true)],
            'allocations.*.category_id' => ['nullable', Rule::exists('categories', 'id')->where('active', true)],
            'allocations.*.amount' => ['nullable', 'numeric', 'min:0'],
            'allocations.*.percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'confirmation' => ['required', 'accepted'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            $start = CarbonImmutable::parse($this->input('start_date'))->startOfMonth();
            $end = CarbonImmutable::parse($this->input('end_date'))->startOfMonth();
            $count = $start->diffInMonths($end) + 1;

            if ($count < 2) {
                $validator->errors()->add('end_date', 'O parcelamento deve ter pelo menos duas parcelas.');
            }

            if ($count > 120) {
                $validator->errors()->add('end_date', 'O período pode ter no máximo 120 parcelas.');
            }
        });
    }
}
