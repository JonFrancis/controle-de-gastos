<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreParticipantRequest;
use App\Http\Requests\UpdateParticipantRequest;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Services\AuditService;
use App\Services\PaymentMethodInvoiceSettingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ParticipantController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(PaymentMethodInvoiceSettingService $invoiceSettings): Response
    {
        return Inertia::render('Settings/Catalogs', [
            'participants' => Participant::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'active', 'is_default']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name', 'active']),
            'paymentMethods' => PaymentMethod::query()->with(['latestInvoiceSetting', 'invoiceSettings'])->orderBy('name')->get(['id', 'name', 'type', 'closing_day', 'active'])->map(function (PaymentMethod $paymentMethod) use ($invoiceSettings): array {
                $latestSetting = $paymentMethod->latestInvoiceSetting;
                $isCredit = $paymentMethod->type === PaymentMethod::TYPE_CREDIT;

                return [
                    'id' => $paymentMethod->id,
                    'name' => $paymentMethod->name,
                    'type' => $paymentMethod->type,
                    'closing_day' => $isCredit ? ($latestSetting?->closing_day ?? $paymentMethod->closing_day) : null,
                    'due_day' => $isCredit ? $latestSetting?->due_day : null,
                    'effective_from' => $isCredit ? $latestSetting?->effective_from?->toDateString() : null,
                    'suggested_effective_from' => $isCredit && ($latestSetting?->closing_day ?? $paymentMethod->closing_day) !== null
                        ? $invoiceSettings->nextSafeEffectiveFrom($paymentMethod)->toDateString()
                        : null,
                    'invoice_settings' => $isCredit ? $paymentMethod->invoiceSettings->map(fn ($setting): array => [
                        'id' => $setting->id,
                        'closing_day' => $setting->closing_day,
                        'due_day' => $setting->due_day,
                        'effective_from' => $setting->effective_from->toDateString(),
                    ])->values()->all() : [],
                    'active' => $paymentMethod->active,
                ];
            })->values()->all(),
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
