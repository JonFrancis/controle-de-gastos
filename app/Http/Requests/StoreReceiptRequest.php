<?php

namespace App\Http\Requests;

use App\Models\Participant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReceiptRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => str_replace(',', '.', (string) $this->input('amount'))]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('participant_id') && Participant::query()->whereKey($this->input('participant_id'))->where('is_default', true)->exists()) {
                $validator->errors()->add('participant_id', 'Selecione um participante diferente de Eu.');
            }
        });
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'participant_id' => ['required', 'integer', Rule::exists('participants', 'id')->where('active', true)],
            'received_at' => ['required', 'date'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
