<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRecurrenceOccurrenceRequest;
use App\Models\RecurrenceOccurrence;
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

    public function update(UpdateRecurrenceOccurrenceRequest $request, RecurrenceOccurrence $recurrenceOccurrence, BalanceService $balanceService): RedirectResponse
    {
        $amount = (int) round(((float) $request->validated('amount')) * 100);
        $recurrenceOccurrence->update(['amount_cents' => $amount, 'is_adjusted' => true]);
        $balanceService->reconcileAll();

        return to_route('recurrences.index')->with('success', 'Ocorrência recorrente ajustada com sucesso.');
    }
}
