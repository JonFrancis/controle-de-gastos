<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateParticipantRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', Rule::unique('participants', 'name')->ignore($this->route('participant'))],
            'active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Já existe uma pessoa com este nome.'];
    }
}
