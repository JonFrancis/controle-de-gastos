<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\InstallmentAllocation;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use App\Models\ReceiptApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstallmentAllocationService
{
    public const MODES = ['equal', 'amount', 'percentage'];

    /** @param list<array{participant_id: int|string|null, category_id?: int|string|null, amount?: int|float|string|null, percentage?: int|float|string|null}> $allocations */
    public function save(Installment $installment, string $mode, array $allocations): void
    {
        $rows = $this->ruleRows($installment->total_cents, $mode, $allocations);

        DB::transaction(function () use ($installment, $mode, $rows): void {
            $installment->update(['allocation_mode' => $mode]);
            $this->materialize($installment, $rows);
        });
    }

    public function redistribute(InstallmentOccurrence $occurrence, int $amountCents): void
    {
        $allocations = $occurrence->allocations()->orderBy('id')->get();

        if ($allocations->isEmpty()) {
            $allocations = collect([[
                'participant_id' => $occurrence->participant_id,
                'category_id' => $occurrence->category_id,
                'amount_cents' => $occurrence->amount_cents,
                'percentage_basis_points' => null,
            ]]);
        }

        $amounts = $this->apportion($amountCents, $allocations->pluck('amount_cents')->map(fn (int $amount): int => $amount)->all());

        DB::transaction(function () use ($occurrence, $allocations, $amounts): void {
            $rows = $allocations->values()->map(fn (array|object $allocation, int $index): array => [
                'participant_id' => is_array($allocation) ? $allocation['participant_id'] : $allocation->participant_id,
                'category_id' => is_array($allocation) ? $allocation['category_id'] : $allocation->category_id,
                'amount_cents' => $amounts[$index],
                'percentage_basis_points' => is_array($allocation) ? $allocation['percentage_basis_points'] : $allocation->percentage_basis_points,
            ])->all();

            $this->replaceOccurrenceAllocations($occurrence, $rows);
        });
    }

    /** @return list<array{participant_id: int|null, category_id: int|null, amount?: int|float|string|null, percentage?: int|float|string|null}> */
    public function rowsForExistingRule(Installment $installment, int $totalCents): array
    {
        $occurrences = $installment->occurrences()->whereNull('archived_at')->with('allocations')->orderBy('installment_number')->get();
        $first = $occurrences->first();

        if ($first === null || $first->allocations->isEmpty()) {
            return [[
                'participant_id' => $installment->participant_id,
                'category_id' => $installment->category_id,
                'amount' => (string) $totalCents / 100,
            ]];
        }

        if ($installment->allocation_mode === 'percentage') {
            return $first->allocations->map(fn ($allocation): array => [
                'participant_id' => $allocation->participant_id,
                'category_id' => $allocation->category_id,
                'percentage' => ((int) $allocation->percentage_basis_points) / 100,
            ])->all();
        }

        $amounts = $installment->allocation_mode === 'amount'
            ? $this->apportion($totalCents, $first->allocations->map(fn ($allocation): int => (int) $occurrences->sum(fn ($occurrence): int => (int) $occurrence->allocations->firstWhere('participant_id', $allocation->participant_id)?->amount_cents ?? 0))->all())
            : [];

        return $first->allocations->map(fn ($allocation, int $index): array => [
            'participant_id' => $allocation->participant_id,
            'category_id' => $allocation->category_id,
            'amount' => $installment->allocation_mode === 'equal'
                ? null
                : ($installment->allocation_mode === 'amount'
                    ? $amounts[$index] / 100
                    : null),
            'percentage' => $installment->allocation_mode === 'percentage'
                ? ((int) $allocation->percentage_basis_points) / 100
                : null,
        ])->all();
    }

    /** @param list<array{participant_id: int|string|null, category_id?: int|string|null, amount?: int|float|string|null, percentage?: int|float|string|null}> $allocations @return list<array{participant_id: int|null, category_id: int|null, amount_cents: int, percentage_basis_points: int|null}> */
    private function ruleRows(int $totalCents, string $mode, array $allocations): array
    {
        if (! in_array($mode, self::MODES, true) || count($allocations) === 0) {
            throw ValidationException::withMessages(['allocations' => 'Informe um modo e pelo menos um participante no Rateio.']);
        }

        $selfId = (int) Participant::query()->where('is_default', true)->value('id');
        $rows = collect($allocations)->map(fn (array $allocation): array => [
            'participant_id' => $allocation['participant_id'] === null || $allocation['participant_id'] === '' ? null : (int) $allocation['participant_id'],
            'category_id' => $allocation['category_id'] ?? null,
            'amount_cents' => $this->moneyToCents($allocation['amount'] ?? '0'),
            'percentage_basis_points' => $this->percentageToBasisPoints($allocation['percentage'] ?? '0'),
        ])->values();

        $participantKeys = $rows->pluck('participant_id')->map(fn (?int $participantId): string => $participantId === null ? 'self' : (string) $participantId)->all();

        if (count($participantKeys) !== count(array_unique($participantKeys))) {
            throw ValidationException::withMessages(['allocations' => 'Cada participante pode aparecer apenas uma vez no Rateio.']);
        }

        $rows = $rows->map(fn (array $row): array => [
            ...$row,
            'category_id' => $row['participant_id'] === null || $row['participant_id'] === $selfId ? ($row['category_id'] === '' ? null : $row['category_id']) : null,
        ]);

        if ($mode === 'equal') {
            $amounts = $this->apportion($totalCents, array_fill(0, $rows->count(), 1));

            return $rows->map(fn (array $row, int $index): array => [...$row, 'amount_cents' => $amounts[$index], 'percentage_basis_points' => null])->all();
        }

        if ($mode === 'amount') {
            if ($rows->contains(fn (array $row): bool => $row['amount_cents'] < 0) || (int) $rows->sum('amount_cents') !== $totalCents) {
                throw ValidationException::withMessages(['allocations' => 'A soma dos Rateios precisa ser exatamente igual ao valor do parcelamento.']);
            }

            return $rows->map(fn (array $row): array => [...$row, 'percentage_basis_points' => null])->all();
        }

        if ($rows->contains(fn (array $row): bool => $row['percentage_basis_points'] < 0) || (int) $rows->sum('percentage_basis_points') !== 10000) {
            throw ValidationException::withMessages(['allocations' => 'A soma dos percentuais precisa ser exatamente 100%.']);
        }

        $amounts = $this->apportion($totalCents, $rows->pluck('percentage_basis_points')->all());

        return $rows->map(fn (array $row, int $index): array => [...$row, 'amount_cents' => $amounts[$index]])->all();
    }

    /** @param list<array{participant_id: int|null, category_id: int|null, amount_cents: int, percentage_basis_points: int|null}> $rows */
    private function materialize(Installment $installment, array $rows): void
    {
        $occurrences = $installment->occurrences()->whereNull('archived_at')->orderBy('installment_number')->get();
        $configuredTotal = (int) collect($rows)->sum('amount_cents');
        $occurrenceTotal = (int) $occurrences->sum('amount_cents');
        $remaining = collect($rows)->pluck('amount_cents')->map(fn (int $amount): int => $amount)->all();

        foreach ($occurrences as $index => $occurrence) {
            $amounts = $configuredTotal === $occurrenceTotal
                ? ($index === $occurrences->count() - 1
                ? $remaining
                : $this->apportion($occurrence->amount_cents, $remaining))
                : $this->apportion($occurrence->amount_cents, collect($rows)->pluck('amount_cents')->all());

            if ($configuredTotal === $occurrenceTotal) {
                $remaining = array_map(fn (int $remainingAmount, int $allocated): int => $remainingAmount - $allocated, $remaining, $amounts);
            }

            $this->replaceOccurrenceAllocations($occurrence, collect($rows)->values()->map(fn (array $row, int $rowIndex): array => [
                'participant_id' => $row['participant_id'],
                'category_id' => $row['category_id'],
                'amount_cents' => $amounts[$rowIndex],
                'percentage_basis_points' => $row['percentage_basis_points'],
            ])->all());
        }
    }

    /** @param list<array{participant_id: int|null, category_id: int|null, amount_cents: int, percentage_basis_points: int|null}> $rows */
    private function replaceOccurrenceAllocations(InstallmentOccurrence $occurrence, array $rows): void
    {
        $existing = $occurrence->allocations()->orderBy('id')->get();
        $existingByParticipant = $existing->keyBy(fn (InstallmentAllocation $allocation): string => $this->participantKey($allocation->participant_id));
        $retained = collect();

        foreach ($rows as $row) {
            $key = $this->participantKey($row['participant_id']);
            $allocation = $existingByParticipant->get($key);

            if ($allocation === null) {
                $allocation = $occurrence->allocations()->create($row);
            } else {
                $allocation->update($row);
            }

            $retained->push($allocation->fresh());
        }

        $fallback = $retained->first();

        foreach ($existing as $allocation) {
            if ($retained->contains('id', $allocation->id)) {
                continue;
            }

            if ($fallback !== null && $this->remapReceiptApplications($allocation, $fallback)) {
                $allocation->delete();
            }
        }
    }

    private function remapReceiptApplications(InstallmentAllocation $from, InstallmentAllocation $to): bool
    {
        $canDelete = true;

        ReceiptApplication::query()
            ->where('source_type', 'installment_allocation')
            ->where('source_id', $from->id)
            ->get()
            ->each(function (ReceiptApplication $application) use ($to, &$canDelete): void {
                $duplicate = ReceiptApplication::query()
                    ->where('receipt_id', $application->receipt_id)
                    ->where('source_type', 'installment_allocation')
                    ->where('source_id', $to->id)
                    ->first();

                if ($duplicate === null) {
                    $application->update(['source_id' => $to->id]);

                    return;
                }

                $canDelete = false;
            });

        return $canDelete;
    }

    private function participantKey(?int $participantId): string
    {
        return $participantId === null ? 'self' : (string) $participantId;
    }

    /** @param list<int> $weights @return list<int> */
    private function apportion(int $amountCents, array $weights): array
    {
        $totalWeight = array_sum($weights);

        if ($amountCents === 0 || $totalWeight === 0) {
            return array_fill(0, count($weights), 0);
        }

        $amounts = array_map(fn (int $weight): int => intdiv($amountCents * $weight, $totalWeight), $weights);
        $remainders = array_map(fn (int $weight): int => ($amountCents * $weight) % $totalWeight, $weights);
        $remaining = $amountCents - array_sum($amounts);
        arsort($remainders, SORT_NUMERIC);
        $remainderIndexes = array_keys($remainders);

        for ($index = 0; $index < $remaining; $index++) {
            $amounts[$remainderIndexes[$index % count($remainderIndexes)]]++;
        }

        return array_values($amounts);
    }

    private function moneyToCents(string|int|float|null $value): int
    {
        return (int) round(((float) str_replace(',', '.', (string) $value)) * 100);
    }

    private function percentageToBasisPoints(string|int|float|null $value): int
    {
        return (int) round(((float) str_replace(',', '.', (string) $value)) * 100);
    }
}
