<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends FormRequest
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
            'purchased_at' => ['required', 'date'], 'description' => ['required', 'string', 'max:160'], 'card_name' => ['nullable', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payer_id' => ['required', Rule::exists('participants', 'id')->where('active', true)],
            'participant_id' => ['required', Rule::exists('participants', 'id')->where('active', true)],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('active', true)],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('active', true)],
            'open_allocation' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => is_string($this->amount) ? str_replace(',', '.', $this->amount) : $this->amount]);
    }

    public function messages(): array
    {
        return ['amount.numeric' => 'Informe um valor válido.', 'amount.min' => 'O valor deve ser maior que zero.'];
    }
}
