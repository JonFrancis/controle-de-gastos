<?php

namespace App\Services;

use App\Models\InstallmentOccurrence;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\RecurrenceOccurrence;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PurchaseListingService
{
    public function __construct(
        private readonly InvoiceCycleService $invoiceCycleService,
        private readonly PaymentMethodInvoiceSettingService $invoiceSettings,
        private readonly RecurrenceService $recurrenceService,
    ) {}

    /** @return array{month: Carbon, purchases: Collection<int, Purchase>, occurrences: Collection<int, InstallmentOccurrence|RecurrenceOccurrence>, invoiceGroups: Collection<int, array<string, mixed>>} */
    public function forMonth(string $selectedMonth, string $view, ?int $selfId): array
    {
        $month = Carbon::createFromFormat('Y-m', $selectedMonth);
        $periodStart = $view === 'invoice' ? $month->copy()->subMonthsNoOverflow(2)->startOfMonth() : $month->copy()->startOfMonth();
        $periodEnd = $month->copy()->endOfMonth();

        $this->recurrenceService->ensureOccurrencesForRange($periodStart, $periodEnd);

        $purchases = Purchase::query()
            ->active()
            ->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name,type,closing_day', 'paymentMethod.invoiceSettings:id,payment_method_id,closing_day,due_day,effective_from', 'category:id,name', 'allocations.participant:id,name', 'allocations' => fn ($query) => $query->with('category:id,name')])
            ->whereBetween('purchased_at', [$periodStart, $periodEnd])
            ->orderByDesc('purchased_at')
            ->get();
        $installmentOccurrences = InstallmentOccurrence::query()
            ->whereNull('archived_at')
            ->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name,type,closing_day', 'paymentMethod.invoiceSettings:id,payment_method_id,closing_day,due_day,effective_from', 'category:id,name', 'installment:id,installment_count'])
            ->whereBetween('purchased_at', [$periodStart, $periodEnd])
            ->orderByDesc('purchased_at')
            ->get();
        $recurrenceOccurrences = RecurrenceOccurrence::query()
            ->whereNull('archived_at')
            ->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name,type,closing_day', 'paymentMethod.invoiceSettings:id,payment_method_id,closing_day,due_day,effective_from', 'category:id,name', 'recurrence:id'])
            ->whereBetween('purchased_at', [$periodStart, $periodEnd])
            ->orderByDesc('purchased_at')
            ->get();
        $occurrences = $installmentOccurrences->concat($recurrenceOccurrences)->sortByDesc('purchased_at')->values();

        if ($view === 'invoice') {
            $purchases = $purchases->filter(fn (Purchase $purchase): bool => $this->belongsToInvoice($purchase, $selectedMonth))->values();
            $occurrences = $occurrences->filter(fn (InstallmentOccurrence|RecurrenceOccurrence $occurrence): bool => $this->belongsToInvoice($occurrence, $selectedMonth))->values();
        }

        $invoiceItems = $view === 'invoice'
            ? $purchases->map(fn (Purchase $purchase): array => $this->invoiceItem($purchase, $selfId))->concat($occurrences->map(fn (InstallmentOccurrence|RecurrenceOccurrence $occurrence): array => $this->invoiceItem($occurrence, $selfId)))
            : collect();
        $invoiceGroups = $invoiceItems
            ->groupBy(fn (array $item): string => $item['paymentMethodId'].'-'.$item['closingDate'])
            ->map(function (Collection $group): array {
                $first = $group->first();

                return [
                    'paymentMethod' => $first['paymentMethod'],
                    'paymentMethodId' => $first['paymentMethodId'],
                    'periodStart' => $first['periodStart'],
                    'periodEnd' => $first['periodEnd'],
                    'closingDate' => $first['closingDate'],
                    'dueDate' => $first['dueDate'],
                    'totalCents' => (int) $group->sum(fn (array $item): int => $item['data']['amountCents']),
                    'purchases' => $group->map(fn (array $item): array => $item['data'])->values(),
                ];
            })
            ->sortBy(fn (array $group): string => ($group['dueDate'] ?? $group['closingDate']).'-'.$group['paymentMethod'])
            ->values();

        return compact('month', 'purchases', 'occurrences', 'invoiceGroups');
    }

    /** @return array<string, mixed> */
    public function purchaseData(Purchase $purchase, ?int $selfId): array
    {
        return [
            'id' => $purchase->id,
            'type' => 'purchase',
            'origin' => $purchase->origin ?: Purchase::ORIGIN_MANUAL,
            'canDelete' => true,
            'editUrl' => route('purchases.edit', $purchase, false),
            'purchasedAt' => $purchase->purchased_at->toDateString(),
            'description' => $purchase->description,
            'cardName' => $purchase->card_name,
            'amountCents' => $purchase->amount_cents,
            'payer' => $purchase->payer?->name,
            'participant' => $purchase->allocations->isNotEmpty() ? $purchase->allocations->map(fn ($allocation): ?string => $allocation->participant?->name)->filter()->join(', ') : $purchase->participant?->name,
            'paymentMethod' => $purchase->paymentMethod?->name,
            'category' => $purchase->allocations->firstWhere('participant_id', $selfId)?->category?->name ?? $purchase->category?->name,
        ];
    }

    /** @return array<string, mixed> */
    public function occurrenceData(InstallmentOccurrence|RecurrenceOccurrence $occurrence): array
    {
        $isInstallment = $occurrence instanceof InstallmentOccurrence;

        return [
            'id' => $occurrence->id,
            'type' => 'occurrence',
            'origin' => $isInstallment ? Purchase::ORIGIN_INSTALLMENT : Purchase::ORIGIN_RECURRENCE,
            'canDelete' => false,
            'editUrl' => $isInstallment
                ? route('installment-occurrences.edit', $occurrence, false)
                : route('recurrence-occurrences.edit', $occurrence, false),
            'occurrenceNumber' => $isInstallment ? $occurrence->installment_number : null,
            'occurrenceCount' => $isInstallment ? $occurrence->installment->installment_count : null,
            'purchasedAt' => $occurrence->purchased_at->toDateString(),
            'description' => $occurrence->description,
            'cardName' => $occurrence->card_name,
            'amountCents' => $occurrence->amount_cents,
            'payer' => $occurrence->payer?->name,
            'participant' => $occurrence->participant?->name,
            'paymentMethod' => $occurrence->paymentMethod?->name,
            'category' => $occurrence->category?->name,
            'isAdjusted' => $occurrence->is_adjusted,
        ];
    }

    private function belongsToInvoice(Purchase|InstallmentOccurrence|RecurrenceOccurrence $item, string $selectedMonth): bool
    {
        $details = $this->invoiceDetails($item);

        return $details !== null
            && ($details['dueDate'] ?? $details['closingDate'])->format('Y-m') === $selectedMonth;
    }

    /** @return array<string, mixed> */
    private function invoiceItem(Purchase|InstallmentOccurrence|RecurrenceOccurrence $item, ?int $selfId): array
    {
        $details = $this->invoiceDetails($item);
        $data = $item instanceof Purchase ? $this->purchaseData($item, $selfId) : $this->occurrenceData($item);

        if ($item instanceof Purchase) {
            $data['addedAfterClosing'] = $item->created_at?->greaterThan($details['closingDate']->endOfDay()) ?? false;
        }

        return [
            'paymentMethodId' => $item->payment_method_id,
            'paymentMethod' => $item->paymentMethod->name,
            'periodStart' => $details['periodStart']->toDateString(),
            'periodEnd' => $details['periodEnd']->toDateString(),
            'closingDate' => $details['closingDate']->toDateString(),
            'dueDate' => $details['dueDate']?->toDateString(),
            'data' => $data,
        ];
    }

    /** @return array{periodStart: Carbon, periodEnd: Carbon, closingDate: Carbon, dueDate: Carbon|null}|null */
    private function invoiceDetails(Purchase|InstallmentOccurrence|RecurrenceOccurrence $item): ?array
    {
        if ($item->paymentMethod?->type !== PaymentMethod::TYPE_CREDIT || ! $item->paymentMethod->closing_day) {
            return null;
        }

        $setting = $this->invoiceSettings->forDate($item->paymentMethod, $item->purchased_at);
        $closingDay = $setting?->closing_day ?? $item->paymentMethod->closing_day;
        $closingDate = $this->invoiceCycleService->closingDate($item->purchased_at, $closingDay);
        $previousClosingDate = $this->invoiceCycleService->previousClosingDate($closingDate, $closingDay);
        $periodStart = $previousClosingDate->addDay()->startOfDay();

        if ($setting?->effective_from !== null
            && $item->purchased_at->greaterThanOrEqualTo($setting->effective_from)
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
}
