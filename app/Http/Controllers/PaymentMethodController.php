<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentMethodRequest;
use App\Http\Requests\UpdatePaymentMethodRequest;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;

class PaymentMethodController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function store(StorePaymentMethodRequest $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $data['active'] = true;
        $data['closing_day'] = $data['type'] === PaymentMethod::TYPE_CREDIT ? ($data['closing_day'] ?? null) : null;
        $paymentMethod = PaymentMethod::create($data);
        $audit->record(AuditLog::ACTION_CREATE, $paymentMethod, newValues: $paymentMethod->getAttributes());

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
    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod, AuditService $audit): RedirectResponse
    {
        $oldValues = $paymentMethod->getAttributes();
        $data = $request->validated();
        $data['closing_day'] = $data['type'] === PaymentMethod::TYPE_CREDIT ? ($data['closing_day'] ?? null) : null;
        $paymentMethod->update($data);
        $audit->record(AuditLog::ACTION_UPDATE, $paymentMethod, oldValues: $oldValues, newValues: $paymentMethod->fresh()->getAttributes());

        return to_route('settings.catalogs');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PaymentMethod $paymentMethod, AuditService $audit): RedirectResponse
    {
        $oldValues = $paymentMethod->getAttributes();
        $paymentMethod->delete();
        $audit->record(AuditLog::ACTION_DELETE, $paymentMethod, oldValues: $oldValues);

        return to_route('settings.catalogs');
    }
}
