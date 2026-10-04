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
        return Inertia::render('Purchases/TypeSelector');
    }

    public function createSimple(): Response
    {
        return Inertia::render('Purchases/Form', ['purchase' => null, ...$this->catalogs()]);
    }

    public function store(StorePurchaseRequest $request): RedirectResponse
    {
        $purchase = Purchase::create($this->data($request->validated(), true));

        return to_route('purchases.allocations.edit', $purchase);
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

    public function archive(Purchase $purchase): RedirectResponse
    {
        $purchase->update(['archived_at' => now()]);

        return to_route('dashboard')->with('success', 'Compra arquivada com sucesso.');
    }

    public function destroy(Purchase $purchase): RedirectResponse
    {
        $purchase->delete();

        return to_route('dashboard')->with('success', 'Compra excluída com sucesso.');
    }

    public function restore(Purchase $purchase): RedirectResponse
    {
        $purchase->update(['archived_at' => null]);

        return to_route('dashboard');
    }

    private function data(array $data, bool $creating = false): array
    {
        $data['amount_cents'] = (int) round(((float) $data['amount']) * 100);
        unset($data['amount']);

        if ($creating) {
            $data['participant_id'] = null;
            $data['category_id'] = null;
        } else {
            unset($data['participant_id'], $data['category_id']);
        }

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
