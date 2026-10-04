<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\Participant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class InstallmentService
{
    public function create(array $data): Installment
    {
        $totalCents = $this->moneyToCents($data['total']);
        $count = (int) $data['installment_count'];
        $selfId = Participant::query()->where('is_default', true)->value('id');
        $participantId = $data['participant_id'] ?? null;
        $categoryId = $participantId === null || (int) $participantId === (int) $selfId ? ($data['category_id'] ?? null) : null;

        return DB::transaction(function () use ($data, $totalCents, $count, $participantId, $categoryId): Installment {
            $installment = Installment::create([
                'start_date' => $data['start_date'],
                'description' => $data['description'],
                'card_name' => $data['card_name'] ?? null,
                'total_cents' => $totalCents,
                'installment_count' => $count,
                'payer_id' => $data['payer_id'] ?? null,
                'participant_id' => $participantId,
                'payment_method_id' => $data['payment_method_id'],
                'category_id' => $categoryId,
            ]);

            $baseCents = intdiv($totalCents, $count);
            $remainder = $totalCents % $count;
            $startDate = CarbonImmutable::parse($data['start_date']);

            $installment->occurrences()->createMany(collect(range(1, $count))->map(function (int $number) use ($installment, $baseCents, $remainder, $startDate, $data, $participantId, $categoryId): array {
                return [
                    'installment_number' => $number,
                    'purchased_at' => $startDate->addMonthsNoOverflow($number - 1)->toDateString(),
                    'description' => $data['description'],
                    'card_name' => $data['card_name'] ?? null,
                    'amount_cents' => $number === $installment->installment_count ? $baseCents + $remainder : $baseCents,
                    'payer_id' => $data['payer_id'] ?? null,
                    'participant_id' => $participantId,
                    'payment_method_id' => $data['payment_method_id'],
                    'category_id' => $categoryId,
                ];
            })->all());

            return $installment;
        });
    }

    private function moneyToCents(string|int|float $value): int
    {
        return (int) round(((float) str_replace(',', '.', (string) $value)) * 100);
    }
}
