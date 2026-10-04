<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendToOpenAiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['content' => ['required', 'string', 'max:50000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'content.required' => 'Preencha o conteúdo antes de enviar à OpenAI.',
            'content.string' => 'O conteúdo para a OpenAI precisa ser texto.',
            'content.max' => 'O conteúdo para a OpenAI não pode passar de 50.000 caracteres.',
        ];
    }
}
