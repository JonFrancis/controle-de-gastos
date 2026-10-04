<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePurchaseAllocationsRequest;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Services\AuditService;
use App\Services\BalanceService;
use App\Services\PurchaseAllocationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseAllocationController extends Controller
{
    public function edit(Purchase $purchase): Response
    {
        return Inertia::render('Purchases/Allocations', [
            'purchase' => $purchase->load(['allocations.participant', 'allocations.category']),
            'participants' => Participant::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdatePurchaseAllocationsRequest $request, Purchase $purchase, PurchaseAllocationService $service, BalanceService $balanceService, AuditService $audit): RedirectResponse
    {
        $oldAllocations = $purchase->allocations()->get()->toArray();
        $service->save($purchase, $request->string('allocation_mode')->toString(), $request->validated('allocations'));
        $audit->record(AuditLog::ACTION_UPDATE, $purchase, oldValues: ['allocations' => $oldAllocations], newValues: ['allocations' => $purchase->fresh()->allocations()->get()->toArray()], metadata: ['type' => 'purchase_allocations']);
        $balanceService->reconcileAll();

        return to_route('dashboard')->with('success', 'Divisão salva com sucesso.');
    }
}
