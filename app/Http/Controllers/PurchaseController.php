<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseRequest;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Services\AuditService;
use App\Services\BalanceService;
use App\Services\PurchaseListingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function index(Request $request, PurchaseListingService $purchaseListingService): Response
    {
        $selectedMonth = $this->validMonth($request->query('month'));
        $view = $request->query('view') === 'calendar' ? 'calendar' : 'invoice';
        $selfId = Participant::query()->where('is_default', true)->value('id');
        $purchaseListing = $purchaseListingService->forMonth($selectedMonth, $view, $selfId);
        $month = $purchaseListing['month'];
        $purchases = $purchaseListing['purchases'];
        $occurrences = $purchaseListing['occurrences'];
        $invoiceGroups = $purchaseListing['invoiceGroups'];

        return Inertia::render('Purchases/Index', [
            'monthLabel' => $view === 'invoice'
                ? 'Faturas com vencimento em '.ucfirst($month->locale('pt_BR')->translatedFormat('F \d\e Y'))
                : 'Movimentação de '.ucfirst($month->locale('pt_BR')->translatedFormat('F \d\e Y')),
            'selectedMonth' => $selectedMonth,
            'view' => $view,
            'monthTotalCents' => (int) ($view === 'invoice' ? $invoiceGroups->sum('totalCents') : $purchases->sum('amount_cents') + $occurrences->sum('amount_cents')),
            'purchases' => $view === 'calendar' ? $purchases->map(fn (Purchase $purchase): array => $purchaseListingService->purchaseData($purchase, $selfId))->values() : [],
            'occurrences' => $view === 'calendar' ? $occurrences->map(fn ($occurrence): array => $purchaseListingService->occurrenceData($occurrence))->values() : [],
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
}
