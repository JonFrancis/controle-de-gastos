<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentMethodRequest;
use App\Http\Requests\UpdatePaymentMethodRequest;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodInvoiceSetting;
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
        if ($paymentMethod->type === PaymentMethod::TYPE_CREDIT) {
            $invoiceSetting = $paymentMethod->invoiceSettings()->latest('id')->first();
            if ($invoiceSetting !== null) {
                $audit->record(AuditLog::ACTION_CREATE, $invoiceSetting, newValues: $this->invoiceSettingAuditValues($invoiceSetting), metadata: $this->invoiceSettingAuditMetadata($invoiceSetting));
            }
        }

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
        $data['type'] ??= $paymentMethod->type;
        $data['active'] ??= $paymentMethod->active;
        $isCredit = $data['type'] === PaymentMethod::TYPE_CREDIT;
        $wasCredit = $paymentMethod->type === PaymentMethod::TYPE_CREDIT;
        $hasInvoiceConfigurationInput = $this->hasInvoiceConfigurationInput($data);
        $currentSetting = $wasCredit && $hasInvoiceConfigurationInput ? $invoiceSettings->currentForDate($paymentMethod) : null;
        $invoiceConfigurationChanged = $this->invoiceConfigurationChanged($data, $paymentMethod, $currentSetting, $isCredit);
        $creditTypeChanged = $wasCredit !== $isCredit;
        $latestScheduledSetting = $invoiceConfigurationChanged && $isCredit
            ? $invoiceSettings->latestScheduled($paymentMethod)
            : null;

        if ($invoiceConfigurationChanged && $isCredit) {
            $effectiveFrom = CarbonImmutable::parse($data['effective_from']);
            $normalizedEffectiveFrom = $invoiceSettings->normalizeEffectiveFrom($paymentMethod, $effectiveFrom);
            $nextSafeEffectiveFrom = $invoiceSettings->nextSafeEffectiveFrom($paymentMethod);
            $latestEffectiveFrom = $latestScheduledSetting?->effective_from?->toImmutable();

            if ($normalizedEffectiveFrom->lt($nextSafeEffectiveFrom)) {
                return to_route('settings.catalogs')->withErrors(['effective_from' => 'A vigência deve começar após o próximo fechamento seguro.']);
            }

            if ($latestEffectiveFrom !== null && $normalizedEffectiveFrom->lessThanOrEqualTo($latestEffectiveFrom)) {
                return to_route('settings.catalogs')->withErrors(['effective_from' => 'A vigência deve ser posterior à última configuração.']);
            }

            $data['effective_from'] = $normalizedEffectiveFrom->toDateString();
        }

        $changes = DB::transaction(function () use ($data, $creditTypeChanged, $invoiceConfigurationChanged, $isCredit, $latestScheduledSetting, $paymentMethod): array {
            $paymentMethod->update([
                'name' => $data['name'],
                'type' => $data['type'],
                'closing_day' => $isCredit ? ($data['closing_day'] ?? $paymentMethod->closing_day) : null,
                'active' => $data['active'],
            ]);

            $createdSetting = null;
            if ($isCredit && $invoiceConfigurationChanged) {
                $createdSetting = $paymentMethod->invoiceSettings()->create([
                    'closing_day' => $data['closing_day'] ?? $paymentMethod->closing_day,
                    'due_day' => $data['due_day'] ?? $latestScheduledSetting?->due_day,
                    'effective_from' => $data['effective_from'],
                ]);
            }

            $retiredSettings = [];
            if ($creditTypeChanged && ! $isCredit) {
                $retiredAt = now();
                $retiredSettings = $paymentMethod->invoiceSettings()->whereNull('retired_at')->get()->all();
                $paymentMethod->invoiceSettings()->whereNull('retired_at')->update(['retired_at' => $retiredAt]);
                foreach ($retiredSettings as $retiredSetting) {
                    $retiredSetting->setAttribute('retired_at', $retiredAt);
                }
            }

            return ['created' => $createdSetting, 'retired' => $retiredSettings];
        });

        $audit->record(AuditLog::ACTION_UPDATE, $paymentMethod, oldValues: $oldValues, newValues: $paymentMethod->fresh()->getAttributes());
        if ($changes['created'] instanceof PaymentMethodInvoiceSetting) {
            $audit->record(AuditLog::ACTION_CREATE, $changes['created'], newValues: $this->invoiceSettingAuditValues($changes['created']), metadata: $this->invoiceSettingAuditMetadata($changes['created']));
        }
        foreach ($changes['retired'] as $retiredSetting) {
            $oldSettingValues = $this->invoiceSettingAuditValues($retiredSetting);
            $oldSettingValues['retired_at'] = null;
            $audit->record(AuditLog::ACTION_ARCHIVE, $retiredSetting, oldValues: $oldSettingValues, newValues: $this->invoiceSettingAuditValues($retiredSetting), metadata: $this->invoiceSettingAuditMetadata($retiredSetting));
        }

        return to_route('settings.catalogs');
    }

    /** @param array<string, mixed> $data */
    private function invoiceConfigurationChanged(array $data, PaymentMethod $paymentMethod, ?PaymentMethodInvoiceSetting $currentSetting, bool $isCredit): bool
    {
        if ($paymentMethod->type !== PaymentMethod::TYPE_CREDIT || ! $isCredit) {
            return $paymentMethod->type !== $data['type'];
        }

        if (! $this->hasInvoiceConfigurationInput($data)) {
            return false;
        }

        $closingDayChanged = array_key_exists('closing_day', $data)
            && ($data['closing_day'] === null ? null : (int) $data['closing_day']) !== $currentSetting?->closing_day;
        $dueDayChanged = array_key_exists('due_day', $data)
            && ($data['due_day'] === null ? null : (int) $data['due_day']) !== $currentSetting?->due_day;
        $effectiveFromChanged = array_key_exists('effective_from', $data)
            && filled($data['effective_from'])
            && $currentSetting?->effective_from?->toDateString() !== $data['effective_from'];

        return $currentSetting === null || $closingDayChanged || $dueDayChanged || $effectiveFromChanged;
    }

    /** @param array<string, mixed> $data */
    private function hasInvoiceConfigurationInput(array $data): bool
    {
        return array_key_exists('closing_day', $data)
            || array_key_exists('due_day', $data)
            || filled($data['effective_from'] ?? null);
    }

    /** @return array{type: string, closing_day: int|null, due_day: int|null, effective_from: string|null, retired_at: string|null} */
    private function invoiceSettingAuditMetadata(PaymentMethodInvoiceSetting $setting): array
    {
        return [
            'type' => 'payment_method_invoice_setting',
            'id' => $setting->id,
            'payment_method_id' => $setting->payment_method_id,
            'closing_day' => $setting->closing_day,
            'due_day' => $setting->due_day,
            'effective_from' => $setting->effective_from?->toDateString(),
            'retired_at' => $setting->retired_at?->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceSettingAuditValues(PaymentMethodInvoiceSetting $setting): array
    {
        return [
            ...$setting->getAttributes(),
            'closing_day' => $setting->closing_day,
            'due_day' => $setting->due_day,
            'effective_from' => $setting->effective_from?->toDateTimeString(),
            'retired_at' => $setting->retired_at?->toDateTimeString(),
        ];
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
