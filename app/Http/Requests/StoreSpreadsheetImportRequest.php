<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSpreadsheetImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array{file: list<string>} */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt,tsv,xlsx', 'extensions:csv,txt,tsv,xlsx', 'max:10240'],
        ];
    }
}
