<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePaymentMethodRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', Rule::unique('payment_methods', 'name')->ignore($this->route('payment_method'))],
            'type' => ['sometimes', Rule::in(PaymentMethod::types())],
            'closing_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'due_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $paymentMethod = $this->route('payment_method');

            if (! $paymentMethod instanceof PaymentMethod || $validator->errors()->has('type')) {
                return;
            }

            $requestedType = $this->input('type', $paymentMethod->type);
            $isCredit = $requestedType === PaymentMethod::TYPE_CREDIT;
            $wasCredit = $paymentMethod->type === PaymentMethod::TYPE_CREDIT;

            if (! $isCredit || ! $wasCredit) {
                if ($isCredit) {
                    $this->requireInvoiceConfiguration($validator);
                }

                return;
            }

            if (! $this->hasInvoiceConfigurationInput()) {
                return;
            }

            $currentSetting = $paymentMethod->invoiceSettings()
                ->whereNull('retired_at')
                ->whereDate('effective_from', '<=', today())
                ->orderByDesc('effective_from')
                ->first();
            $invoiceConfigurationChanged = $currentSetting === null
                || (array_key_exists('closing_day', $this->all()) && ($this->input('closing_day') === null ? null : (int) $this->input('closing_day')) !== $currentSetting->closing_day)
                || (array_key_exists('due_day', $this->all()) && ($this->input('due_day') === null ? null : (int) $this->input('due_day')) !== $currentSetting->due_day)
                || (array_key_exists('effective_from', $this->all()) && $currentSetting->effective_from?->toDateString() !== $this->input('effective_from'));

            if ($invoiceConfigurationChanged) {
                $this->requireInvoiceConfiguration($validator);
            }
        });
    }

    private function hasInvoiceConfigurationInput(): bool
    {
        return array_key_exists('closing_day', $this->all())
            || array_key_exists('due_day', $this->all())
            || $this->filled('effective_from');
    }

    private function requireInvoiceConfiguration(Validator $validator): void
    {
        if ($this->input('closing_day') === null) {
            $validator->errors()->add('closing_day', 'Informe o dia de fechamento do cartão de crédito.');
        }

        if ($this->input('due_day') === null) {
            $validator->errors()->add('due_day', 'Informe o dia de vencimento do cartão de crédito.');
        }

        if ($this->input('effective_from') === null) {
            $validator->errors()->add('effective_from', 'Informe a data de vigência da nova configuração.');
        }
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Já existe uma forma de pagamento com este nome.',
            'closing_day.required' => 'Informe o dia de fechamento do cartão de crédito.',
            'due_day.required' => 'Informe o dia de vencimento do cartão de crédito.',
            'effective_from.required' => 'Informe a data de vigência da nova configuração.',
        ];
    }
}
