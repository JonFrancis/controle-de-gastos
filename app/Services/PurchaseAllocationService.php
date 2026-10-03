<?php

namespace App\Services;

use App\Models\Participant;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseAllocationService
{
    public const MODES = ['equal', 'amount', 'percentage'];

    public function save(Purchase $purchase, string $mode, array $allocations): void
    {
        if (! in_array($mode, self::MODES, true) || count($allocations) === 0) {
            throw ValidationException::withMessages(['allocations' => 'Informe um modo e pelo menos um participante na divisão.']);
        }

        $selfId = (int) Participant::query()->where('is_default', true)->value('id');
        $rows = collect($allocations)->map(fn (array $row) => ['participant_id' => $row['participant_id'], 'category_id' => (int) $row['participant_id'] === $selfId ? ($row['category_id'] ?? null) : null, 'amount_cents' => $this->moneyToCents($row['amount'] ?? '0'), 'percentage_basis_points' => $this->percentageToBasisPoints($row['percentage'] ?? '0')])->values();
        $this->ensureUniqueParticipants($rows->pluck('participant_id')->all());

        if ($mode === 'equal') {
            $rows = $this->equalAmounts($rows->count(), $purchase->amount_cents, $rows);
        } elseif ($mode === 'amount') {
            if ((int) $rows->sum('amount_cents') !== $purchase->amount_cents) {
                throw ValidationException::withMessages(['allocations' => 'A soma das divisões precisa ser exatamente igual ao valor da compra.']);
            }
            $rows = $rows->map(fn (array $row) => [...$row, 'percentage_basis_points' => null]);
        } else {
            if ((int) $rows->sum('percentage_basis_points') !== 10000) {
                throw ValidationException::withMessages(['allocations' => 'A soma dos percentuais precisa ser exatamente 100%.']);
            }
            $rows = $this->percentageAmounts($rows, $purchase->amount_cents);
        }

        DB::transaction(function () use ($purchase, $mode, $rows): void {
            $purchase->update(['allocation_mode' => $mode]);
            $purchase->allocations()->delete();
            $purchase->allocations()->createMany($rows->all());
        });
    }

    private function equalAmounts(int $count, int $total, $rows)
    {
        $base = intdiv($total, $count);
        $remainder = $total % $count;

        return $rows->values()->map(fn (array $row, int $index) => [...$row, 'amount_cents' => $base + ($index < $remainder ? 1 : 0), 'percentage_basis_points' => null]);
    }

    private function percentageAmounts($rows, int $total)
    {
        $calculated = $rows->map(fn (array $row) => [...$row, 'amount_cents' => intdiv($total * $row['percentage_basis_points'], 10000)]);
        $remainder = $total - (int) $calculated->sum('amount_cents');

        return $calculated->values()->map(fn (array $row, int $index) => $index === 0 ? [...$row, 'amount_cents' => $row['amount_cents'] + $remainder] : $row);
    }

    private function ensureUniqueParticipants(array $participantIds): void
    {
        if (count($participantIds) !== count(array_unique(array_map(fn ($id) => $id === null ? 'self' : (string) $id, $participantIds)))) {
            throw ValidationException::withMessages(['allocations' => 'Cada participante pode aparecer apenas uma vez na divisão.']);
        }
    }

    private function moneyToCents(string|int|float $value): int
    {
        return (int) round(((float) str_replace(',', '.', (string) $value)) * 100);
    }

    private function percentageToBasisPoints(string|int|float $value): int
    {
        return (int) round(((float) str_replace(',', '.', (string) $value)) * 100);
    }
}
