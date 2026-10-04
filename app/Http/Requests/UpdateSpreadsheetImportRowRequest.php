<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpreadsheetImportRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array{action: list<string>, purchased_at: list<string>, description: list<string>, card_name: list<string>, amount: list<string>, payer_id: list<string>, participant_id: list<string>, payment_method_id: list<string>, category_id: list<string>, origin: list<string>} */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approved,rejected,pending_review'],
            'purchased_at' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:160'],
            'card_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'amount' => ['sometimes', 'nullable', 'numeric'],
            'payer_id' => ['sometimes', 'nullable', 'integer'],
            'participant_id' => ['sometimes', 'nullable', 'integer'],
            'payment_method_id' => ['sometimes', 'nullable', 'integer'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'origin' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }
}
