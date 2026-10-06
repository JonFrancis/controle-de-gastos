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
