<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
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
            'total' => ['required', 'numeric', 'min:0.01'],
            'confirmation' => ['required', 'accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['total' => is_string($this->total) ? str_replace(',', '.', $this->total) : $this->total]);
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
