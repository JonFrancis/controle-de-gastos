<?php

namespace App\Http\Controllers;

use App\Models\Participant;
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
        $month = Carbon::createFromFormat('Y-m', $selectedMonth);
        $recurrenceService->ensureOccurrencesForRange($month->copy()->startOfMonth(), $month->copy()->endOfMonth());
        $movementCategoryId = $request->integer('category') ?: null;
        $budgetAnalysis = $monthlyAnalysisService->analyze($selectedMonth, $view, $movementCategoryId);
        $budgetSummary = $budgetAnalysis['summary'];
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

        return Inertia::render('Dashboard', [
            'selectedMonth' => $selectedMonth,
            'view' => $view,
            'summary' => $budgetSummary,
            'personChart' => [
                'expenses' => $budgetAnalysis['participantExpenses'],
                'balances' => $personBalances,
            ],
            'charts' => [
                'paymentMethodTotals' => $budgetAnalysis['paymentMethodTotals'],
                'movement' => $budgetAnalysis['weeklyMovement'],
                'categories' => $budgetAnalysis['movementCategories'],
                'selectedCategoryId' => $budgetAnalysis['selectedMovementCategoryId'],
            ],
            'pendingReview' => $pendingReview,
            'pendingReviewUrl' => $pendingReview > 0 ? route('imports.queue', [], false) : null,
            'invoiceGroups' => $invoiceGroups,
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
