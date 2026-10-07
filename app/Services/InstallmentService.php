<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\Participant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

    public function reschedule(Installment $installment, array $data): Installment
    {
        $startDate = CarbonImmutable::parse($data['start_date']);
        $endDate = CarbonImmutable::parse($data['end_date']);
        $count = $this->installmentCountForRange($startDate, $endDate);
        $totalCents = $this->moneyToCents($data['total']);
        $baseCents = intdiv($totalCents, $count);
        $remainder = $totalCents % $count;

        return DB::transaction(function () use ($installment, $startDate, $count, $baseCents, $remainder, $totalCents): Installment {
            $installment->update([
                'start_date' => $startDate->toDateString(),
                'total_cents' => $totalCents,
                'installment_count' => $count,
            ]);

            $existing = $installment->occurrences()->get()->keyBy('installment_number');

            foreach (range(1, $count) as $number) {
                $attributes = [
                    'installment_number' => $number,
                    'purchased_at' => $startDate->addMonthsNoOverflow($number - 1)->toDateString(),
                    'amount_cents' => $number === $count ? $baseCents + $remainder : $baseCents,
                    'description' => $installment->description,
                    'card_name' => $installment->card_name,
                    'payer_id' => $installment->payer_id,
                    'participant_id' => $installment->participant_id,
                    'payment_method_id' => $installment->payment_method_id,
                    'category_id' => $installment->category_id,
                    'is_adjusted' => false,
                    'archived_at' => null,
                ];

                if ($existing->has($number)) {
                    $existing->get($number)->update($attributes);
                } else {
                    $installment->occurrences()->create($attributes);
                }
            }

            $installment->occurrences()
                ->where('installment_number', '>', $count)
                ->whereNull('archived_at')
                ->update(['archived_at' => now()]);

            return $installment->fresh();
        });
    }

    public function installmentCountForRange(CarbonInterface $startDate, CarbonInterface $endDate): int
    {
        return $startDate->startOfMonth()->diffInMonths($endDate->startOfMonth()) + 1;
    }

    private function moneyToCents(string|int|float $value): int
    {
        return (int) round(((float) str_replace(',', '.', (string) $value)) * 100);
    }
}
