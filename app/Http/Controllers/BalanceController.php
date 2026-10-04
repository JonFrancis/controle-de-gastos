<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
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

        return Inertia::render('Balances/Index', [
            'selectedMonth' => $selectedMonth,
            'summary' => $service->summary($until),
            'participants' => $service->participantBalances($until),
            'receipts' => $service->receipts($until),
            'catalogs' => ['participants' => Participant::query()->where('active', true)->where('is_default', false)->orderBy('name')->get(['id', 'name']), 'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']), 'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name'])],
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
