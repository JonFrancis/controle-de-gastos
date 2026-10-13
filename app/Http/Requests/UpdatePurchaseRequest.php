<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePurchaseRequest extends FormRequest
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
            'due_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payer_id' => ['required', Rule::exists('participants', 'id')->where('active', true)],
            'participant_id' => ['nullable', Rule::exists('participants', 'id')->where('active', true)],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')],
            'category_id' => ['nullable', Rule::exists('categories', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (PaymentMethod::query()->whereKey($this->input('payment_method_id'))->value('type') === PaymentMethod::TYPE_LOAN && blank($this->input('due_date'))) {
                $validator->errors()->add('due_date', 'Informe a data de vencimento do empréstimo.');
            }
        });
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
