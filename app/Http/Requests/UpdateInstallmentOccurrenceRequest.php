<?php

namespace App\Http\Requests;

use App\Models\InstallmentOccurrence;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;

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
        return ['amount' => ['required', 'numeric', 'min:0.01'], 'due_date' => ['nullable', 'date']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => is_string($this->amount) ? str_replace(',', '.', $this->amount) : $this->amount]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $occurrence = $this->route('installmentOccurrence');
            if ($occurrence instanceof InstallmentOccurrence && $occurrence->paymentMethod?->type === PaymentMethod::TYPE_LOAN && blank($this->input('due_date'))) {
                $validator->errors()->add('due_date', 'Informe a data de vencimento do empréstimo.');
            }
        });
    }
}
