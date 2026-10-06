<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\PaymentMethodInvoiceSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PaymentMethodInvoiceSettingService
{
    public function __construct(private readonly InvoiceCycleService $invoiceCycleService) {}

    public function nextSafeEffectiveFrom(PaymentMethod $paymentMethod, ?CarbonInterface $referenceDate = null): CarbonImmutable
    {
        $referenceDate = CarbonImmutable::instance($referenceDate ?? now())->startOfDay();
        $currentSetting = $this->forDate($paymentMethod, $referenceDate);
        $closingDay = $currentSetting?->closing_day ?? $paymentMethod->closing_day;

        if ($closingDay === null) {
            return $referenceDate;
        }

        $nextCycleStart = $this->invoiceCycleService->closingDate($referenceDate, $closingDay)->addDay()->startOfDay();
        $latestScheduledSetting = $this->latestScheduled($paymentMethod);

        if ($latestScheduledSetting === null || $latestScheduledSetting->effective_from === null) {
            return $nextCycleStart;
        }

        $scheduledNextCycleStart = $this->invoiceCycleService
            ->closingDate($latestScheduledSetting->effective_from, $latestScheduledSetting->closing_day)
            ->addDay()
            ->startOfDay();

        return $scheduledNextCycleStart->greaterThan($nextCycleStart) ? $scheduledNextCycleStart : $nextCycleStart;
    }

    public function normalizeEffectiveFrom(PaymentMethod $paymentMethod, CarbonInterface $effectiveFrom): CarbonImmutable
    {
        $date = CarbonImmutable::instance($effectiveFrom)->startOfDay();
        $closingDay = $this->forDate($paymentMethod, $date)?->closing_day ?? $paymentMethod->closing_day;

        if ($closingDay === null) {
            return $date;
        }

        $closingDate = $this->invoiceCycleService->closingDate($date, $closingDay);
        $cycleStart = $this->invoiceCycleService->previousClosingDate($closingDate, $closingDay)->addDay()->startOfDay();

        return $cycleStart->lessThan($date) ? $closingDate->addDay()->startOfDay() : $cycleStart;
    }

    public function currentForDate(PaymentMethod $paymentMethod, ?CarbonInterface $date = null): ?PaymentMethodInvoiceSetting
    {
        return $this->forDate($paymentMethod, $date ?? now());
    }

    public function latestScheduled(PaymentMethod $paymentMethod): ?PaymentMethodInvoiceSetting
    {
        return $paymentMethod->invoiceSettings()
            ->whereNull('retired_at')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function forDate(PaymentMethod $paymentMethod, CarbonInterface $date): ?PaymentMethodInvoiceSetting
    {
        $settings = $this->settingsFor($paymentMethod);

        $orderedSettings = $settings
            ->sortByDesc('effective_from')
            ->values();
        $date = CarbonImmutable::instance($date)->startOfDay();
        $setting = $orderedSettings
            ->filter(fn (PaymentMethodInvoiceSetting $invoiceSetting): bool => $this->firstApplicableCycleStart($invoiceSetting, $orderedSettings)->lte($date))
            ->first()
            ?? $orderedSettings->sortBy('effective_from')->first();

        return $setting;
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
        $firstApplicableCycleStart = $setting?->effective_from?->lte($purchaseDate)
            ? $this->firstApplicableCycleStart($setting, $this->settingsFor($paymentMethod))
            : $periodStart;
        $dueDay = $this->dueDayFor($paymentMethod, $setting);

        return [
            'periodStart' => $firstApplicableCycleStart->greaterThan($periodStart) ? $firstApplicableCycleStart : $periodStart,
            'periodEnd' => $closingDate,
            'closingDate' => $closingDate,
            'dueDate' => $dueDay === null ? null : $this->invoiceCycleService->dueDate($closingDate, $dueDay),
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

    /** @param Collection<int, PaymentMethodInvoiceSetting> $settings */
    private function firstApplicableCycleStart(PaymentMethodInvoiceSetting $setting, Collection $settings): CarbonImmutable
    {
        $effectiveFrom = CarbonImmutable::instance($setting->effective_from)->startOfDay();
        $previousSetting = $settings
            ->filter(fn (PaymentMethodInvoiceSetting $candidate): bool => $candidate->effective_from?->lt($setting->effective_from))
            ->sortByDesc('effective_from')
            ->first();

        if ($previousSetting === null) {
            return $effectiveFrom;
        }

        $closingDate = $this->invoiceCycleService->closingDate($effectiveFrom, $previousSetting->closing_day);
        $cycleStart = $this->invoiceCycleService->previousClosingDate($closingDate, $previousSetting->closing_day)->addDay()->startOfDay();

        return $cycleStart->lessThan($effectiveFrom) ? $closingDate->addDay()->startOfDay() : $cycleStart;
    }

    private function dueDayFor(PaymentMethod $paymentMethod, ?PaymentMethodInvoiceSetting $setting): ?int
    {
        if ($setting?->due_day !== null) {
            return $setting->due_day;
        }

        $settings = $this->settingsFor($paymentMethod);

        // Migrated versions may not know the historical due day; the active version is the documented approximation.
        return $settings
            ->sortByDesc('effective_from')
            ->filter(fn (PaymentMethodInvoiceSetting $invoiceSetting): bool => $invoiceSetting->retired_at === null)
            ->filter(fn (PaymentMethodInvoiceSetting $invoiceSetting): bool => $this->firstApplicableCycleStart($invoiceSetting, $settings)->lte(now()))
            ->first()?->due_day;
    }

    /** @return Collection<int, PaymentMethodInvoiceSetting> */
    private function settingsFor(PaymentMethod $paymentMethod): Collection
    {
        return $paymentMethod->relationLoaded('invoiceSettings')
            ? $paymentMethod->invoiceSettings
            : $paymentMethod->invoiceSettings()->get();
    }
}
