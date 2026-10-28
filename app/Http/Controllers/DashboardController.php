<?php

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Models\Receipt;
use App\Models\SpreadsheetImportRow;
use App\Services\BalanceService;
use App\Services\MonthlyAnalysisService;
use App\Services\PurchaseListingService;
use App\Services\RecurrenceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MonthlyAnalysisService $monthlyAnalysisService, BalanceService $balanceService, RecurrenceService $recurrenceService, PurchaseListingService $purchaseListingService): Response
    {
        $selectedMonth = $this->validMonth($request->query('month'));
        $view = $request->query('view') === 'invoice' ? 'invoice' : 'calendar';
        $tab = $request->query('tab') === 'personal' ? 'personal' : 'overview';
        $month = Carbon::createFromFormat('Y-m', $selectedMonth);
        $recurrenceService->ensureOccurrencesForRange($month->copy()->startOfMonth(), $month->copy()->endOfMonth());
        $movementCategoryId = $request->integer('category') ?: null;
        $budgetAnalysis = $monthlyAnalysisService->analyze($selectedMonth, $view, $movementCategoryId);
        $personalAnalysis = $monthlyAnalysisService->analyze($selectedMonth, 'calendar');
        $budgetSummary = $budgetAnalysis['summary'];
        $totalReceiptsCents = (int) Receipt::query()
            ->whereNull('archived_at')
            ->whereBetween('received_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->sum('amount_cents');
        $recentReceipts = Receipt::query()
            ->whereNull('archived_at')
            ->with('participant:id,name')
            ->whereBetween('received_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->orderByDesc('received_at')
            ->limit(8)
            ->get()
            ->map(fn (Receipt $receipt): array => [
                'key' => 'receipt:'.$receipt->id,
                'date' => $receipt->received_at->toDateString(),
                'description' => $receipt->note ?: 'Recebimento',
                'categoryName' => null,
                'amountCents' => $receipt->amount_cents,
                'kind' => 'receipt',
                'paymentMethodName' => null,
                'participantName' => $receipt->participant?->name,
                'isOwn' => false,
                'pending' => false,
            ]);
        $recentMovements = collect($budgetAnalysis['purchaseReview'])
            ->map(fn (array $purchase): array => [
                'key' => $purchase['key'],
                'date' => $purchase['date'],
                'description' => $purchase['description'],
                'categoryName' => $purchase['categoryName'],
                'amountCents' => $purchase['amountCents'],
                'kind' => 'expense',
                'paymentMethodName' => $purchase['paymentMethodName'],
                'participantName' => $purchase['participantName'],
                'isOwn' => $purchase['isOwn'],
                'pending' => $purchase['pending'],
            ])
            ->concat($recentReceipts)
            ->sortByDesc('date')
            ->take(6)
            ->values()
            ->all();
        $pendingReviewQuery = SpreadsheetImportRow::query()->where('status', 'pending_review');
        $pendingReview = (clone $pendingReviewQuery)->count();
        $personBalances = collect($balanceService->participantBalances($month->copy()->endOfMonth(), $month->copy()->startOfMonth()))
            ->filter(fn (array $balance): bool => $balance['hasMovement'])
            ->map(fn (array $balance): array => [
                'id' => $balance['id'],
                'name' => $balance['name'],
                'amountCents' => $balance['netCents'] - $balance['creditCents'],
                'netCents' => $balance['netCents'],
                'creditCents' => $balance['creditCents'],
                'status' => $balance['hasMovement'] && $balance['netCents'] - $balance['creditCents'] === 0 ? 'settled' : 'chargeable',
            ])
            ->sortByDesc(fn (array $balance): int => abs($balance['amountCents']))
            ->values()
            ->all();
        $invoiceGroups = $view === 'invoice'
            ? $purchaseListingService->forMonth($selectedMonth, 'invoice', Participant::query()->where('is_default', true)->value('id'))['invoiceGroups']
            : collect();
        $invoiceGroups = $invoiceGroups->map(fn (array $group): array => [
            ...$group,
            'detailsUrl' => route('purchases.index', [
                'month' => $selectedMonth,
                'view' => 'invoice',
                'payment_method_id' => $group['paymentMethodId'],
                'closing_date' => $group['closingDate'],
            ], false),
        ])->values();

        return Inertia::render('Dashboard', [
            'selectedMonth' => $selectedMonth,
            'view' => $view,
            'tab' => $tab,
            'summary' => [
                ...$budgetSummary,
                'totalReceiptsCents' => $totalReceiptsCents,
                'monthBalanceCents' => $totalReceiptsCents - $budgetSummary['totalDisbursedCents'],
            ],
            'personalSummary' => $personalAnalysis['summary'],
            'ownSummary' => $personalAnalysis['ownSummary'],
            'personChart' => [
                'expenses' => $budgetAnalysis['participantExpenses'],
                'balances' => $personBalances,
            ],
            'charts' => [
                'paymentMethodTotals' => $budgetAnalysis['paymentMethodTotals'],
                'movement' => $budgetAnalysis['weeklyMovement'],
                'categories' => $budgetAnalysis['movementCategories'],
                'selectedCategoryId' => $budgetAnalysis['selectedMovementCategoryId'],
                'invoiceCategoryTotals' => $monthlyAnalysisService->invoiceCategoryTotals($selectedMonth),
            ],
            'pendingReview' => $pendingReview,
            'pendingReviewUrl' => $pendingReview > 0 ? route('imports.queue', [], false) : null,
            'invoiceGroups' => $invoiceGroups,
            'recentMovements' => $recentMovements,
            'navigationBases' => [
                'overview' => route('dashboard', [], false),
                'analysis' => route('analysis', [], false),
                'balances' => route('balances', [], false),
                'purchases' => route('purchases.index', [], false),
                'receipt' => route('receipts.create', [], false),
                'purchaseCreate' => route('purchases.create', [], false),
            ],
        ]);
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
