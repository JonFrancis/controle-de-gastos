<?php

namespace App\Services;

use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use App\Models\Purchase;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Models\RecurrenceOccurrence;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BalanceService
{
    public function reconcileParticipant(int $participantId): void
    {
        DB::transaction(function () use ($participantId): void {
            ReceiptApplication::query()
                ->whereHas('receipt', fn ($query) => $query->where('participant_id', $participantId))
                ->whereNull('superseded_at')
                ->where('source', 'automatic')
                ->update(['superseded_at' => now()]);

            $debts = $this->debtItems()->filter(fn (array $debt): bool => $debt['participant_id'] === $participantId)->values();
            $applications = ReceiptApplication::query()
                ->whereHas('receipt', fn ($query) => $query->where('participant_id', $participantId))
                ->whereNull('superseded_at')
                ->get()
                ->groupBy(fn (ReceiptApplication $application): string => $application->source_type.':'.$application->source_id)
                ->map(fn (Collection $rows): int => (int) $rows->sum('amount_cents'));
            $remainingDebts = $debts->mapWithKeys(fn (array $debt): array => [$debt['key'] => max(0, $debt['amount_cents'] - ($applications[$debt['key']] ?? 0))]);
            $receipts = Receipt::query()->where('participant_id', $participantId)->whereNull('archived_at')->with('applications')->orderBy('received_at')->orderBy('id')->get();

            foreach ($receipts as $receipt) {
                if ($receipt->is_manually_adjusted) {
                    continue;
                }

                $available = $receipt->amount_cents - (int) $receipt->applications->sum('amount_cents');
                foreach ($debts as $debt) {
                    if ($available <= 0) {
                        break;
                    }

                    $remaining = $remainingDebts[$debt['key']] ?? 0;
                    if ($remaining <= 0) {
                        continue;
                    }

                    $amount = min($available, $remaining);
                    $this->createApplication($receipt->id, $debt, $amount, 'automatic');
                    $available -= $amount;
                    $remainingDebts[$debt['key']] -= $amount;
                }
            }
        });
    }

    public function reconcileAll(): void
    {
        Receipt::query()->whereNull('archived_at')->pluck('participant_id')->unique()->each(fn (int $participantId) => $this->reconcileParticipant($participantId));
    }

    /** @param list<array{source_type: string, source_id: int, amount_cents: int}> $applications */
    public function replaceManualApplications(Receipt $receipt, array $applications): void
    {
        $debts = $this->debtItems()->keyBy('key');
        $total = 0;
        $keys = [];

        foreach ($applications as $application) {
            $key = $application['source_type'].':'.$application['source_id'];
            $debt = $debts->get($application['source_type'].':'.$application['source_id']);
            $alreadyApplied = ReceiptApplication::query()->where('source_type', $application['source_type'])->where('source_id', $application['source_id'])->where('receipt_id', '!=', $receipt->id)->whereNull('superseded_at')->sum('amount_cents');
            if (isset($keys[$key]) || ! $debt || $debt['participant_id'] !== $receipt->participant_id || $application['amount_cents'] <= 0 || $application['amount_cents'] > $debt['amount_cents'] - $alreadyApplied) {
                throw ValidationException::withMessages(['applications' => 'O recebimento só pode ser aplicado aos saldos da própria pessoa, dentro do valor devido.']);
            }

            $keys[$key] = true;
            $total += $application['amount_cents'];
        }

        if ($total > $receipt->amount_cents) {
            throw ValidationException::withMessages(['applications' => 'A soma das aplicações não pode superar o valor recebido.']);
        }

        DB::transaction(function () use ($receipt, $applications, $debts): void {
            $receipt->applications()->update(['superseded_at' => now()]);
            $receipt->update(['is_manually_adjusted' => true]);
            foreach ($applications as $application) {
                $this->createApplication($receipt->id, $debts->get($application['source_type'].':'.$application['source_id']), $application['amount_cents'], 'manual');
            }
        });

        $this->reconcileParticipant($receipt->participant_id);
    }

    /** @return array{ownConsumptionCents: int, paidForOthersCents: int, owedToOthersCents: int} */
    public function summary(CarbonInterface $until, ?CarbonInterface $since = null): array
    {
        $selfId = $this->selfId();
        $rows = $this->expenseRows($until, $since);

        return [
            'ownConsumptionCents' => (int) $rows->filter(fn (array $row): bool => $row['participant_id'] === $selfId)->sum('amount_cents'),
            'paidForOthersCents' => (int) $rows->filter(fn (array $row): bool => $row['payer_id'] === $selfId && $row['participant_id'] !== null && $row['participant_id'] !== $selfId)->sum('amount_cents'),
            'owedToOthersCents' => (int) $rows->filter(fn (array $row): bool => $row['payer_id'] !== null && $row['payer_id'] !== $selfId && $row['participant_id'] === $selfId)->sum('amount_cents'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function participantBalances(CarbonInterface $until, ?CarbonInterface $since = null): array
    {
        $selfId = $this->selfId();
        $rows = $this->expenseRows($until, $since);
        $applications = $this->applicationsUntil($until);
        $receiptBalances = $this->receiptBalancesUntil($until);

        return Participant::query()->where('id', '!=', $selfId)->orderBy('name')->get()->map(function (Participant $participant) use ($rows, $applications, $receiptBalances, $selfId): array {
            $receivableItems = $rows->filter(fn (array $row): bool => $row['payer_id'] === $selfId && $row['participant_id'] === $participant->id)->map(fn (array $row): array => $this->itemWithOutstanding($row, $applications))->values();
            $payableItems = $rows->filter(fn (array $row): bool => $row['payer_id'] === $participant->id && $row['participant_id'] === $selfId)->map(fn (array $row): array => $this->itemWithOutstanding($row, $applications))->values();
            $receivable = (int) $receivableItems->sum('outstanding_cents');
            $payable = (int) $payableItems->sum('outstanding_cents');
            $receiptBalance = $receiptBalances->get($participant->id);
            $credit = max(0, (int) ($receiptBalance?->received_amount_cents ?? 0) - (int) ($receiptBalance?->applied_amount_cents ?? 0));

            return [
                'id' => $participant->id,
                'name' => $participant->name,
                'receivableCents' => $receivable,
                'payableCents' => $payable,
                'netCents' => $receivable - $payable,
                'creditCents' => $credit,
                'receivableItems' => $receivableItems->all(),
                'payableItems' => $payableItems->all(),
                'hasMovement' => $receivableItems->isNotEmpty() || $payableItems->isNotEmpty() || (int) ($receiptBalance?->receipt_count ?? 0) > 0,
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function receipts(CarbonInterface $until): array
    {
        return Receipt::query()->whereNull('archived_at')->whereDate('received_at', '<=', $until)->with(['participant:id,name', 'applications'])->orderByDesc('received_at')->orderByDesc('id')->get()->map(fn (Receipt $receipt): array => [
            'id' => $receipt->id,
            'participantId' => $receipt->participant_id,
            'participant' => $receipt->participant?->name,
            'receivedAt' => $receipt->received_at->toDateString(),
            'amountCents' => $receipt->amount_cents,
            'appliedCents' => (int) $receipt->applications->sum('amount_cents'),
            'creditCents' => max(0, $receipt->amount_cents - (int) $receipt->applications->sum('amount_cents')),
            'note' => $receipt->note,
            'manual' => $receipt->is_manually_adjusted,
        ])->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function debtItems(): Collection
    {
        $selfId = $this->selfId();

        return $this->expenseRows()->filter(fn (array $row): bool => $row['payer_id'] === $selfId && $row['participant_id'] !== null && $row['participant_id'] !== $selfId)->sortBy([['purchased_at', 'asc'], ['source_id', 'asc']])->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function expenseRows(?CarbonInterface $until = null, ?CarbonInterface $since = null): Collection
    {
        $selfId = $this->selfId();
        $rows = collect();
        $purchases = Purchase::query()->active()->whereNotNull('payment_method_id')->with('allocations')->when($since, fn ($query) => $query->whereDate('purchased_at', '>=', $since))->when($until, fn ($query) => $query->whereDate('purchased_at', '<=', $until))->get();
        foreach ($purchases as $purchase) {
            if ($purchase->allocations->isEmpty()) {
                $rows->push($this->row('purchase', $purchase->id, $purchase->purchased_at, $purchase->amount_cents, $purchase->payer_id ?? $selfId, $purchase->participant_id ?? $selfId, $purchase->description));

                continue;
            }
            foreach ($purchase->allocations as $allocation) {
                $rows->push($this->row('purchase_allocation', $allocation->id, $purchase->purchased_at, $allocation->amount_cents, $purchase->payer_id ?? $selfId, $allocation->participant_id ?? $selfId, $purchase->description));
            }
        }
        foreach (InstallmentOccurrence::query()->whereNull('archived_at')->whereNotNull('payment_method_id')->when($since, fn ($query) => $query->whereDate('purchased_at', '>=', $since))->when($until, fn ($query) => $query->whereDate('purchased_at', '<=', $until))->get() as $occurrence) {
            $rows->push($this->row('installment_occurrence', $occurrence->id, $occurrence->purchased_at, $occurrence->amount_cents, $occurrence->payer_id ?? $selfId, $occurrence->participant_id ?? $selfId, $occurrence->description));
        }
        foreach (RecurrenceOccurrence::query()->whereNull('archived_at')->whereNotNull('payment_method_id')->when($since, fn ($query) => $query->whereDate('purchased_at', '>=', $since))->when($until, fn ($query) => $query->whereDate('purchased_at', '<=', $until))->get() as $occurrence) {
            $rows->push($this->row('recurrence_occurrence', $occurrence->id, $occurrence->purchased_at, $occurrence->amount_cents, $occurrence->payer_id ?? $selfId, $occurrence->participant_id ?? $selfId, $occurrence->description));
        }

        return $rows;
    }

    /** @return Collection<string, int> */
    private function applicationsUntil(CarbonInterface $until): Collection
    {
        return ReceiptApplication::query()->whereNull('superseded_at')->whereHas('receipt', fn ($query) => $query->whereNull('archived_at')->whereDate('received_at', '<=', $until))->get()->groupBy(fn (ReceiptApplication $application): string => $application->source_type.':'.$application->source_id)->map(fn (Collection $rows): int => (int) $rows->sum('amount_cents'));
    }

    /** @return Collection<int, object> */
    private function receiptBalancesUntil(CarbonInterface $until): Collection
    {
        $applicationTotals = ReceiptApplication::query()
            ->whereNull('superseded_at')
            ->select('receipt_id')
            ->selectRaw('SUM(amount_cents) as applied_amount_cents')
            ->groupBy('receipt_id');

        return Receipt::query()
            ->leftJoinSub($applicationTotals, 'receipt_application_totals', fn ($join) => $join->on('receipts.id', '=', 'receipt_application_totals.receipt_id'))
            ->whereNull('receipts.archived_at')
            ->whereDate('receipts.received_at', '<=', $until)
            ->select('receipts.participant_id')
            ->selectRaw('SUM(receipts.amount_cents) as received_amount_cents')
            ->selectRaw('COALESCE(SUM(receipt_application_totals.applied_amount_cents), 0) as applied_amount_cents')
            ->selectRaw('COUNT(receipts.id) as receipt_count')
            ->groupBy('receipts.participant_id')
            ->get()
            ->keyBy('participant_id');
    }

    /** @param array<string, mixed> $row */
    private function itemWithOutstanding(array $row, Collection $applications): array
    {
        return [...$row, 'applied_cents' => $applications[$row['key']] ?? 0, 'outstanding_cents' => max(0, $row['amount_cents'] - ($applications[$row['key']] ?? 0))];
    }

    /** @return array<string, mixed> */
    private function row(string $sourceType, int $sourceId, CarbonInterface $date, int $amountCents, int $payerId, ?int $participantId, string $description): array
    {
        return ['key' => $sourceType.':'.$sourceId, 'source_type' => $sourceType, 'source_id' => $sourceId, 'purchased_at' => $date->toDateString(), 'amount_cents' => $amountCents, 'payer_id' => $payerId, 'participant_id' => $participantId, 'description' => $description];
    }

    /** @param array<string, mixed> $debt */
    private function createApplication(int $receiptId, array $debt, int $amountCents, string $source): void
    {
        ReceiptApplication::create(['receipt_id' => $receiptId, 'source_type' => $debt['source_type'], 'source_id' => $debt['source_id'], 'purchase_allocation_id' => $debt['source_type'] === 'purchase_allocation' ? $debt['source_id'] : null, 'amount_cents' => $amountCents, 'source' => $source]);
    }

    private function selfId(): int
    {
        return (int) Participant::query()->where('is_default', true)->value('id');
    }
}
