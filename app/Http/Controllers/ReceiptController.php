<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReceiptRequest;
use App\Http\Requests\UpdateReceiptApplicationsRequest;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Services\AuditService;
use App\Services\BalanceService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    public function edit(Request $request, Receipt $receipt, BalanceService $service): Response
    {
        $selectedMonth = $this->validMonth($request->query('month'));
        $receipt->load(['participant', 'applications']);
        $applied = $receipt->applications->keyBy(fn ($application): string => $application->source_type.':'.$application->source_id);
        $month = Carbon::createFromFormat('!Y-m', $selectedMonth);
        $view = $this->view($request);
        $allDebtItems = $view === 'invoice' ? $service->billableDebtItems() : $service->debtItems();
        $applicationTotals = ReceiptApplication::query()
            ->whereNull('superseded_at')
            ->whereHas('receipt', fn (Builder $query): Builder => $query->whereNull('archived_at'))
            ->get()
            ->groupBy(fn (ReceiptApplication $application): string => $application->source_type.':'.$application->source_id)
            ->map(fn (Collection $applications): int => (int) $applications->sum('amount_cents'));
        $selectedDebts = $view === 'invoice'
            ? $service->debtItemsForBillingMonth($month)
            : $service->debtItemsForCalendarMonth($month);
        $debts = $selectedDebts
            ->filter(fn (array $debt): bool => $debt['participant_id'] === $receipt->participant_id)
            ->concat($allDebtItems
                ->filter(fn (array $debt): bool => $debt['participant_id'] === $receipt->participant_id
                    && $applied->has($debt['key']))
            )
            ->unique('key')
            ->sortBy([['purchased_at', 'asc'], ['source_id', 'asc']])
            ->map(fn (array $debt): array => [
                ...$debt,
                'applied_cents' => $applied->get($debt['key'])?->amount_cents ?? 0,
                'outstanding_cents' => max(0, $debt['amount_cents'] - ($applicationTotals[$debt['key']] ?? 0)),
            ])
            ->values();

        return Inertia::render('Balances/ReceiptApplications', [
            'selectedMonth' => $selectedMonth,
            'view' => $view,
            'receipt' => [
                'id' => $receipt->id,
                'participant' => ['name' => $receipt->participant->name],
                'received_at' => $receipt->received_at->toDateString(),
                'amount_cents' => $receipt->amount_cents,
            ],
            'debts' => $debts,
        ]);
    }

    public function store(StoreReceiptRequest $request, BalanceService $service, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $data['amount_cents'] = $this->moneyToCents($data['amount']);
        unset($data['amount']);
        $receipt = Receipt::create($data);
        $audit->record(AuditLog::ACTION_CREATE, $receipt, newValues: $receipt->getAttributes());
        $service->reconcileParticipant($receipt->participant_id);

        return to_route('balances', array_filter(['month' => $request->query('month')]))->with('success', 'Recebimento registrado e aplicado aos saldos mais antigos.');
    }

    public function updateApplications(UpdateReceiptApplicationsRequest $request, Receipt $receipt, BalanceService $service, AuditService $audit): RedirectResponse
    {
        $oldApplications = $receipt->applications()->get()->toArray();
        $applications = collect($request->validated('applications', []))->map(fn (array $application): array => [...$application, 'source_id' => (int) $application['source_id'], 'amount_cents' => $this->moneyToCents($application['amount'])])->all();
        $service->replaceManualApplications($receipt, $applications, $this->view($request) === 'invoice');
        $audit->record(AuditLog::ACTION_UPDATE, $receipt, oldValues: ['applications' => $oldApplications], newValues: ['applications' => $receipt->fresh()->applications()->get()->toArray()], metadata: ['type' => 'receipt_applications']);

        return to_route('balances', array_filter(['month' => $request->query('month'), 'view' => $request->query('view')]))->with('success', 'Aplicações do recebimento ajustadas.');
    }

    public function destroy(Request $request, Receipt $receipt, BalanceService $service, AuditService $audit): RedirectResponse
    {
        $oldValues = $receipt->getAttributes();
        $receiptApplicationIds = $receipt->applicationHistory()->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();
        DB::transaction(function () use ($receipt, $service, $audit, $oldValues, $receiptApplicationIds): void {
            $service->deleteReceipt($receipt);
            $audit->record(AuditLog::ACTION_DELETE, $receipt, oldValues: $oldValues, metadata: [
                'type' => 'receipt_deletion',
                'deleted_receipt_application_ids' => $receiptApplicationIds,
                'balances_reconciled' => true,
            ]);
        });

        return to_route('balances', [
            'month' => $this->validMonth($request->query('month')),
            'view' => $this->view($request),
        ])->with('success', 'Recebimento excluído permanentemente e saldos recalculados.');
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

    private function view(Request $request): string
    {
        return $request->query('view') === 'invoice' ? 'invoice' : 'calendar';
    }
}
