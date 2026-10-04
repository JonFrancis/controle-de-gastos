<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\ReceiptApplication;
use App\Models\RecurrenceOccurrence;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class MonthlyAnalysisService
{
    public function __construct(private readonly InvoiceCycleService $invoiceCycleService) {}

    /** @return array<string, mixed> */
    public function analyze(string $selectedMonth, string $view, ?int $movementCategoryId = null): array
    {
        $month = Carbon::createFromFormat('!Y-m', $selectedMonth);
        $periodStart = $view === 'invoice' ? $month->copy()->subMonthNoOverflow()->startOfMonth() : $month->copy()->startOfMonth();
        $periodEnd = $month->copy()->endOfMonth();
        $items = $this->items($periodStart, $periodEnd)->filter(fn (array $item): bool => $view !== 'invoice' || $this->inInvoice($item, $selectedMonth))->values();

        return $this->buildAnalysis($items, $periodStart, $periodEnd, $movementCategoryId);
    }

    /** @return array<string, mixed> */
    public function analyzeRange(?CarbonInterface $periodStart, ?CarbonInterface $periodEnd): array
    {
        return $this->buildAnalysis($this->items($periodStart, $periodEnd), $periodStart, $periodEnd);
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function participantTotals(Collection $items): array
    {
        $totals = $items->groupBy('participantId')->map(fn (Collection $rows): int => (int) $rows->sum('amountCents'));
        $names = Participant::query()->whereIn('id', $totals->keys())->pluck('name', 'id');

        return $totals->map(fn (int $amountCents, int|string $participantId): array => [
            'id' => (int) $participantId,
            'name' => (string) $names->get($participantId),
            'amountCents' => $amountCents,
        ])->sortByDesc('amountCents')->values()->all();
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function buildAnalysis(Collection $items, ?CarbonInterface $periodStart, ?CarbonInterface $periodEnd, ?int $movementCategoryId = null): array
    {
        $chargeableItems = $items->reject(fn (array $item): bool => $this->isPending($item))->values();
        $selfId = $this->selfId();
        $participants = $this->participantRows($chargeableItems, $selfId, $periodStart, $periodEnd);
        $movementCategories = $this->movementCategories($chargeableItems, $selfId);
        $selectedMovementCategoryId = collect($movementCategories)->contains(fn (array $category): bool => $category['id'] === $movementCategoryId)
            ? $movementCategoryId
            : null;

        return [
            'summary' => $this->summary($chargeableItems, $selfId),
            'participantExpenses' => $this->participantTotals($chargeableItems),
            'participants' => $participants,
            'fullMessage' => $this->fullMessage($participants),
            'categories' => $this->groupOwnRows($chargeableItems, $selfId, 'categoryName'),
            'paymentMethods' => $this->groupOwnRows($chargeableItems, $selfId, 'paymentMethodName'),
            'paymentMethodTotals' => $this->groupRows($chargeableItems, 'paymentMethodName'),
            'weeklyMovement' => $this->weeklyMovement($chargeableItems, $selfId, $selectedMovementCategoryId),
            'movementCategories' => $movementCategories,
            'selectedMovementCategoryId' => $selectedMovementCategoryId,
            'origins' => $this->origins($chargeableItems),
            'purchaseReview' => $items->unique('sourceKey')->map(fn (array $item): array => [
                'key' => $item['key'],
                'date' => $item['date'],
                'description' => $item['description'],
                'cardName' => $item['cardName'],
                'amountCents' => $item['sourceAmountCents'],
                'origin' => $item['origin'],
                'paymentMethodName' => $item['paymentMethodName'],
                'participantName' => $item['participantName'],
                'pending' => $item['paymentMethodId'] === null,
            ])->unique('key')->values()->all(),
            'pendingReview' => $items->filter(fn (array $item): bool => $item['paymentMethodId'] === null)->unique('sourceKey')->count(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function summary(Collection $items, int $selfId): array
    {
        $bySource = $items->unique('sourceKey');
        $ownConsumption = (int) $items->filter(fn (array $item): bool => $item['participantId'] === $selfId)->sum('amountCents');
        $paidForOthers = (int) $items->filter(fn (array $item): bool => $item['payerId'] === $selfId && $item['participantId'] !== null && $item['participantId'] !== $selfId)->sum('amountCents');
        $salaryCents = AppSetting::query()->find(1)?->monthly_salary_cents;

        return [
            'ownConsumptionCents' => $ownConsumption,
            'paidForOthersCents' => $paidForOthers,
            'totalDisbursedCents' => (int) $bySource->filter(fn (array $item): bool => $item['payerId'] === $selfId)->sum('sourceAmountCents'),
            'salaryCents' => $salaryCents,
            'salaryRemainingCents' => $salaryCents === null ? null : $salaryCents - $ownConsumption,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function participantRows(Collection $items, int $selfId, ?CarbonInterface $periodStart, ?CarbonInterface $periodEnd): array
    {
        $sourceKeysByType = $items->pluck('key')->map(fn (string $sourceKey): array => explode(':', $sourceKey, 2))->groupBy(fn (array $parts): string => $parts[0])->map(fn (Collection $parts): array => $parts->pluck(1)->map(fn (string $id): int => (int) $id)->all());
        $itemsByParticipant = $items->filter(fn (array $item): bool => $item['payerId'] === $selfId)->groupBy('participantId');
        $applicationsQuery = ReceiptApplication::query()->whereNull('superseded_at')
            ->whereHas('receipt', function ($query) use ($periodStart, $periodEnd): void {
                $query->whereNull('archived_at')
                    ->when($periodStart, fn ($query, CarbonInterface $start) => $query->whereDate('received_at', '>=', $start))
                    ->when($periodEnd, fn ($query, CarbonInterface $end) => $query->whereDate('received_at', '<=', $end));
            });
        if ($sourceKeysByType->isNotEmpty()) {
            $applicationsQuery->where(function ($query) use ($sourceKeysByType): void {
                foreach ($sourceKeysByType as $sourceType => $sourceIds) {
                    $query->orWhere(fn ($sourceQuery) => $sourceQuery->where('source_type', $sourceType)->whereIn('source_id', $sourceIds));
                }
            });
        }
        $appliedBySource = $applicationsQuery->get()
            ->groupBy(fn (ReceiptApplication $application): string => $application->source_type.':'.$application->source_id)
            ->map(fn (Collection $rows): int => (int) $rows->sum('amount_cents'));

        return Participant::query()->where('id', '!=', $selfId)->orderBy('name')->get()->map(function (Participant $participant) use ($itemsByParticipant, $appliedBySource): array {
            $participantItems = $itemsByParticipant->get($participant->id, collect());
            $gross = (int) $participantItems->sum('amountCents');
            $abatements = (int) $participantItems->sum(fn (array $item): int => min($item['amountCents'], $appliedBySource[$item['key']] ?? 0));
            $items = $participantItems->map(fn (array $item): array => [
                'date' => $item['date'],
                'description' => $item['description'],
                'cardName' => $item['cardName'],
                'origin' => $item['origin'],
                'amountCents' => $item['amountCents'],
                'abatementCents' => min($item['amountCents'], $appliedBySource[$item['key']] ?? 0),
                'finalCents' => max(0, $item['amountCents'] - ($appliedBySource[$item['key']] ?? 0)),
            ])->values()->all();
            $final = $gross - $abatements;
            $status = $final > 0 ? 'chargeable' : 'settled';
            $row = ['id' => $participant->id, 'name' => $participant->name, 'grossCents' => $gross, 'abatementsCents' => $abatements, 'finalCents' => $final, 'status' => $status, 'items' => $items];

            return [...$row, 'message' => $this->participantMessage($row)];
        })->filter(fn (array $row): bool => $row['grossCents'] > 0 || $row['abatementsCents'] > 0)->values()->all();
    }

    /** @param list<array<string, mixed>> $participants */
    private function fullMessage(array $participants): string
    {
        $chargeable = collect($participants)->filter(fn (array $participant): bool => $participant['status'] === 'chargeable');
        $settled = collect($participants)->filter(fn (array $participant): bool => $participant['status'] === 'settled')->pluck('name');
        if ($chargeable->isEmpty()) {
            return $settled->isEmpty()
                ? 'Nenhuma cobrança a enviar neste período.'
                : "Nenhuma cobrança a enviar neste período.\n\nQuitados ou sem cobrança: ".$settled->implode(', ').'.';
        }

        $sections = $chargeable->map(fn (array $participant): string => $participant['name'].":\n".$this->messageDetails($participant['items'])."\nTotal bruto: ".$this->formatMoney($participant['grossCents'])."\nAbatimentos: ".$this->formatMoney($participant['abatementsCents'])."\nValor líquido: ".$this->formatMoney($participant['finalCents']))->implode("\n\n");

        if ($settled->isNotEmpty()) {
            $sections .= "\n\nQuitados ou sem cobrança: ".$settled->implode(', ').'.';
        }

        return "Cobranças do período:\n\n".$sections;
    }

    /** @param array<string, mixed> $participant */
    private function participantMessage(array $participant): string
    {
        if ($participant['status'] === 'settled') {
            return "Olá, {$participant['name']}!\n\nSua conta está quitada neste período. Nenhuma cobrança a enviar.";
        }

        return "Olá, {$participant['name']}!\n\nSegue o resumo das suas compras no período:\n".$this->messageDetails($participant['items'])."\n\nTotal bruto: ".$this->formatMoney($participant['grossCents'])."\nAbatimentos: ".$this->formatMoney($participant['abatementsCents'])."\nValor líquido: ".$this->formatMoney($participant['finalCents']);
    }

    /** @param list<array<string, mixed>> $items */
    private function messageDetails(array $items): string
    {
        return collect($items)->map(function (array $item): string {
            $line = '- '.$item['description'].' ('.Carbon::parse($item['date'])->format('d/m/Y').'): '.$this->formatMoney($item['finalCents']);
            if ($item['abatementCents'] > 0) {
                $line .= ' (abatimento '.$this->formatMoney($item['abatementCents']).')';
            }

            return $line;
        })->implode("\n");
    }

    private function formatMoney(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function groupOwnRows(Collection $items, int $selfId, string $field): array
    {
        return $items->filter(fn (array $item): bool => $item['participantId'] === $selfId)->groupBy(fn (array $item): string => $item[$field] ?? 'Sem informação')->map(fn (Collection $rows, string $name): array => ['name' => $name, 'amountCents' => (int) $rows->sum('amountCents')])->sortByDesc('amountCents')->values()->all();
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function groupRows(Collection $items, string $field): array
    {
        return $items
            ->filter(fn (array $item): bool => $item[$field] !== null)
            ->groupBy(fn (array $item): string => $item[$field])
            ->map(fn (Collection $rows, string $name): array => ['name' => $name, 'amountCents' => (int) $rows->sum('amountCents')])
            ->sortByDesc('amountCents')
            ->values()
            ->all();
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function movementCategories(Collection $items, int $selfId): array
    {
        return $items
            ->filter(fn (array $item): bool => $item['participantId'] === $selfId && $item['categoryId'] !== null)
            ->map(fn (array $item): array => ['id' => $item['categoryId'], 'name' => $item['categoryName']])
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function weeklyMovement(Collection $items, int $selfId, ?int $categoryId): array
    {
        return $items
            ->filter(fn (array $item): bool => $item['participantId'] === $selfId)
            ->when($categoryId !== null, fn (Collection $rows) => $rows->filter(fn (array $item): bool => $item['categoryId'] === $categoryId))
            ->groupBy(fn (array $item): int => intdiv(Carbon::parse($item['date'])->day - 1, 7) + 1)
            ->map(fn (Collection $rows, int $week): array => ['week' => $week, 'label' => 'Semana '.$week, 'amountCents' => (int) $rows->sum('amountCents')])
            ->sortKeys()
            ->values()
            ->all();
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function origins(Collection $items): array
    {
        return $items->unique('sourceKey')->groupBy('origin')->map(fn (Collection $rows): array => ['amountCents' => (int) $rows->sum('sourceAmountCents'), 'count' => $rows->count()])->all() + [
            'manual' => ['amountCents' => 0, 'count' => 0],
            'installment' => ['amountCents' => 0, 'count' => 0],
            'recurrence' => ['amountCents' => 0, 'count' => 0],
        ];
    }

    /** @param array<string, mixed> $item */
    private function isPending(array $item): bool
    {
        return $item['paymentMethodId'] === null;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function items(?CarbonInterface $start, ?CarbonInterface $end): Collection
    {
        $items = collect();
        $selfId = $this->selfId();
        $participantNames = Participant::query()->pluck('name', 'id');
        $paymentMethods = PaymentMethod::query()->get(['id', 'name', 'type', 'closing_day'])->keyBy('id');
        $purchases = Purchase::query()->active()->with(['allocations.category', 'paymentMethod', 'category'])
            ->when($start, fn ($query, CarbonInterface $periodStart) => $query->whereDate('purchased_at', '>=', $periodStart))
            ->when($end, fn ($query, CarbonInterface $periodEnd) => $query->whereDate('purchased_at', '<=', $periodEnd))
            ->orderBy('purchased_at')->orderBy('id')->get();
        foreach ($purchases as $purchase) {
            $allocations = $purchase->allocations;
            if ($allocations->isEmpty()) {
                $items->push($this->row($purchase->origin ?: 'manual', 'purchase', $purchase->id, 'purchase:'.$purchase->id, $purchase->purchased_at, $purchase->description, $purchase->card_name, $purchase->amount_cents, $purchase->amount_cents, $purchase->payer_id ?? $selfId, $purchase->participant_id ?? $selfId, $purchase->category_id, $purchase->category?->name, $purchase->payment_method_id, $participantNames, $paymentMethods));

                continue;
            }
            foreach ($allocations as $allocation) {
                $items->push($this->row($purchase->origin ?: 'manual', 'purchase_allocation', $allocation->id, 'purchase:'.$purchase->id, $purchase->purchased_at, $purchase->description, $purchase->card_name, $allocation->amount_cents, $purchase->amount_cents, $purchase->payer_id ?? $selfId, $allocation->participant_id ?? $selfId, $allocation->category_id, $allocation->category?->name, $purchase->payment_method_id, $participantNames, $paymentMethods));
            }
        }
        foreach (InstallmentOccurrence::query()->whereNull('archived_at')->with(['installment', 'paymentMethod', 'category'])
            ->when($start, fn ($query, CarbonInterface $periodStart) => $query->whereDate('purchased_at', '>=', $periodStart))
            ->when($end, fn ($query, CarbonInterface $periodEnd) => $query->whereDate('purchased_at', '<=', $periodEnd))
            ->orderBy('purchased_at')->orderBy('id')->get() as $occurrence) {
            $items->push($this->row('installment', 'installment_occurrence', $occurrence->id, 'installment_occurrence:'.$occurrence->id, $occurrence->purchased_at, $occurrence->description, $occurrence->card_name, $occurrence->amount_cents, $occurrence->amount_cents, $occurrence->payer_id ?? $selfId, $occurrence->participant_id ?? $selfId, $occurrence->category_id, $occurrence->category?->name, $occurrence->payment_method_id, $participantNames, $paymentMethods));
        }
        foreach (RecurrenceOccurrence::query()->whereNull('archived_at')->with(['recurrence', 'paymentMethod', 'category'])
            ->when($start, fn ($query, CarbonInterface $periodStart) => $query->whereDate('purchased_at', '>=', $periodStart))
            ->when($end, fn ($query, CarbonInterface $periodEnd) => $query->whereDate('purchased_at', '<=', $periodEnd))
            ->orderBy('purchased_at')->orderBy('id')->get() as $occurrence) {
            $items->push($this->row('recurrence', 'recurrence_occurrence', $occurrence->id, 'recurrence_occurrence:'.$occurrence->id, $occurrence->purchased_at, $occurrence->description, $occurrence->card_name, $occurrence->amount_cents, $occurrence->amount_cents, $occurrence->payer_id ?? $selfId, $occurrence->participant_id ?? $selfId, $occurrence->category_id, $occurrence->category?->name, $occurrence->payment_method_id, $participantNames, $paymentMethods));
        }

        return $items->sortBy([['date', 'asc'], ['key', 'asc']])->values();
    }

    /** @param array<string, mixed> $item */
    private function inInvoice(array $item, string $selectedMonth): bool
    {
        if ($item['paymentType'] !== PaymentMethod::TYPE_CREDIT || ! $item['closingDay']) {
            return false;
        }

        return $this->invoiceCycleService->closingDate(Carbon::parse($item['date']), $item['closingDay'])->format('Y-m') === $selectedMonth;
    }

    /**
     * @param  Collection<int|string, string>  $participantNames
     * @param  Collection<int|string, PaymentMethod>  $paymentMethods
     * @return array<string, mixed>
     */
    private function row(string $origin, string $sourceType, int $sourceId, string $sourceKey, CarbonInterface $date, string $description, ?string $cardName, int $amountCents, int $sourceAmountCents, int $payerId, ?int $participantId, ?int $categoryId, ?string $categoryName, ?int $paymentMethodId, Collection $participantNames, Collection $paymentMethods): array
    {
        $paymentMethod = $paymentMethods->get($paymentMethodId);

        return ['key' => $sourceType.':'.$sourceId, 'sourceKey' => $sourceKey, 'origin' => $origin, 'date' => $date->toDateString(), 'description' => $description, 'cardName' => $cardName, 'amountCents' => $amountCents, 'sourceAmountCents' => $sourceAmountCents, 'payerId' => $payerId, 'participantId' => $participantId, 'participantName' => $participantNames->get($participantId), 'categoryId' => $categoryId, 'categoryName' => $categoryName, 'paymentMethodName' => $paymentMethod?->name, 'paymentMethodId' => $paymentMethodId, 'paymentType' => $paymentMethod?->type, 'closingDay' => $paymentMethod?->closing_day];
    }

    private function selfId(): int
    {
        return (int) Participant::query()->where('is_default', true)->value('id');
    }
}
