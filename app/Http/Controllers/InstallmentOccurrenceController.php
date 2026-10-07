<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInstallmentOccurrenceRequest;
use App\Models\AuditLog;
use App\Models\InstallmentOccurrence;
use App\Services\AuditService;
use App\Services\BalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InstallmentOccurrenceController extends Controller
{
    public function edit(InstallmentOccurrence $installmentOccurrence): Response
    {
        if ((int) $installmentOccurrence->installment_number === 1) {
            $installment = $installmentOccurrence->load('installment')->installment;

            return Inertia::render('Installments/Edit', [
                'installment' => [
                    'id' => $installment->id,
                    'startDate' => $installment->start_date->toDateString(),
                    'endDate' => CarbonImmutable::instance($installment->start_date)->addMonthsNoOverflow($installment->installment_count - 1)->toDateString(),
                    'totalCents' => $installment->total_cents,
                    'installmentCount' => $installment->installment_count,
                    'description' => $installment->description,
                ],
            ]);
        }

        return Inertia::render('Installments/OccurrenceForm', [
            'occurrence' => $installmentOccurrence->load('installment:id,description,installment_count'),
        ]);
    }

    public function update(UpdateInstallmentOccurrenceRequest $request, InstallmentOccurrence $installmentOccurrence, BalanceService $balanceService, AuditService $audit): RedirectResponse
    {
        $oldValues = $installmentOccurrence->getAttributes();
        $amount = (int) round(((float) $request->validated('amount')) * 100);
        $installmentOccurrence->update(['amount_cents' => $amount, 'is_adjusted' => true]);
        $balanceService->reconcileAll();
        $audit->record(AuditLog::ACTION_UPDATE, $installmentOccurrence, oldValues: $oldValues, newValues: $installmentOccurrence->fresh()->getAttributes());

        return to_route('installments.index')->with('success', 'Parcela ajustada com sucesso.');
    }
}
