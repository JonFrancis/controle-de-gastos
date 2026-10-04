<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBackupSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'automatic_backup_enabled' => ['sometimes', 'boolean'],
            'backup_path' => ['nullable', 'string', 'max:500'],
        ];
    }
}
