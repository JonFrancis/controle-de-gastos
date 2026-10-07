<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\PaymentMethodInvoiceSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class PaymentMethodInvoiceSettingService
{
    public function __construct(private readonly InvoiceCycleService $invoiceCycleService) {}

    public function nextSafeEffectiveFrom(PaymentMethod $paymentMethod, ?CarbonInterface $referenceDate = null): CarbonImmutable
    {
        $referenceDate = CarbonImmutable::instance($referenceDate ?? now())->startOfDay();
        $closingDay = $paymentMethod->latestInvoiceSetting?->closing_day ?? $paymentMethod->closing_day;

        if ($closingDay === null) {
            return $referenceDate;
        }

        return $this->invoiceCycleService->closingDate($referenceDate, $closingDay)->addDay()->startOfDay();
    }

    public function forDate(PaymentMethod $paymentMethod, CarbonInterface $date): ?PaymentMethodInvoiceSetting
    {
        $settings = $paymentMethod->relationLoaded('invoiceSettings')
            ? $paymentMethod->invoiceSettings
            : $paymentMethod->invoiceSettings()->get();

        $orderedSettings = $settings
            ->sortByDesc('effective_from')
            ->values();
        $setting = $orderedSettings
            ->filter(fn (PaymentMethodInvoiceSetting $invoiceSetting): bool => $invoiceSetting->effective_from?->startOfDay()->lte($date))
            ->first() ?? $orderedSettings->first();

        if ($setting?->due_day !== null) {
            return $setting;
        }

        // Migrated versions do not know the historical due day; the active version is the documented approximation.
        return $orderedSettings->first(fn (PaymentMethodInvoiceSetting $invoiceSetting): bool => $invoiceSetting->retired_at === null)
            ?? $setting;
    }

    /** @return array{periodStart: CarbonImmutable, periodEnd: CarbonImmutable, closingDate: CarbonImmutable, dueDate: CarbonImmutable|null}|null */
    public function detailsFor(PaymentMethod $paymentMethod, CarbonInterface $purchaseDate): ?array
    {
        if ($paymentMethod->type !== PaymentMethod::TYPE_CREDIT || $paymentMethod->closing_day === null) {
            return null;
        }

        $setting = $this->forDate($paymentMethod, $purchaseDate);
        $closingDay = $setting?->closing_day ?? $paymentMethod->closing_day;
        $closingDate = $this->invoiceCycleService->closingDate($purchaseDate, $closingDay);
        $previousClosingDate = $this->invoiceCycleService->previousClosingDate($closingDate, $closingDay);
        $periodStart = $previousClosingDate->addDay()->startOfDay();

        if ($setting?->effective_from !== null
            && $purchaseDate->greaterThanOrEqualTo($setting->effective_from)
            && $setting->effective_from->greaterThan($previousClosingDate)) {
            $periodStart = $setting->effective_from->toImmutable()->startOfDay();
        }

        return [
            'periodStart' => $periodStart,
            'periodEnd' => $closingDate,
            'closingDate' => $closingDate,
            'dueDate' => $setting?->due_day === null ? null : $this->invoiceCycleService->dueDate($closingDate, $setting->due_day),
        ];
    }

    public function createInitialVersion(PaymentMethod $paymentMethod, int $closingDay, ?int $dueDay): PaymentMethodInvoiceSetting
    {
        $effectiveFrom = $paymentMethod->created_at?->toDateString() ?? now()->toDateString();
        $existingSetting = $paymentMethod->invoiceSettings()->whereDate('effective_from', $effectiveFrom)->first();

        if ($existingSetting !== null) {
            $existingSetting->update(['closing_day' => $closingDay, 'due_day' => $dueDay]);

            return $existingSetting->fresh();
        }

        return $paymentMethod->invoiceSettings()->create([
            'closing_day' => $closingDay,
            'due_day' => $dueDay,
            'effective_from' => $effectiveFrom,
        ]);
    }
}
