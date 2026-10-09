<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\InstallmentAllocation;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstallmentAllocationService
{
    public const MODES = ['equal', 'amount', 'percentage'];

    /** @param list<array{participant_id: int|string|null, category_id?: int|string|null, amount?: int|string|null, percentage?: int|string|null}> $allocations */
    public function save(Installment $installment, string $mode, array $allocations): void
    {
        $rows = $this->ruleRows($installment->total_cents, $mode, $allocations);

        DB::transaction(function () use ($installment, $mode, $rows): void {
            $installment->update(['allocation_mode' => $mode]);
            $this->materialize($installment, $rows);
        });
    }

    public function synchronizeMaterialization(Installment $installment): void
    {
        DB::transaction(function () use ($installment): void {
            $occurrences = $installment->occurrences()
                ->whereNull('archived_at')
                ->with('allocations')
                ->orderBy('installment_number')
                ->get();
            $templateOccurrence = $occurrences->first(fn (InstallmentOccurrence $occurrence): bool => $occurrence->allocations->isNotEmpty());

            if ($templateOccurrence === null) {
                return;
            }

            $template = $templateOccurrence->allocations->map(fn (InstallmentAllocation $allocation): array => [
                'participant_id' => $allocation->participant_id,
                'category_id' => $allocation->category_id,
                'amount_cents' => $allocation->amount_cents,
                'percentage_basis_points' => $allocation->percentage_basis_points,
            ])->values()->all();

            foreach ($occurrences as $occurrence) {
                if ($occurrence->allocations->isEmpty()) {
                    $this->materializeOccurrence($occurrence, $template, $occurrence->amount_cents);

                    continue;
                }

                $this->synchronizeOccurrence($occurrence);
            }
        });
    }

    /** @param list<array{participant_id: int|string|null, category_id?: int|string|null, amount?: int|string|null, percentage?: int|string|null}> $allocations @return list<array{participant_id: int|null, category_id: int|null, amount_cents: int, percentage_basis_points: int|null}> */
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

            $occurrence->allocations()->createMany(collect($rows)->values()->map(fn (array $row, int $rowIndex): array => [
                'participant_id' => $row['participant_id'],
                'category_id' => $row['category_id'],
                'amount_cents' => $amounts[$rowIndex],
                'percentage_basis_points' => $row['percentage_basis_points'],
            ])->all());
        }
    }

    /** @param list<array{participant_id: int|null, category_id: int|null, amount_cents: int, percentage_basis_points: int|null}> $rows */
    private function materializeOccurrence(InstallmentOccurrence $occurrence, array $rows, int $amountCents): void
    {
        $amounts = $this->apportion($amountCents, collect($rows)->pluck('amount_cents')->all());

        $occurrence->allocations()->createMany(collect($rows)->values()->map(fn (array $row, int $rowIndex): array => [
            'participant_id' => $row['participant_id'],
            'category_id' => $row['category_id'],
            'amount_cents' => $amounts[$rowIndex],
            'percentage_basis_points' => $row['percentage_basis_points'],
        ])->all());
    }

    private function synchronizeOccurrence(InstallmentOccurrence $occurrence): void
    {
        $allocations = $occurrence->allocations->values();

        if ($allocations->count() === 1) {
            $allocation = $allocations->first();
            $attributes = ['amount_cents' => $occurrence->amount_cents];

            if ($occurrence->participant_id !== null) {
                $attributes['participant_id'] = $occurrence->participant_id;
                $attributes['category_id'] = $occurrence->category_id;
            }

            $allocation->update($attributes);

            return;
        }

        $amountCents = (int) $allocations->sum('amount_cents');
        if ($amountCents === $occurrence->amount_cents) {
            return;
        }

        $amounts = $this->apportion($occurrence->amount_cents, $allocations->pluck('amount_cents')->all());
        foreach ($allocations as $index => $allocation) {
            $allocation->update(['amount_cents' => $amounts[$index]]);
        }
    }

    /** @param list<int> $weights @return list<int> */
    private function apportion(int $amountCents, array $weights): array
    {
        $totalWeight = array_sum($weights);

        if ($amountCents === 0) {
            return array_fill(0, count($weights), 0);
        }

        if ($totalWeight === 0) {
            $weights = array_fill(0, count($weights), 1);
            $totalWeight = count($weights);
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

    private function moneyToCents(string|int|null $value): int
    {
        return $this->decimalToMinorUnits($value);
    }

    private function percentageToBasisPoints(string|int|null $value): int
    {
        return $this->decimalToMinorUnits($value);
    }

    private function decimalToMinorUnits(string|int|null $value): int
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($fraction, 3, '0');
        $minorUnits = ((int) $whole * 100) + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $minorUnits++;
        }

        return $negative ? -$minorUnits : $minorUnits;
    }
}
