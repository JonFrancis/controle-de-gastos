<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;

class InstallmentCatalogService
{
    /** @return array{participants: Collection<int, Participant>, categories: Collection<int, Category>, paymentMethods: Collection<int, PaymentMethod>} */
    public function all(): array
    {
        return [
            'participants' => Participant::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'type', 'closing_day']),
        ];
    }
}
