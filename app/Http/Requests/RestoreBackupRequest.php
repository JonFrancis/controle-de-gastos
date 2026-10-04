<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RestoreBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array{backup: list<string>, confirmation: list<string>} */
    public function rules(): array
    {
        return [
            'backup' => ['required', 'file', 'max:51200', 'mimes:json', 'extensions:json'],
            'confirmation' => ['accepted'],
        ];
    }
}
