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
        $occurrence = $recurrenceOccurrence->load(['recurrence:id,description', 'paymentMethod:id,type']);

        return Inertia::render('Recurrences/OccurrenceForm', [
            'occurrence' => [
                'id' => $occurrence->id,
                'purchased_at' => $occurrence->purchased_at->toDateString(),
                'due_date' => $occurrence->due_date?->toDateString(),
                'amount_cents' => $occurrence->amount_cents,
                'recurrence' => ['description' => $occurrence->recurrence->description],
                'payment_method_type' => $occurrence->paymentMethod?->type,
            ],
        ]);
    }

    public function update(UpdateRecurrenceOccurrenceRequest $request, RecurrenceOccurrence $recurrenceOccurrence, BalanceService $balanceService, AuditService $audit): RedirectResponse
    {
        $oldValues = $recurrenceOccurrence->getAttributes();
        $amount = (int) round(((float) $request->validated('amount')) * 100);
        $data = $request->validated();
        $recurrenceOccurrence->update([
            'amount_cents' => $amount,
            'due_date' => array_key_exists('due_date', $data) ? ($data['due_date'] ?: null) : $recurrenceOccurrence->due_date?->toDateString(),
            'is_adjusted' => true,
        ]);
        $balanceService->reconcileAll();
        $audit->record(AuditLog::ACTION_UPDATE, $recurrenceOccurrence, oldValues: $oldValues, newValues: $recurrenceOccurrence->fresh()->getAttributes());

        return to_route('recurrences.index')->with('success', 'Ocorrência recorrente ajustada com sucesso.');
    }
}
