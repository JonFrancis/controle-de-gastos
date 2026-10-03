<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePurchaseAllocationsRequest;
use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Services\PurchaseAllocationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseAllocationController extends Controller
{
    public function edit(Purchase $purchase): Response
    {
        return Inertia::render('Purchases/Allocations', [
            'purchase' => $purchase->load(['allocations.participant']),
            'participants' => Participant::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdatePurchaseAllocationsRequest $request, Purchase $purchase, PurchaseAllocationService $service): RedirectResponse
    {
        $service->save($purchase, $request->string('allocation_mode')->toString(), $request->validated('allocations'));

        return to_route('purchases.allocations.edit', $purchase);
    }
}
