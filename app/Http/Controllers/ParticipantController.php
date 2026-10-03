<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreParticipantRequest;
use App\Http\Requests\UpdateParticipantRequest;
use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
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
            'participants' => Participant::query()->orderBy('name')->get(['id', 'name', 'active']),
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
    public function store(StoreParticipantRequest $request): RedirectResponse
    {
        Participant::create([...$request->validated(), 'active' => true]);

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
    public function update(UpdateParticipantRequest $request, Participant $participant): RedirectResponse
    {
        $participant->update($request->validated());

        return to_route('settings.catalogs');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
