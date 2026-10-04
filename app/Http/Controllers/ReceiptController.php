<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReceiptRequest;
use App\Http\Requests\UpdateReceiptApplicationsRequest;
use App\Models\Participant;
use App\Models\Receipt;
use App\Services\BalanceService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Balances/CreateReceipt', [
            'selectedMonth' => $this->validMonth($request->query('month')),
            'participants' => Participant::query()->where('active', true)->where('is_default', false)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(Receipt $receipt, BalanceService $service): Response
    {
        $receipt->load(['participant', 'applications']);
        $applied = $receipt->applications->keyBy(fn ($application): string => $application->source_type.':'.$application->source_id);
        $debts = $service->debtItems()->filter(fn (array $debt): bool => $debt['participant_id'] === $receipt->participant_id)->map(fn (array $debt): array => [...$debt, 'applied_cents' => $applied->get($debt['key'])?->amount_cents ?? 0])->values();

        return Inertia::render('Balances/ReceiptApplications', ['receipt' => $receipt, 'debts' => $debts]);
    }

    public function store(StoreReceiptRequest $request, BalanceService $service): RedirectResponse
    {
        $data = $request->validated();
        $data['amount_cents'] = $this->moneyToCents($data['amount']);
        unset($data['amount']);
        $receipt = Receipt::create($data);
        $service->reconcileParticipant($receipt->participant_id);

        return to_route('balances', array_filter(['month' => $request->query('month')]))->with('success', 'Recebimento registrado e aplicado aos saldos mais antigos.');
    }

    public function updateApplications(UpdateReceiptApplicationsRequest $request, Receipt $receipt, BalanceService $service): RedirectResponse
    {
        $applications = collect($request->validated('applications', []))->map(fn (array $application): array => [...$application, 'source_id' => (int) $application['source_id'], 'amount_cents' => $this->moneyToCents($application['amount'])])->all();
        $service->replaceManualApplications($receipt, $applications);

        return to_route('balances')->with('success', 'Aplicações do recebimento ajustadas.');
    }

    private function moneyToCents(string|int $value): int
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        [$whole, $decimal] = array_pad(explode('.', $normalized, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($decimal, 2, '0');
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
