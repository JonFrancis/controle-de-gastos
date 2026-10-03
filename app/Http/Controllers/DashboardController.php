<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Services\InvoiceCycleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, InvoiceCycleService $invoiceCycleService): Response
    {
        $selectedMonth = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : now()->format('Y-m');
        $view = $request->query('view') === 'invoice' ? 'invoice' : 'calendar';
        $month = Carbon::createFromFormat('Y-m', $selectedMonth);
        $selfId = Participant::query()->where('is_default', true)->value('id');
        $periodStart = $view === 'invoice' ? $month->copy()->subMonthNoOverflow()->startOfMonth() : $month->copy()->startOfMonth();
        $allPurchases = Purchase::query()->active()->with(['payer:id,name', 'participant:id,name', 'paymentMethod', 'category:id,name', 'allocations.participant:id,name', 'allocations' => fn ($query) => $query->with('category:id,name')])->whereBetween('purchased_at', [$periodStart, $month->copy()->endOfMonth()])->orderByDesc('purchased_at')->get();
        $purchases = $view === 'invoice'
            ? $allPurchases->filter(fn (Purchase $purchase) => $purchase->paymentMethod?->type === PaymentMethod::TYPE_CREDIT && $purchase->paymentMethod->closing_day && $invoiceCycleService->closingDate($purchase->purchased_at, $purchase->paymentMethod->closing_day)->format('Y-m') === $selectedMonth)->values()
            : $allPurchases;
        $invoiceGroups = $view === 'invoice' ? $purchases->groupBy(fn (Purchase $purchase) => $purchase->payment_method_id.'-'.$invoiceCycleService->closingDate($purchase->purchased_at, $purchase->paymentMethod->closing_day)->toDateString())->map(function ($group) use ($selfId, $invoiceCycleService): array {
            $first = $group->first();
            $closingDate = $invoiceCycleService->closingDate($first->purchased_at, $first->paymentMethod->closing_day);

            return [
                'paymentMethod' => $first->paymentMethod->name,
                'closingDate' => $closingDate->toDateString(),
                'totalCents' => (int) $group->sum('amount_cents'),
                'purchases' => $group->map(fn (Purchase $purchase) => $this->purchaseData($purchase, $selfId))->values(),
            ];
        })->values() : collect();

        return Inertia::render('Dashboard', [
            'monthLabel' => ucfirst($month->locale('pt_BR')->translatedFormat('F \d\e Y')),
            'selectedMonth' => $selectedMonth,
            'view' => $view,
            'summary' => ['ownExpenses' => 'R$ 0,00', 'toReceive' => 'R$ 0,00', 'salaryRemaining' => 'R$ 0,00'],
            'pendingReview' => 0,
            'monthTotalCents' => (int) ($view === 'invoice' ? $invoiceGroups->sum('totalCents') : $purchases->sum('amount_cents')),
            'purchases' => $view === 'calendar' ? $purchases->map(fn (Purchase $purchase) => $this->purchaseData($purchase, $selfId))->values() : [],
            'invoiceGroups' => $invoiceGroups->values(),
            'catalogs' => [
                'participants' => Participant::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
                'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
                'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'type', 'closing_day']),
            ],
        ]);
    }

    private function purchaseData(Purchase $purchase, ?int $selfId): array
    {
        return [
            'id' => $purchase->id,
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
}
