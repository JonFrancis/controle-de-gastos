<?php

namespace App\Services;

use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class RecurrenceService
{
    /**
     * @param  array{start_date: string, due_date?: string|null, end_date?: string|null, day_of_month: int|string, description: string, card_name?: string|null, amount: int|float|string, payer_id?: int|string|null, participant_id?: int|string|null, payment_method_id: int|string, category_id?: int|string|null}  $data
     */
    public function create(array $data): Recurrence
    {
        $amountCents = $this->moneyToCents($data['amount']);
        $participantId = $data['participant_id'] ?? null;
        $selfId = Participant::query()->where('is_default', true)->value('id');
        $categoryId = $participantId === null || (int) $participantId === (int) $selfId ? ($data['category_id'] ?? null) : null;
        $paymentMethod = PaymentMethod::query()->find($data['payment_method_id']);
        $dueDate = $paymentMethod?->type === PaymentMethod::TYPE_LOAN ? ($data['due_date'] ?? null) : null;

        return DB::transaction(function () use ($data, $amountCents, $participantId, $categoryId, $dueDate): Recurrence {
            $recurrence = Recurrence::create([
                'start_date' => $data['start_date'],
                'due_date' => $dueDate,
                'end_date' => $data['end_date'] ?? null,
                'day_of_month' => $data['day_of_month'],
                'description' => $data['description'],
                'card_name' => $data['card_name'] ?? null,
                'amount_cents' => $amountCents,
                'payer_id' => $data['payer_id'] ?? null,
                'participant_id' => $participantId,
                'payment_method_id' => $data['payment_method_id'],
                'category_id' => $categoryId,
                'active' => true,
            ]);

            $endDate = $recurrence->end_date?->endOfMonth() ?? CarbonImmutable::parse($recurrence->start_date)->addMonthsNoOverflow(12)->endOfMonth();
            $this->ensureOccurrencesForRange($recurrence->start_date->startOfMonth(), $endDate);

            return $recurrence;
        });
    }

    public function ensureOccurrencesForRange(CarbonInterface $from, CarbonInterface $to): void
    {
        Recurrence::query()->active()
            ->with('paymentMethod:id,type')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $from->toDateString()))
            ->get()
            ->each(fn (Recurrence $recurrence) => $this->ensureOccurrences($recurrence, $from, $to));
    }

    public function synchronizeFutureDueDates(Recurrence $recurrence): void
    {
        if ($recurrence->paymentMethod?->type !== PaymentMethod::TYPE_LOAN || $recurrence->due_date === null) {
            return;
        }

        $startDate = CarbonImmutable::instance($recurrence->start_date);
        $baseDueDate = CarbonImmutable::instance($recurrence->due_date);
        $recurrence->occurrences()
            ->whereNull('archived_at')
            ->whereDate('purchased_at', '>=', now()->toDateString())
            ->get()
            ->each(function ($occurrence) use ($startDate, $baseDueDate): void {
                $months = $startDate->startOfMonth()->diffInMonths($occurrence->purchased_at->startOfMonth());
                $occurrence->update(['due_date' => $baseDueDate->addMonthsNoOverflow($months)->toDateString()]);
            });
    }

    private function ensureOccurrences(Recurrence $recurrence, CarbonInterface $from, CarbonInterface $to): void
    {
        $cursor = CarbonImmutable::instance($from)->startOfMonth();
        $lastMonth = CarbonImmutable::instance($to)->startOfMonth();
        $startDate = CarbonImmutable::instance($recurrence->start_date);
        $endDate = $recurrence->end_date ? CarbonImmutable::instance($recurrence->end_date) : null;
        $paymentMethod = $recurrence->paymentMethod;
        $baseDueDate = $paymentMethod?->type === PaymentMethod::TYPE_LOAN && $recurrence->due_date !== null
            ? CarbonImmutable::instance($recurrence->due_date)
            : null;

        while ($cursor->lessThanOrEqualTo($lastMonth)) {
            $date = $cursor->setDay(min($recurrence->day_of_month, $cursor->daysInMonth));

            if ($date->greaterThanOrEqualTo($startDate) && ($endDate === null || $date->lessThanOrEqualTo($endDate))) {
                $alreadyExists = $recurrence->occurrences()->whereDate('purchased_at', $date->toDateString())->exists();

                if (! $alreadyExists) {
                    $recurrence->occurrences()->create([
                        'purchased_at' => $date->toDateString(),
                        'due_date' => $baseDueDate?->addMonthsNoOverflow($startDate->startOfMonth()->diffInMonths($date->startOfMonth()))->toDateString(),
                        'description' => $recurrence->description,
                        'card_name' => $recurrence->card_name,
                        'amount_cents' => $recurrence->amount_cents,
                        'payer_id' => $recurrence->payer_id,
                        'participant_id' => $recurrence->participant_id,
                        'payment_method_id' => $recurrence->payment_method_id,
                        'category_id' => $recurrence->category_id,
                    ]);
                }
            }

            $cursor = $cursor->addMonthNoOverflow();
        }
    }

    private function moneyToCents(string|int|float $value): int
    {
        return (int) round(((float) str_replace(',', '.', (string) $value)) * 100);
    }
}
