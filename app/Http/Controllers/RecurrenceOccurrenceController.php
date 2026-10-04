<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRecurrenceOccurrenceRequest;
use App\Models\AuditLog;
use App\Models\RecurrenceOccurrence;
use App\Services\AuditService;
use App\Services\BalanceService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RecurrenceOccurrenceController extends Controller
{
    public function edit(RecurrenceOccurrence $recurrenceOccurrence): Response
    {
        return Inertia::render('Recurrences/OccurrenceForm', [
            'occurrence' => $recurrenceOccurrence->load('recurrence:id,description'),
        ]);
    }

    public function update(UpdateRecurrenceOccurrenceRequest $request, RecurrenceOccurrence $recurrenceOccurrence, BalanceService $balanceService, AuditService $audit): RedirectResponse
    {
        $oldValues = $recurrenceOccurrence->getAttributes();
        $amount = (int) round(((float) $request->validated('amount')) * 100);
        $recurrenceOccurrence->update(['amount_cents' => $amount, 'is_adjusted' => true]);
        $balanceService->reconcileAll();
        $audit->record(AuditLog::ACTION_UPDATE, $recurrenceOccurrence, oldValues: $oldValues, newValues: $recurrenceOccurrence->fresh()->getAttributes());

        return to_route('recurrences.index')->with('success', 'Ocorrência recorrente ajustada com sucesso.');
    }
}
