<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\MonthlyAnalysisService;
use App\Services\RecurrenceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalysisController extends Controller
{
    public function __invoke(Request $request, MonthlyAnalysisService $service, RecurrenceService $recurrenceService): Response
    {
        $selectedMonth = $this->validMonth($request->query('month'));
        $view = $request->query('view') === 'invoice' ? 'invoice' : 'calendar';
        $month = Carbon::createFromFormat('!Y-m', $selectedMonth);
        $periodStart = $view === 'invoice' ? $month->copy()->subMonthNoOverflow()->startOfMonth() : $month->copy()->startOfMonth();
        $recurrenceService->ensureOccurrencesForRange($periodStart, $month->copy()->endOfMonth());

        return Inertia::render('Analysis/Index', [
            'selectedMonth' => $selectedMonth,
            'view' => $view,
            'salaryCents' => AppSetting::query()->find(1)?->monthly_salary_cents,
            'flash' => ['success' => session('success'), 'openai' => session('openai')],
            ...$service->analyze($selectedMonth, $view),
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
