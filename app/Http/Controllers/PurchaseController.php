<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseRequest;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\RecurrenceOccurrence;
use App\Services\AuditService;
use App\Services\BalanceService;
use App\Services\InvoiceCycleService;
use App\Services\RecurrenceService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function index(Request $request, InvoiceCycleService $invoiceCycleService, RecurrenceService $recurrenceService): Response
    {
        $selectedMonth = $this->validMonth($request->query('month'));
        $view = $request->query('view') === 'invoice' ? 'invoice' : 'calendar';
        $month = Carbon::createFromFormat('Y-m', $selectedMonth);
        $periodStart = $view === 'invoice' ? $month->copy()->subMonthNoOverflow()->startOfMonth() : $month->copy()->startOfMonth();
        $periodEnd = $month->copy()->endOfMonth();
        $selfId = Participant::query()->where('is_default', true)->value('id');

        $recurrenceService->ensureOccurrencesForRange($periodStart, $periodEnd);

        $purchases = Purchase::query()
            ->active()
            ->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name,type,closing_day', 'category:id,name', 'allocations.participant:id,name', 'allocations' => fn ($query) => $query->with('category:id,name')])
            ->whereBetween('purchased_at', [$periodStart, $periodEnd])
            ->orderByDesc('purchased_at')
            ->get();
        $installmentOccurrences = InstallmentOccurrence::query()
            ->whereNull('archived_at')
            ->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name,type,closing_day', 'category:id,name', 'installment:id,installment_count'])
            ->whereBetween('purchased_at', [$periodStart, $periodEnd])
            ->orderByDesc('purchased_at')
            ->get();
        $recurrenceOccurrences = RecurrenceOccurrence::query()
            ->whereNull('archived_at')
            ->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name,type,closing_day', 'category:id,name', 'recurrence:id'])
            ->whereBetween('purchased_at', [$periodStart, $periodEnd])
            ->orderByDesc('purchased_at')
            ->get();
        $occurrences = $installmentOccurrences->concat($recurrenceOccurrences)->sortByDesc('purchased_at')->values();

        if ($view === 'invoice') {
            $purchases = $purchases->filter(fn (Purchase $purchase): bool => $this->belongsToInvoice($purchase, $selectedMonth, $invoiceCycleService))->values();
            $occurrences = $occurrences->filter(fn (InstallmentOccurrence|RecurrenceOccurrence $occurrence): bool => $this->belongsToInvoice($occurrence, $selectedMonth, $invoiceCycleService))->values();
        }

        $invoiceItems = $view === 'invoice'
            ? $purchases->map(fn (Purchase $purchase): array => $this->invoiceItem($purchase, $selfId, $invoiceCycleService))->concat($occurrences->map(fn (InstallmentOccurrence|RecurrenceOccurrence $occurrence): array => $this->invoiceItem($occurrence, $selfId, $invoiceCycleService)))
            : collect();
        $invoiceGroups = $invoiceItems
            ->groupBy(fn (array $item): string => $item['paymentMethodId'].'-'.$item['closingDate'])
            ->map(function ($group): array {
                $first = $group->first();

                return [
                    'paymentMethod' => $first['paymentMethod'],
                    'closingDate' => $first['closingDate'],
                    'totalCents' => (int) $group->sum(fn (array $item): int => $item['data']['amountCents']),
                    'purchases' => $group->map(fn (array $item): array => $item['data'])->values(),
                ];
            })
            ->sortBy('closingDate')
            ->values();

        return Inertia::render('Purchases/Index', [
            'monthLabel' => ucfirst($month->locale('pt_BR')->translatedFormat('F \d\e Y')),
            'selectedMonth' => $selectedMonth,
            'view' => $view,
            'monthTotalCents' => (int) ($view === 'invoice' ? $invoiceGroups->sum('totalCents') : $purchases->sum('amount_cents') + $occurrences->sum('amount_cents')),
            'purchases' => $view === 'calendar' ? $purchases->map(fn (Purchase $purchase): array => $this->purchaseData($purchase, $selfId))->values() : [],
            'occurrences' => $view === 'calendar' ? $occurrences->map(fn (InstallmentOccurrence|RecurrenceOccurrence $occurrence): array => $this->occurrenceData($occurrence))->values() : [],
            'invoiceGroups' => $invoiceGroups,
            'flash' => ['success' => session('success')],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Purchases/TypeSelector');
    }

    public function createSimple(): Response
    {
        return Inertia::render('Purchases/Form', ['purchase' => null, ...$this->catalogs()]);
    }

    public function store(StorePurchaseRequest $request, AuditService $audit): RedirectResponse
    {
        $purchase = Purchase::create($this->data($request->validated(), true));
        $audit->record('create', $purchase, newValues: $purchase->getAttributes());

        return to_route('purchases.allocations.edit', $purchase);
    }

    public function edit(Purchase $purchase): Response
    {
        return Inertia::render('Purchases/Form', [
            'purchase' => $purchase->load(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name', 'category:id,name']),
            ...$this->catalogs(),
        ]);
    }

    public function update(UpdatePurchaseRequest $request, Purchase $purchase, BalanceService $balanceService, AuditService $audit): RedirectResponse
    {
        $this->updateAndAudit($purchase, $this->data($request->validated()), 'update', $audit);
        $balanceService->reconcileAll();

        return to_route('dashboard');
    }

    public function archive(Purchase $purchase, AuditService $audit): RedirectResponse
    {
        $this->updateAndAudit($purchase, ['archived_at' => now()], 'archive', $audit);

        return to_route('dashboard')->with('success', 'Compra arquivada com sucesso.');
    }

    public function destroy(Purchase $purchase, AuditService $audit): RedirectResponse
    {
        $oldValues = $purchase->getAttributes();
        $purchase->delete();
        $audit->record(AuditLog::ACTION_DELETE, $purchase, oldValues: $oldValues);

        return to_route('dashboard')->with('success', 'Compra excluída com sucesso.');
    }

    public function restore(Purchase $purchase, AuditService $audit): RedirectResponse
    {
        $this->updateAndAudit($purchase, ['archived_at' => null], 'restore', $audit);

        return to_route('dashboard');
    }

    private function data(array $data, bool $creating = false): array
    {
        $data['amount_cents'] = (int) round(((float) $data['amount']) * 100);
        unset($data['amount']);

        if ($creating) {
            $data['participant_id'] = null;
            $data['category_id'] = null;
        } else {
            unset($data['participant_id'], $data['category_id']);
        }

        return $data;
    }

    /** @param array<string, mixed> $changes */
    private function updateAndAudit(Purchase $purchase, array $changes, string $action, AuditService $audit): void
    {
        $oldValues = $purchase->getAttributes();
        $purchase->update($changes);
        $audit->record($action, $purchase, $oldValues, $purchase->fresh()->getAttributes());
    }

    private function catalogs(): array
    {
        return [
            'participants' => Participant::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
        ];
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

    private function belongsToInvoice(Purchase|InstallmentOccurrence|RecurrenceOccurrence $item, string $selectedMonth, InvoiceCycleService $invoiceCycleService): bool
    {
        return $item->paymentMethod?->type === PaymentMethod::TYPE_CREDIT
            && $item->paymentMethod->closing_day
            && $invoiceCycleService->closingDate($item->purchased_at, $item->paymentMethod->closing_day)->format('Y-m') === $selectedMonth;
    }

    private function purchaseData(Purchase $purchase, ?int $selfId): array
    {
        return [
            'id' => $purchase->id,
            'origin' => $purchase->origin ?: Purchase::ORIGIN_MANUAL,
            'editUrl' => "/purchases/{$purchase->id}/edit",
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

    private function occurrenceData(InstallmentOccurrence|RecurrenceOccurrence $occurrence): array
    {
        $isInstallment = $occurrence instanceof InstallmentOccurrence;

        return [
            'id' => $occurrence->id,
            'origin' => $isInstallment ? Purchase::ORIGIN_INSTALLMENT : Purchase::ORIGIN_RECURRENCE,
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
