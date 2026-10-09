<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInstallmentOccurrenceRequest;
use App\Models\AuditLog;
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
    public function edit(InstallmentOccurrence $installmentOccurrence, InstallmentCatalogService $catalogs, InstallmentAllocationService $allocationService, InstallmentService $service): Response
    {
        if ((int) $installmentOccurrence->installment_number === 1) {
            $installment = $installmentOccurrence->load('installment')->installment;
            $catalogData = $catalogs->all();
            $selfId = $catalogData['participants']->firstWhere('is_default', true)?->id;
            $allocationRows = collect($allocationService->rowsForExistingRule($installment, $installment->total_cents))
                ->map(fn (array $row): array => [
                    'participantId' => $row['participant_id'] ?? $selfId,
                    'categoryId' => $row['category_id'],
                    'amountCents' => (int) round(((float) ($row['amount'] ?? 0)) * 100),
                    'percentageBasisPoints' => ($row['percentage'] ?? null) === null ? null : (int) round(((float) $row['percentage']) * 100),
                ])
                ->values();

            return Inertia::render('Installments/Edit', [
                'installment' => [
                    'id' => $installment->id,
                    'startDate' => $installment->start_date->toDateString(),
                    'endDate' => CarbonImmutable::instance($installment->start_date)->addMonthsNoOverflow($installment->installment_count - 1)->toDateString(),
                    'totalCents' => $installment->total_cents,
                    'installmentCount' => $installment->installment_count,
                    'allocationMode' => $installment->allocation_mode,
                    'description' => $installment->description,
                    'cardName' => $installment->card_name,
                    'payerId' => $installment->payer_id,
                    'participantId' => $installment->participant_id,
                    'paymentMethodId' => $installment->payment_method_id,
                    'categoryId' => $installment->category_id,
                    'allocations' => $allocationRows,
                    'occurrences' => $installment->occurrences()->orderBy('installment_number')->get()->map(fn ($occurrence): array => [
                        'id' => $occurrence->id,
                        'number' => $occurrence->installment_number,
                        'purchasedAt' => $occurrence->purchased_at->toDateString(),
                        'amountCents' => $occurrence->amount_cents,
                        'isAdjusted' => $occurrence->is_adjusted,
                        'archivedAt' => $occurrence->archived_at?->toIso8601String(),
                    ])->values(),
                ],
                'schedulePreview' => $service->schedulePreview($installment),
                ...$catalogData,
            ]);
        }

        return Inertia::render('Installments/OccurrenceForm', [
            'occurrence' => $installmentOccurrence->load('installment:id,description,installment_count'),
        ]);
    }

    public function update(UpdateInstallmentOccurrenceRequest $request, InstallmentOccurrence $installmentOccurrence, InstallmentAllocationService $allocationService, BalanceService $balanceService, AuditService $audit): RedirectResponse
    {
        $oldValues = $installmentOccurrence->getAttributes();
        $oldAllocations = $installmentOccurrence->allocations()->orderBy('id')->get()->map(fn ($allocation): array => $allocation->getAttributes())->all();
        $amount = (int) round(((float) $request->validated('amount')) * 100);
        $installmentOccurrence->update(['amount_cents' => $amount, 'is_adjusted' => true]);
        $allocationService->redistribute($installmentOccurrence->fresh(), $amount);
        $balanceService->reconcileAll();
        $updatedOccurrence = $installmentOccurrence->fresh();
        $newValues = [
            ...$updatedOccurrence->getAttributes(),
            'allocations' => $updatedOccurrence->allocations()->orderBy('id')->get()->map(fn ($allocation): array => $allocation->getAttributes())->all(),
        ];
        $audit->record(
            AuditLog::ACTION_UPDATE,
            $installmentOccurrence,
            oldValues: [...$oldValues, 'allocations' => $oldAllocations],
            newValues: $newValues,
            metadata: ['type' => 'occurrence_adjustment', 'old_allocations' => $oldAllocations, 'new_allocations' => $newValues['allocations']],
        );

        return to_route('installments.index')->with('success', 'Parcela ajustada com sucesso.');
    }
}
