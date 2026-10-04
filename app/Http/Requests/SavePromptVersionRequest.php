<?php

namespace App\Http\Requests;

class SavePromptVersionRequest extends ExportSelectionRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'content' => ['required', 'string', 'max:200000'],
        ];
    }
}
