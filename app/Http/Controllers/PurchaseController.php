<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseRequest;
use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Purchases/Form', ['purchase' => null, ...$this->catalogs()]);
    }

    public function store(StorePurchaseRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $openAllocation = (bool) ($data['open_allocation'] ?? false);
        $purchase = Purchase::create($this->data($data));

        return $openAllocation ? to_route('purchases.allocations.edit', $purchase) : to_route('dashboard');
    }

    public function edit(Purchase $purchase): Response
    {
        return Inertia::render('Purchases/Form', [
            'purchase' => $purchase->load(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name', 'category:id,name']),
            ...$this->catalogs(),
        ]);
    }

    public function update(UpdatePurchaseRequest $request, Purchase $purchase): RedirectResponse
    {
        $purchase->update($this->data($request->validated()));

        return to_route('dashboard');
    }

    public function destroy(Purchase $purchase): RedirectResponse
    {
        $purchase->update(['archived_at' => now()]);

        return to_route('dashboard');
    }

    public function restore(Purchase $purchase): RedirectResponse
    {
        $purchase->update(['archived_at' => null]);

        return to_route('dashboard');
    }

    private function data(array $data): array
    {
        $data['amount_cents'] = (int) round(((float) $data['amount']) * 100);
        $selfId = Participant::query()->where('is_default', true)->value('id');
        $data['category_id'] = (int) $data['participant_id'] === (int) $selfId ? ($data['category_id'] ?? null) : null;
        unset($data['amount']);
        unset($data['open_allocation']);

        return $data;
    }

    private function catalogs(): array
    {
        return [
            'participants' => Participant::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
