<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\InstallmentAllocation;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InstallmentService
{
    public function __construct(
        private readonly PaymentMethodInvoiceSettingService $invoiceSettings,
        private readonly InstallmentAllocationService $allocations,
    ) {}

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

            $this->allocations->save($installment, $data['allocation_mode'] ?? 'equal', $data['allocations'] ?? [[
                'participant_id' => $participantId,
                'category_id' => $categoryId,
                'amount' => $data['total'],
            ]]);

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
        $description = $data['description'] ?? $installment->description;
        $cardName = array_key_exists('card_name', $data) ? $data['card_name'] : $installment->card_name;
        $payerId = array_key_exists('payer_id', $data) ? $data['payer_id'] : $installment->payer_id;
        $participantId = array_key_exists('participant_id', $data) ? $data['participant_id'] : $installment->participant_id;
        $paymentMethodId = $data['payment_method_id'] ?? $installment->payment_method_id;
        $categoryId = array_key_exists('category_id', $data) ? $data['category_id'] : $installment->category_id;
        $selfId = Participant::query()->where('is_default', true)->value('id');
        $categoryId = $participantId === null || (int) $participantId === (int) $selfId ? $categoryId : null;
        $financialScheduleChanged = $totalCents !== (int) $installment->total_cents || $count !== (int) $installment->installment_count;

        return DB::transaction(function () use ($installment, $data, $startDate, $count, $baseCents, $remainder, $totalCents, $description, $cardName, $payerId, $participantId, $paymentMethodId, $categoryId, $financialScheduleChanged): Installment {
            $installment->update([
                'start_date' => $startDate->toDateString(),
                'description' => $description,
                'card_name' => $cardName,
                'total_cents' => $totalCents,
                'installment_count' => $count,
                'payer_id' => $payerId,
                'participant_id' => $participantId,
                'payment_method_id' => $paymentMethodId,
                'category_id' => $categoryId,
            ]);

            $existing = $installment->occurrences()->get()->keyBy('installment_number');

            foreach (range(1, $count) as $number) {
                $existingOccurrence = $existing->get($number);
                $preserveAmount = ! $financialScheduleChanged && $existingOccurrence !== null && $existingOccurrence->archived_at === null;
                $attributes = [
                    'installment_number' => $number,
                    'purchased_at' => $startDate->addMonthsNoOverflow($number - 1)->toDateString(),
                    'amount_cents' => $preserveAmount ? $existingOccurrence->amount_cents : ($number === $count ? $baseCents + $remainder : $baseCents),
                    'description' => $description,
                    'card_name' => $cardName,
                    'payer_id' => $payerId,
                    'participant_id' => $participantId,
                    'payment_method_id' => $paymentMethodId,
                    'category_id' => $categoryId,
                    'is_adjusted' => $preserveAmount ? $existingOccurrence->is_adjusted : false,
                    'archived_at' => null,
                ];

                if ($existing->has($number)) {
                    $existingOccurrence = $existing->get($number);
                    $existingOccurrence->update($attributes);

                } else {
                    $installment->occurrences()->create($attributes);
                }
            }

            $installment->occurrences()
                ->where('installment_number', '>', $count)
                ->whereNull('archived_at')
                ->update(['archived_at' => now()]);

            if (array_key_exists('allocations', $data)) {
                $this->allocations->synchronizeRule($installment, $data['allocation_mode'] ?? $installment->allocation_mode ?? 'equal', $data['allocations']);
            } else {
                $this->allocations->synchronizeMaterialization($installment);
            }

            return $installment->fresh();
        });
    }

    /** @return array{installment: Installment, oldValues: array<string, mixed>, newValues: array<string, mixed>, metadata: array<string, mixed>} */
    public function rescheduleWithImpact(Installment $installment, array $data): array
    {
        $oldValues = $installment->getAttributes();
        $oldOccurrences = $this->occurrenceSnapshots($installment);
        $oldAllocations = $this->allocationSnapshots($installment);
        $updated = $this->reschedule($installment, $data);
        $newOccurrences = $this->occurrenceSnapshots($updated);
        $newAllocations = $this->allocationSnapshots($updated);
        $newValues = $updated->getAttributes();
        $ruleFields = ['start_date', 'description', 'card_name', 'total_cents', 'installment_count', 'payer_id', 'participant_id', 'payment_method_id', 'category_id'];
        $changedFields = collect($ruleFields)
            ->filter(fn (string $field): bool => (string) ($oldValues[$field] ?? null) !== (string) ($newValues[$field] ?? null))
            ->values()
            ->all();
        $oldOccurrenceMap = collect($oldOccurrences)->keyBy('id');
        $invoiceImpacts = collect($newOccurrences)->map(function (array $occurrence) use ($oldOccurrenceMap): array {
            $oldOccurrence = $oldOccurrenceMap->get($occurrence['id']);

            return [
                'occurrence_id' => $occurrence['id'],
                'occurrence_number' => $occurrence['installment_number'],
                'before' => $oldOccurrence === null ? null : [
                    'purchased_at' => $oldOccurrence['purchased_at'],
                    'amount_cents' => $oldOccurrence['amount_cents'],
                    'payment_method_id' => $oldOccurrence['payment_method_id'],
                    'archived_at' => $oldOccurrence['archived_at'],
                ],
                'after' => [
                    'purchased_at' => $occurrence['purchased_at'],
                    'amount_cents' => $occurrence['amount_cents'],
                    'payment_method_id' => $occurrence['payment_method_id'],
                    'archived_at' => $occurrence['archived_at'],
                ],
            ];
        })->values()->all();

        return [
            'installment' => $updated,
            'oldValues' => $oldValues,
            'newValues' => $newValues,
            'metadata' => [
                'type' => 'schedule_reschedule',
                'old_installment_count' => (int) $oldValues['installment_count'],
                'new_installment_count' => $updated->installment_count,
                'old_total_cents' => (int) $oldValues['total_cents'],
                'new_total_cents' => $updated->total_cents,
                'created_occurrences' => max(0, $updated->installment_count - (int) $oldValues['installment_count']),
                'archived_occurrences' => max(0, (int) $oldValues['installment_count'] - $updated->installment_count),
                'old_occurrences' => $oldOccurrences,
                'new_occurrences' => $newOccurrences,
                'old_allocations' => $oldAllocations,
                'new_allocations' => $newAllocations,
                'old_rule' => array_intersect_key($oldValues, array_flip($ruleFields)),
                'new_rule' => array_intersect_key($newValues, array_flip($ruleFields)),
                'changed_fields' => $changedFields,
                'affected_occurrence_ids' => collect($newOccurrences)->whereNull('archived_at')->pluck('id')->values()->all(),
                'invoice_impacts' => $invoiceImpacts,
            ],
        ];
    }

    /** @return array{oldStartDate: string, oldEndDate: string, oldInstallmentCount: int, oldTotalCents: int, installmentValues: list<array{number: int, date: string, amountCents: int}>, invoiceImpacts: list<array{label: string, installmentNumbers: list<int>, totalCents: int}>} */
    public function schedulePreview(Installment $installment): array
    {
        $occurrences = $installment->occurrences()
            ->whereNull('archived_at')
            ->with(['paymentMethod.invoiceSettings'])
            ->orderBy('installment_number')
            ->get();
        $invoiceImpacts = $occurrences->groupBy(fn (InstallmentOccurrence $occurrence): string => $this->invoiceImpactLabel($occurrence))
            ->map(fn (Collection $rows, string $label): array => [
                'label' => $label,
                'installmentNumbers' => $rows->pluck('installment_number')->map(fn (int $number): int => $number)->values()->all(),
                'totalCents' => (int) $rows->sum('amount_cents'),
            ])->values()->all();

        return [
            'oldStartDate' => $installment->start_date->toDateString(),
            'oldEndDate' => CarbonImmutable::instance($installment->start_date)->addMonthsNoOverflow($installment->installment_count - 1)->toDateString(),
            'oldInstallmentCount' => $installment->installment_count,
            'oldTotalCents' => $installment->total_cents,
            'installmentValues' => $occurrences->map(fn (InstallmentOccurrence $occurrence): array => [
                'number' => $occurrence->installment_number,
                'date' => $occurrence->purchased_at->toDateString(),
                'amountCents' => $occurrence->amount_cents,
            ])->values()->all(),
            'invoiceImpacts' => $invoiceImpacts,
        ];
    }

    public function installmentCountForRange(CarbonInterface $startDate, CarbonInterface $endDate): int
    {
        return $startDate->startOfMonth()->diffInMonths($endDate->startOfMonth()) + 1;
    }

    private function moneyToCents(string|int|float $value): int
    {
        return (int) round(((float) str_replace(',', '.', (string) $value)) * 100);
    }

    /**
     * @return list<array{
     *     id: int,
     *     installment_id: int,
     *     installment_number: int,
     *     purchased_at: string,
     *     description: string,
     *     card_name: string|null,
     *     amount_cents: int,
     *     payer_id: int|null,
     *     participant_id: int|null,
     *     payment_method_id: int|null,
     *     category_id: int|null,
     *     is_adjusted: bool|int,
     *     archived_at: string|null,
     *     created_at: string,
     *     updated_at: string
     * }>
     */
    private function occurrenceSnapshots(Installment $installment): array
    {
        return $installment->occurrences()->orderBy('installment_number')->get()->map(fn (InstallmentOccurrence $occurrence): array => $occurrence->getAttributes())->all();
    }

    /**
     * @return list<array{
     *     id: int,
     *     installment_occurrence_id: int,
     *     participant_id: int|null,
     *     category_id: int|null,
     *     amount_cents: int,
     *     percentage_basis_points: int|null
     * }>
     */
    private function allocationSnapshots(Installment $installment): array
    {
        return $installment->occurrences()
            ->with('allocations')
            ->orderBy('installment_number')
            ->get()
            ->flatMap(fn (InstallmentOccurrence $occurrence): Collection => $occurrence->allocations->map(fn (InstallmentAllocation $allocation): array => [
                'id' => $allocation->id,
                'installment_occurrence_id' => $allocation->installment_occurrence_id,
                'participant_id' => $allocation->participant_id,
                'category_id' => $allocation->category_id,
                'amount_cents' => $allocation->amount_cents,
                'percentage_basis_points' => $allocation->percentage_basis_points,
            ]))
            ->values()
            ->all();
    }

    private function invoiceImpactLabel(InstallmentOccurrence $occurrence): string
    {
        if ($occurrence->paymentMethod === null) {
            return 'Sem forma de pagamento';
        }

        $details = $this->invoiceSettings->detailsFor($occurrence->paymentMethod, $occurrence->purchased_at);

        if ($details === null) {
            return $occurrence->paymentMethod->name.' · movimentação de '.$occurrence->purchased_at->format('m/Y');
        }

        $cycle = ($details['dueDate'] ?? $details['closingDate'])->format('m/Y');

        return $occurrence->paymentMethod->name.' · fatura '.$cycle;
    }
}
