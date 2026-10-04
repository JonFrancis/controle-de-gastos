<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreParticipantRequest;
use App\Http\Requests\UpdateParticipantRequest;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ParticipantController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        return Inertia::render('Settings/Catalogs', [
            'participants' => Participant::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'active', 'is_default']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name', 'active']),
            'paymentMethods' => PaymentMethod::query()->orderBy('name')->get(['id', 'name', 'type', 'closing_day', 'active']),
            'paymentMethodTypes' => PaymentMethod::types(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreParticipantRequest $request, AuditService $audit): RedirectResponse
    {
        $participant = Participant::create([...$request->validated(), 'active' => true]);
        $audit->record(AuditLog::ACTION_CREATE, $participant, newValues: $participant->getAttributes());

        return to_route('settings.catalogs');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateParticipantRequest $request, Participant $participant, AuditService $audit): RedirectResponse
    {
        if ($participant->is_default) {
            return to_route('settings.catalogs')->withErrors(['name' => 'A pessoa Eu é obrigatória e não pode ser alterada.']);
        }

        $oldValues = $participant->getAttributes();
        $participant->update($request->validated());
        $audit->record(AuditLog::ACTION_UPDATE, $participant, oldValues: $oldValues, newValues: $participant->fresh()->getAttributes());

        return to_route('settings.catalogs');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Participant $participant, AuditService $audit): RedirectResponse
    {
        if ($participant->is_default) {
            return to_route('settings.catalogs')->withErrors(['name' => 'A pessoa Eu é obrigatória e não pode ser excluída.']);
        }

        $oldValues = $participant->getAttributes();
        $participant->delete();
        $audit->record(AuditLog::ACTION_DELETE, $participant, oldValues: $oldValues);

        return to_route('settings.catalogs');
    }
}
