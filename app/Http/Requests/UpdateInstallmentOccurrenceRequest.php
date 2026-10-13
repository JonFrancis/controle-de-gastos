<?php

namespace App\Http\Requests;

use App\Models\InstallmentOccurrence;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateInstallmentOccurrenceRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'due_date' => ['nullable', 'date'],
            'payment_method_type' => ['sometimes', 'string', Rule::in(PaymentMethod::types())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => is_string($this->amount) ? str_replace(',', '.', $this->amount) : $this->amount]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $occurrence = $this->route('installmentOccurrence');
            $paymentMethodType = $occurrence instanceof InstallmentOccurrence ? $occurrence->paymentMethod?->type : null;
            if ($this->filled('payment_method_type') && $this->input('payment_method_type') !== $paymentMethodType) {
                $validator->errors()->add('payment_method_type', 'A forma de pagamento da ocorrência não pode ser alterada neste ajuste.');
            }
            if ($paymentMethodType === PaymentMethod::TYPE_LOAN && blank($this->input('due_date'))) {
                $validator->errors()->add('due_date', 'Informe a data de vencimento do empréstimo.');
            }
        });
    }
}
