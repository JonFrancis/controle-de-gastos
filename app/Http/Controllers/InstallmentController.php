<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInstallmentRequest;
use App\Http\Requests\UpdateInstallmentScheduleRequest;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Installment;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Services\AuditService;
use App\Services\BalanceService;
use App\Services\InstallmentService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InstallmentController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Installments/Create', $this->catalogs());
    }

    public function index(): Response
    {
        $installments = Installment::query()
            ->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name,type,closing_day', 'category:id,name', 'occurrences' => fn ($query) => $query->orderBy('installment_number')])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Installment $installment): array => [
                'id' => $installment->id,
                'startDate' => $installment->start_date->toDateString(),
                'description' => $installment->description,
                'cardName' => $installment->card_name,
                'totalCents' => $installment->total_cents,
                'installmentCount' => $installment->installment_count,
                'payer' => $installment->payer?->name,
                'participant' => $installment->participant?->name,
                'paymentMethod' => $installment->paymentMethod?->name,
                'category' => $installment->category?->name,
                'archivedAt' => $installment->archived_at?->toIso8601String(),
                'occurrences' => $installment->occurrences->map(fn ($occurrence): array => [
                    'id' => $occurrence->id,
                    'number' => $occurrence->installment_number,
                    'purchasedAt' => $occurrence->purchased_at->toDateString(),
                    'amountCents' => $occurrence->amount_cents,
                    'isAdjusted' => $occurrence->is_adjusted,
                    'archivedAt' => $occurrence->archived_at?->toIso8601String(),
                ])->values(),
            ]);

        return Inertia::render('Installments/Index', [
            'installments' => $installments,
            'flash' => ['success' => session('success')],
        ]);
    }

    public function store(StoreInstallmentRequest $request, InstallmentService $service, BalanceService $balanceService, AuditService $audit): RedirectResponse
    {
        $installment = $service->create($request->validated());
        $audit->record(AuditLog::ACTION_CREATE, $installment, newValues: $installment->getAttributes());
        $balanceService->reconcileAll();

        return to_route('installments.index')->with('success', 'Parcelamento criado com sucesso.');
    }

    public function updateSchedule(UpdateInstallmentScheduleRequest $request, Installment $installment, InstallmentService $service, BalanceService $balanceService, AuditService $audit): RedirectResponse
    {
        $oldValues = $installment->getAttributes();
        $oldOccurrences = $installment->occurrences()->orderBy('installment_number')->get()->map(fn ($occurrence): array => $occurrence->getAttributes())->all();
        $updated = $service->reschedule($installment, $request->validated());
        $balanceService->reconcileAll();
        $newOccurrences = $updated->occurrences()->orderBy('installment_number')->get()->map(fn ($occurrence): array => $occurrence->getAttributes())->all();
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
        $audit->record(AuditLog::ACTION_UPDATE, $updated, oldValues: $oldValues, newValues: $updated->getAttributes(), metadata: [
            'type' => 'schedule_reschedule',
            'old_installment_count' => (int) $oldValues['installment_count'],
            'new_installment_count' => $updated->installment_count,
            'old_total_cents' => (int) $oldValues['total_cents'],
            'new_total_cents' => $updated->total_cents,
            'created_occurrences' => max(0, $updated->installment_count - (int) $oldValues['installment_count']),
            'archived_occurrences' => max(0, (int) $oldValues['installment_count'] - $updated->installment_count),
            'old_occurrences' => $oldOccurrences,
            'new_occurrences' => $newOccurrences,
            'old_rule' => array_intersect_key($oldValues, array_flip($ruleFields)),
            'new_rule' => array_intersect_key($newValues, array_flip($ruleFields)),
            'changed_fields' => $changedFields,
            'affected_occurrence_ids' => collect($newOccurrences)->whereNull('archived_at')->pluck('id')->values()->all(),
            'invoice_impacts' => $invoiceImpacts,
            'balances_reconciled' => true,
        ]);

        return to_route('installments.index')->with('success', 'Cronograma do parcelamento atualizado com sucesso.');
    }

    public function archive(Installment $installment, AuditService $audit): RedirectResponse
    {
        $oldValues = $installment->getAttributes();
        $installment->update(['archived_at' => now()]);
        $installment->occurrences()->whereDate('purchased_at', '>', now()->toDateString())->whereNull('archived_at')->update(['archived_at' => now()]);
        $audit->record(AuditLog::ACTION_ARCHIVE, $installment, oldValues: $oldValues, newValues: $installment->fresh()->getAttributes());

        return to_route('installments.index')->with('success', 'Parcelamento encerrado.');
    }

    private function catalogs(): array
    {
        return [
            'participants' => Participant::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'type', 'closing_day']),
        ];
    }
}
