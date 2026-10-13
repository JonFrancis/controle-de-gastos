<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRecurrenceRequest extends FormRequest
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
            'due_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:recurrence_start_date'],
            'active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['recurrence_start_date' => $this->route('recurrence')->start_date->toDateString()]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $recurrence = $this->route('recurrence');
            if ($recurrence?->paymentMethod?->type === PaymentMethod::TYPE_LOAN && blank($this->input('due_date'))) {
                $validator->errors()->add('due_date', 'Informe a data de vencimento do empréstimo.');
            }
        });
    }
}
