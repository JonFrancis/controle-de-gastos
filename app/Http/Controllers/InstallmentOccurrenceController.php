<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInstallmentOccurrenceRequest;
use App\Models\InstallmentOccurrence;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InstallmentOccurrenceController extends Controller
{
    public function edit(InstallmentOccurrence $installmentOccurrence): Response
    {
        return Inertia::render('Installments/OccurrenceForm', [
            'occurrence' => $installmentOccurrence->load('installment:id,description,installment_count'),
        ]);
    }

    public function update(UpdateInstallmentOccurrenceRequest $request, InstallmentOccurrence $installmentOccurrence): RedirectResponse
    {
        $amount = (int) round(((float) $request->validated('amount')) * 100);
        $installmentOccurrence->update(['amount_cents' => $amount, 'is_adjusted' => true]);

        return to_route('installments.index')->with('success', 'Parcela ajustada com sucesso.');
    }
}
