<?php

namespace App\Services;

use App\Models\InstallmentOccurrence;
use App\Models\Participant;
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
    public function forMonth(string $selectedMonth, string $view, ?int $selfId, ?int $paymentMethodId = null, ?string $closingDate = null): array
    {
        $month = Carbon::createFromFormat('Y-m', $selectedMonth);
        $range = $view === 'invoice'
            ? $this->invoiceCycleService->sourceRangeForInvoiceMonth($month)
            : ['start' => $month->copy()->startOfMonth(), 'end' => $month->copy()->endOfMonth()];
        $periodStart = $range['start'];
        $periodEnd = $range['end'];

        $this->recurrenceService->ensureOccurrencesForRange($periodStart, $periodEnd);

        $purchases = Purchase::query()
            ->active()
            ->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name,type,closing_day', 'paymentMethod.invoiceSettings:id,payment_method_id,closing_day,due_day,effective_from', 'category:id,name', 'allocations.participant:id,name', 'allocations' => fn ($query) => $query->with('category:id,name')])
            ->whereBetween('purchased_at', [$periodStart, $periodEnd])
            ->orderByDesc('purchased_at')
            ->get();
        $installmentOccurrences = InstallmentOccurrence::query()
            ->whereNull('archived_at')
            ->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name,type,closing_day', 'paymentMethod.invoiceSettings:id,payment_method_id,closing_day,due_day,effective_from', 'category:id,name', 'installment:id,installment_count', 'allocations.participant:id,name', 'allocations.category:id,name'])
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

        if ($paymentMethodId !== null) {
            $invoiceItems = $invoiceItems->filter(fn (array $item): bool => (int) $item['paymentMethodId'] === $paymentMethodId);
        }

        if ($closingDate !== null) {
            $invoiceItems = $invoiceItems->filter(fn (array $item): bool => $item['closingDate'] === $closingDate);
        }

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
    public function occurrenceData(InstallmentOccurrence|RecurrenceOccurrence $occurrence, ?int $selfId = null): array
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
            'participant' => $isInstallment && $occurrence->allocations->isNotEmpty()
                ? $occurrence->allocations->map(fn ($allocation): ?string => $allocation->participant?->name)->filter()->join(', ')
                : $occurrence->participant?->name,
            'paymentMethod' => $occurrence->paymentMethod?->name,
            'category' => $isInstallment
                ? $occurrence->allocations->firstWhere('participant_id', $selfId ?? $this->selfId())?->category?->name ?? $occurrence->category?->name
                : $occurrence->category?->name,
            'allocations' => $isInstallment ? $occurrence->allocations->map(fn ($allocation): array => [
                'participantId' => $allocation->participant_id,
                'participant' => $allocation->participant?->name,
                'categoryId' => $allocation->category_id,
                'category' => $allocation->category?->name,
                'amountCents' => $allocation->amount_cents,
            ])->values()->all() : [],
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
        $data = $item instanceof Purchase ? $this->purchaseData($item, $selfId) : $this->occurrenceData($item, $selfId);

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
        return $item->paymentMethod === null
            ? null
            : $this->invoiceSettings->detailsFor($item->paymentMethod, $item->purchased_at);
    }

    private function selfId(): int
    {
        return (int) Participant::query()->where('is_default', true)->value('id');
    }
}
