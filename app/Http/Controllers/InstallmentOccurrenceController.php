<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInstallmentOccurrenceRequest;
use App\Models\AuditLog;
use App\Models\InstallmentAllocation;
use App\Models\InstallmentOccurrence;
use App\Services\AuditService;
use App\Services\BalanceService;
use App\Services\InstallmentAllocationService;
use App\Services\InstallmentCatalogService;
use App\Services\InstallmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InstallmentOccurrenceController extends Controller
{
    public function edit(InstallmentOccurrence $installmentOccurrence, InstallmentCatalogService $catalogs, InstallmentService $service, InstallmentAllocationService $allocations): Response
    {
        if ((int) $installmentOccurrence->installment_number === 1) {
            $installment = $installmentOccurrence->load('installment')->installment;
            $allocationRule = $allocations->editorRule($installment);

            return Inertia::render('Installments/Edit', [
                'installment' => [
                    'id' => $installment->id,
                    'startDate' => $installment->start_date->toDateString(),
                    'dueDate' => $installment->due_date?->toDateString(),
                    'endDate' => CarbonImmutable::instance($installment->start_date)->addMonthsNoOverflow($installment->installment_count - 1)->toDateString(),
                    'totalCents' => $installment->total_cents,
                    'installmentCount' => $installment->installment_count,
                    'description' => $installment->description,
                    'cardName' => $installment->card_name,
                    'payerId' => $installment->payer_id,
                    'participantId' => $installment->participant_id,
                    'paymentMethodId' => $installment->payment_method_id,
                    'categoryId' => $installment->category_id,
                    'allocationMode' => $allocationRule['mode'],
                    'allocations' => $allocationRule['allocations'],
                    'occurrences' => $installment->occurrences()->orderBy('installment_number')->get()->map(fn (InstallmentOccurrence $occurrence): array => [
                        'id' => $occurrence->id,
                        'number' => $occurrence->installment_number,
                        'purchasedAt' => $occurrence->purchased_at->toDateString(),
                        'dueDate' => $occurrence->due_date?->toDateString(),
                        'amountCents' => $occurrence->amount_cents,
                        'isAdjusted' => $occurrence->is_adjusted,
                        'archivedAt' => $occurrence->archived_at?->toIso8601String(),
                    ])->values(),
                ],
                'schedulePreview' => $service->schedulePreview($installment),
                ...$catalogs->all(),
            ]);
        }

        $occurrence = $installmentOccurrence->load(['installment:id,description,installment_count', 'paymentMethod:id,type']);

        return Inertia::render('Installments/OccurrenceForm', [
            'occurrence' => [
                'id' => $occurrence->id,
                'installment_number' => $occurrence->installment_number,
                'purchased_at' => $occurrence->purchased_at->toDateString(),
                'due_date' => $occurrence->due_date?->toDateString(),
                'amount_cents' => $occurrence->amount_cents,
                'installment' => [
                    'description' => $occurrence->installment->description,
                    'installment_count' => $occurrence->installment->installment_count,
                ],
                'payment_method_type' => $occurrence->paymentMethod?->type,
            ],
        ]);
    }

    public function update(UpdateInstallmentOccurrenceRequest $request, InstallmentOccurrence $installmentOccurrence, InstallmentAllocationService $allocations, BalanceService $balanceService, AuditService $audit): RedirectResponse
    {
        $oldValues = $installmentOccurrence->getAttributes();
        $oldAllocations = $installmentOccurrence->allocations()->get()->map(fn (InstallmentAllocation $allocation): array => $allocation->getAttributes())->all();
        $amount = (int) round(((float) $request->validated('amount')) * 100);
        $data = $request->validated();
        $installmentOccurrence->update([
            'amount_cents' => $amount,
            'due_date' => array_key_exists('due_date', $data) ? ($data['due_date'] ?: null) : $installmentOccurrence->due_date?->toDateString(),
            'is_adjusted' => true,
        ]);
        $allocations->redistributeOccurrence($installmentOccurrence);
        $balanceService->reconcileAll();
        $audit->record(AuditLog::ACTION_UPDATE, $installmentOccurrence, oldValues: $oldValues, newValues: $installmentOccurrence->fresh()->getAttributes(), metadata: [
            'type' => 'occurrence_adjustment',
            'old_allocations' => $oldAllocations,
            'new_allocations' => $installmentOccurrence->allocations()->get()->map(fn (InstallmentAllocation $allocation): array => $allocation->getAttributes())->all(),
            'balances_reconciled' => true,
        ]);

        return to_route('installments.index')->with('success', 'Parcela ajustada com sucesso.');
    }
}
