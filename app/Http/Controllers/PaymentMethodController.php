<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentMethodRequest;
use App\Http\Requests\UpdatePaymentMethodRequest;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Services\AuditService;
use App\Services\PaymentMethodInvoiceSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

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
    public function store(StorePaymentMethodRequest $request, AuditService $audit, PaymentMethodInvoiceSettingService $invoiceSettings): RedirectResponse
    {
        $data = $request->validated();
        $paymentMethod = DB::transaction(function () use ($data, $invoiceSettings): PaymentMethod {
            $paymentMethod = PaymentMethod::create([
                'name' => $data['name'],
                'type' => $data['type'],
                'closing_day' => $data['type'] === PaymentMethod::TYPE_CREDIT ? ($data['closing_day'] ?? null) : null,
                'active' => true,
            ]);

            if ($paymentMethod->type === PaymentMethod::TYPE_CREDIT) {
                $invoiceSettings->createInitialVersion($paymentMethod, $data['closing_day'], $data['due_day']);
            }

            return $paymentMethod;
        });

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
    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod, AuditService $audit, PaymentMethodInvoiceSettingService $invoiceSettings): RedirectResponse
    {
        $oldValues = $paymentMethod->getAttributes();
        $data = $request->validated();
        $latestSetting = $paymentMethod->latestInvoiceSetting;
        $isCredit = $data['type'] === PaymentMethod::TYPE_CREDIT;

        if ($isCredit) {
            $effectiveFrom = CarbonImmutable::parse($data['effective_from']);
            $nextSafeEffectiveFrom = $invoiceSettings->nextSafeEffectiveFrom($paymentMethod);
            $latestEffectiveFrom = $latestSetting?->effective_from?->toImmutable();

            if ($effectiveFrom->lt($nextSafeEffectiveFrom)) {
                return to_route('settings.catalogs')->withErrors(['effective_from' => 'A vigência deve começar após o próximo fechamento seguro.']);
            }

            if ($latestEffectiveFrom !== null && $effectiveFrom->lessThanOrEqualTo($latestEffectiveFrom)) {
                return to_route('settings.catalogs')->withErrors(['effective_from' => 'A vigência deve ser posterior à última configuração.']);
            }
        }

        DB::transaction(function () use ($data, $isCredit, $latestSetting, $paymentMethod): void {
            $paymentMethod->update([
                'name' => $data['name'],
                'type' => $data['type'],
                'closing_day' => $isCredit ? ($data['closing_day'] ?? null) : null,
                'active' => $data['active'],
            ]);

            if ($isCredit && ($latestSetting === null || $latestSetting->closing_day !== (int) $data['closing_day'] || $latestSetting->due_day !== (int) $data['due_day'])) {
                $paymentMethod->invoiceSettings()->create([
                    'closing_day' => $data['closing_day'],
                    'due_day' => $data['due_day'],
                    'effective_from' => $data['effective_from'],
                ]);
            }

            if (! $isCredit) {
                $paymentMethod->invoiceSettings()->whereNull('retired_at')->update(['retired_at' => now()]);
            }
        });

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
