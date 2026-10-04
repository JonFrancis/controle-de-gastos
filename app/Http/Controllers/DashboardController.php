<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\SpreadsheetImportRow;
use App\Services\BalanceService;
use App\Services\MonthlyAnalysisService;
use App\Services\PurchaseListingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MonthlyAnalysisService $monthlyAnalysisService, BalanceService $balanceService, PurchaseListingService $purchaseListingService): Response
    {
        $selectedMonth = $this->validMonth($request->query('month'));
        $view = $request->query('view') === 'invoice' ? 'invoice' : 'calendar';
        $month = Carbon::createFromFormat('Y-m', $selectedMonth);
        $selfId = Participant::query()->where('is_default', true)->value('id');
        $purchaseListing = $purchaseListingService->forMonth($selectedMonth, $view, $selfId);
        $movementCategoryId = $request->integer('category') ?: null;
        $budgetAnalysis = $monthlyAnalysisService->analyze($selectedMonth, 'calendar', $movementCategoryId);
        $budgetSummary = $budgetAnalysis['summary'];
        $pendingReviewQuery = SpreadsheetImportRow::query()->where('status', 'pending_review');
        $pendingReview = (clone $pendingReviewQuery)->count();
        $pendingReviewImportId = (clone $pendingReviewQuery)->value('spreadsheet_import_id');
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
        $purchases = $purchaseListing['purchases'];
        $occurrences = $purchaseListing['occurrences'];
        $invoiceGroups = $purchaseListing['invoiceGroups'];

        return Inertia::render('Dashboard', [
            'monthLabel' => ucfirst($month->locale('pt_BR')->translatedFormat('F \d\e Y')),
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
            'pendingReviewUrl' => $pendingReviewImportId ? route('imports.review', $pendingReviewImportId, false) : null,
            'monthTotalCents' => (int) ($view === 'invoice' ? $invoiceGroups->sum('totalCents') : $purchases->sum('amount_cents') + $occurrences->sum('amount_cents')),
            'purchases' => $view === 'calendar' ? $purchases->map(fn ($purchase) => $purchaseListingService->purchaseData($purchase, $selfId))->values() : [],
            'occurrences' => $view === 'calendar' ? $occurrences->map(fn ($occurrence) => $purchaseListingService->occurrenceData($occurrence))->values() : [],
            'invoiceGroups' => $invoiceGroups->values(),
            'catalogs' => [
                'participants' => Participant::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
                'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
                'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'type', 'closing_day']),
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
