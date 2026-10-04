<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\RecurrenceOccurrence;
use App\Models\SpreadsheetImportRow;
use App\Services\BalanceService;
use App\Services\InvoiceCycleService;
use App\Services\RecurrenceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, InvoiceCycleService $invoiceCycleService, RecurrenceService $recurrenceService, BalanceService $balanceService): Response
    {
        $selectedMonth = $this->validMonth($request->query('month'));
        $view = $request->query('view') === 'invoice' ? 'invoice' : 'calendar';
        $month = Carbon::createFromFormat('Y-m', $selectedMonth);
        $selfId = Participant::query()->where('is_default', true)->value('id');
        $periodStart = $view === 'invoice' ? $month->copy()->subMonthNoOverflow()->startOfMonth() : $month->copy()->startOfMonth();
        $recurrenceService->ensureOccurrencesForRange($periodStart, $month->copy()->endOfMonth());
        $allPurchases = Purchase::query()->active()->with(['payer:id,name', 'participant:id,name', 'paymentMethod', 'category:id,name', 'allocations.participant:id,name', 'allocations' => fn ($query) => $query->with('category:id,name')])->whereBetween('purchased_at', [$periodStart, $month->copy()->endOfMonth()])->orderByDesc('purchased_at')->get();
        $allInstallmentOccurrences = InstallmentOccurrence::query()->whereNull('archived_at')->with(['payer:id,name', 'participant:id,name', 'paymentMethod', 'category:id,name', 'installment:id,installment_count'])->whereBetween('purchased_at', [$periodStart, $month->copy()->endOfMonth()])->orderByDesc('purchased_at')->get();
        $allRecurrenceOccurrences = RecurrenceOccurrence::query()->whereNull('archived_at')->with(['payer:id,name', 'participant:id,name', 'paymentMethod', 'category:id,name', 'recurrence:id'])->whereBetween('purchased_at', [$periodStart, $month->copy()->endOfMonth()])->orderByDesc('purchased_at')->get();
        $allOccurrences = $allInstallmentOccurrences->concat($allRecurrenceOccurrences)->sortByDesc('purchased_at')->values();
        $purchases = $view === 'invoice'
            ? $allPurchases->filter(fn (Purchase $purchase) => $purchase->paymentMethod?->type === PaymentMethod::TYPE_CREDIT && $purchase->paymentMethod->closing_day && $invoiceCycleService->closingDate($purchase->purchased_at, $purchase->paymentMethod->closing_day)->format('Y-m') === $selectedMonth)->values()
            : $allPurchases;
        $occurrences = $view === 'invoice'
            ? $allOccurrences->filter(fn (InstallmentOccurrence|RecurrenceOccurrence $occurrence) => $occurrence->paymentMethod?->type === PaymentMethod::TYPE_CREDIT && $occurrence->paymentMethod->closing_day && $invoiceCycleService->closingDate($occurrence->purchased_at, $occurrence->paymentMethod->closing_day)->format('Y-m') === $selectedMonth)->values()
            : $allOccurrences;
        $invoiceItems = $view === 'invoice'
            ? $purchases->map(fn (Purchase $purchase): array => $this->invoiceItem($purchase, $selfId, $invoiceCycleService))->concat($occurrences->map(fn (InstallmentOccurrence|RecurrenceOccurrence $occurrence): array => $this->invoiceItem($occurrence, $selfId, $invoiceCycleService)))
            : collect();
        $invoiceGroups = $invoiceItems->groupBy(fn (array $item) => $item['paymentMethodId'].'-'.$item['closingDate'])->map(function ($group): array {
            $first = $group->first();

            return [
                'paymentMethod' => $first['paymentMethod'],
                'closingDate' => $first['closingDate'],
                'totalCents' => (int) $group->sum(fn (array $item): int => $item['data']['amountCents']),
                'purchases' => $group->map(fn (array $item): array => $item['data'])->values(),
            ];
        })->values();

        return Inertia::render('Dashboard', [
            'monthLabel' => ucfirst($month->locale('pt_BR')->translatedFormat('F \d\e Y')),
            'selectedMonth' => $selectedMonth,
            'view' => $view,
            'summary' => $this->summaryData($balanceService->summary($month->copy()->endOfMonth(), $month->copy()->startOfMonth())),
            'pendingReview' => SpreadsheetImportRow::query()->where('status', 'pending_review')->count(),
            'monthTotalCents' => (int) ($view === 'invoice' ? $invoiceGroups->sum('totalCents') : $purchases->sum('amount_cents') + $occurrences->sum('amount_cents')),
            'purchases' => $view === 'calendar' ? $purchases->map(fn (Purchase $purchase) => $this->purchaseData($purchase, $selfId))->values() : [],
            'occurrences' => $view === 'calendar' ? $occurrences->map(fn (InstallmentOccurrence|RecurrenceOccurrence $occurrence) => $this->occurrenceData($occurrence))->values() : [],
            'invoiceGroups' => $invoiceGroups->values(),
            'catalogs' => [
                'participants' => Participant::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
                'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
                'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'type', 'closing_day']),
            ],
        ]);
    }

    /** @param array{ownConsumptionCents: int, paidForOthersCents: int, owedToOthersCents: int} $summary */
    private function summaryData(array $summary): array
    {
        return [
            'ownExpenses' => $this->formatMoney($summary['ownConsumptionCents']),
            'toReceive' => $this->formatMoney($summary['paidForOthersCents']),
            'owedToOthers' => $this->formatMoney($summary['owedToOthersCents']),
        ];
    }

    private function formatMoney(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    private function validMonth(mixed $value): string
    {
        $candidate = (string) $value;

        if (! preg_match('/^\d{4}-\d{2}$/', $candidate)) {
            return now()->format('Y-m');
        }

        try {
            $month = Carbon::createFromFormat('!Y-m', $candidate);
        } catch (\Throwable) {
            return now()->format('Y-m');
        }

        return $month->format('Y-m') === $candidate ? $candidate : now()->format('Y-m');
    }

    private function purchaseData(Purchase $purchase, ?int $selfId): array
    {
        return [
            'id' => $purchase->id,
            'origin' => $purchase->origin ?: 'manual',
            'editUrl' => "/purchases/{$purchase->id}/edit",
            'purchasedAt' => $purchase->purchased_at->toDateString(),
            'description' => $purchase->description,
            'cardName' => $purchase->card_name,
            'amountCents' => $purchase->amount_cents,
            'payer' => $purchase->payer?->name,
            'participant' => $purchase->allocations->isNotEmpty() ? $purchase->allocations->map(fn ($allocation) => $allocation->participant?->name)->filter()->join(', ') : $purchase->participant?->name,
            'paymentMethod' => $purchase->paymentMethod?->name,
            'category' => $purchase->allocations->firstWhere('participant_id', $selfId)?->category?->name ?? $purchase->category?->name,
        ];
    }

    private function occurrenceData(InstallmentOccurrence|RecurrenceOccurrence $occurrence): array
    {
        $isInstallment = $occurrence instanceof InstallmentOccurrence;

        return [
            'id' => $occurrence->id,
            'origin' => $isInstallment ? 'installment' : 'recurrence',
            'editUrl' => $isInstallment ? "/installment-occurrences/{$occurrence->id}/edit" : "/recurrence-occurrences/{$occurrence->id}/edit",
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

    private function invoiceItem(Purchase|InstallmentOccurrence|RecurrenceOccurrence $item, ?int $selfId, InvoiceCycleService $invoiceCycleService): array
    {
        $closingDate = $invoiceCycleService->closingDate($item->purchased_at, $item->paymentMethod->closing_day);

        return [
            'paymentMethodId' => $item->payment_method_id,
            'paymentMethod' => $item->paymentMethod->name,
            'closingDate' => $closingDate->toDateString(),
            'data' => $item instanceof Purchase ? $this->purchaseData($item, $selfId) : $this->occurrenceData($item),
        ];
    }
}
