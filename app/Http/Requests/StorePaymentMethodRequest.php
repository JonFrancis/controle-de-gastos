<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', 'unique:payment_methods,name'],
            'type' => ['required', Rule::in(PaymentMethod::types())],
            'closing_day' => ['nullable', 'integer', 'between:1,31'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('closing_day', ['required'], fn () => $this->input('type') === PaymentMethod::TYPE_CREDIT);
    }
}
