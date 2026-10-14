<?php

namespace App\Http\Controllers;

use App\Services\BalanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BalanceController extends Controller
{
    public function __invoke(Request $request, BalanceService $service): Response
    {
        $selectedMonth = $this->validMonth($request->query('month'));
        $until = Carbon::createFromFormat('!Y-m', $selectedMonth)->endOfMonth();
        $month = Carbon::createFromFormat('!Y-m', $selectedMonth);
        $view = $request->query('view') === 'invoice' ? 'invoice' : 'calendar';

        return Inertia::render('Balances/Index', [
            'selectedMonth' => $selectedMonth,
            'view' => $view,
            'summary' => $view === 'invoice' ? $service->summaryForBillingMonth($month) : $service->summary($until, $month->startOfMonth()),
            'participants' => $view === 'invoice' ? $service->participantBalancesForBillingMonth($month) : $service->participantBalances($until, $month->startOfMonth()),
            'receipts' => $service->receipts($until),
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
