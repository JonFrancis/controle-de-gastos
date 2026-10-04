<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecurrenceRequest extends FormRequest
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
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'day_of_month' => ['required', 'integer', 'min:1', 'max:31'],
            'description' => ['required', 'string', 'max:160'],
            'card_name' => ['nullable', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payer_id' => ['nullable', Rule::exists('participants', 'id')->where('active', true)],
            'participant_id' => ['nullable', Rule::exists('participants', 'id')->where('active', true)],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('active', true)],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('active', true)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => is_string($this->amount) ? str_replace(',', '.', $this->amount) : $this->amount]);
    }

    public function messages(): array
    {
        return [
            'amount.numeric' => 'Informe um valor válido.',
            'amount.min' => 'O valor deve ser maior que zero.',
            'end_date.after_or_equal' => 'A data final deve ser igual ou posterior à data inicial.',
        ];
    }
}
