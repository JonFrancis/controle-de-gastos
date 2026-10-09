<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInstallmentRequest;
use App\Http\Requests\UpdateInstallmentScheduleRequest;
use App\Models\AuditLog;
use App\Models\Installment;
use App\Models\Participant;
use App\Services\AuditService;
use App\Services\BalanceService;
use App\Services\InstallmentCatalogService;
use App\Services\InstallmentService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InstallmentController extends Controller
{
    public function create(InstallmentCatalogService $catalogs): Response
    {
        return Inertia::render('Installments/Create', $catalogs->all());
    }

    public function index(): Response
    {
        $selfId = (int) Participant::query()->where('is_default', true)->value('id');
        $installments = Installment::query()
            ->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name,type,closing_day', 'category:id,name', 'occurrences' => fn ($query) => $query->orderBy('installment_number'), 'occurrences.allocations.participant:id,name', 'occurrences.allocations.category:id,name'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (Installment $installment) use ($selfId): array {
                $activeOccurrences = $installment->occurrences->whereNull('archived_at');
                $allocations = $activeOccurrences->flatMap->allocations;
                $selfAllocation = $allocations->first(fn ($allocation): bool => $allocation->participant_id === null || (int) $allocation->participant_id === $selfId);

                return [
                    'id' => $installment->id,
                    'startDate' => $installment->start_date->toDateString(),
                    'description' => $installment->description,
                    'cardName' => $installment->card_name,
                    'totalCents' => $installment->total_cents,
                    'installmentCount' => $installment->installment_count,
                    'payer' => $installment->payer?->name,
                    'participant' => $allocations->isNotEmpty()
                        ? $allocations->map(fn ($allocation): string => $allocation->participant?->name ?? 'Eu')->unique()->join(', ')
                        : $installment->participant?->name,
                    'paymentMethod' => $installment->paymentMethod?->name,
                    'category' => $allocations->isNotEmpty() ? $selfAllocation?->category?->name : $installment->category?->name,
                    'archivedAt' => $installment->archived_at?->toIso8601String(),
                    'occurrences' => $installment->occurrences->map(fn ($occurrence): array => [
                        'id' => $occurrence->id,
                        'number' => $occurrence->installment_number,
                        'purchasedAt' => $occurrence->purchased_at->toDateString(),
                        'amountCents' => $occurrence->amount_cents,
                        'isAdjusted' => $occurrence->is_adjusted,
                        'archivedAt' => $occurrence->archived_at?->toIso8601String(),
                    ])->values(),
                ];
            });

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
        $result = $service->rescheduleWithImpact($installment, $request->validated());
        $balanceService->reconcileAll();
        $audit->record(AuditLog::ACTION_UPDATE, $result['installment'], oldValues: $result['oldValues'], newValues: $result['newValues'], metadata: [...$result['metadata'], 'balances_reconciled' => true]);

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
}
