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
    public function edit(InstallmentOccurrence $installmentOccurrence, InstallmentCatalogService $catalogs, InstallmentService $service): Response
    {
        if ((int) $installmentOccurrence->installment_number === 1) {
            $installment = $installmentOccurrence->load('installment')->installment;
            $allocationRows = $installment->occurrences()
                ->whereNull('archived_at')
                ->with(['allocations.participant:id,name', 'allocations.category:id,name'])
                ->orderBy('installment_number')
                ->get()
                ->flatMap(fn ($occurrence) => $occurrence->allocations)
                ->groupBy(fn ($allocation): string => $allocation->participant_id === null ? 'self' : (string) $allocation->participant_id)
                ->map(fn ($allocations): object => $allocations->first()->setAttribute('amount_cents', (int) $allocations->sum('amount_cents')))
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
                    'allocations' => $allocationRows->map(fn ($allocation): array => [
                        'participantId' => $allocation->participant_id,
                        'categoryId' => $allocation->category_id,
                        'amountCents' => $allocation->amount_cents,
                        'percentageBasisPoints' => $allocation->percentage_basis_points,
                    ])->values(),
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
                ...$catalogs->all(),
            ]);
        }

        return Inertia::render('Installments/OccurrenceForm', [
            'occurrence' => $installmentOccurrence->load('installment:id,description,installment_count'),
        ]);
    }

    public function update(UpdateInstallmentOccurrenceRequest $request, InstallmentOccurrence $installmentOccurrence, InstallmentAllocationService $allocationService, BalanceService $balanceService, AuditService $audit): RedirectResponse
    {
        $oldValues = $installmentOccurrence->getAttributes();
        $amount = (int) round(((float) $request->validated('amount')) * 100);
        $installmentOccurrence->update(['amount_cents' => $amount, 'is_adjusted' => true]);
        $allocationService->redistribute($installmentOccurrence->fresh(), $amount);
        $balanceService->reconcileAll();
        $audit->record(AuditLog::ACTION_UPDATE, $installmentOccurrence, oldValues: $oldValues, newValues: $installmentOccurrence->fresh()->getAttributes());

        return to_route('installments.index')->with('success', 'Parcela ajustada com sucesso.');
    }
}
