<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RestoreBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'backup' => ['required', 'file', 'max:51200', 'extensions:json'],
            'confirmation' => ['accepted'],
        ];
    }
}
