<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInstallmentRequest;
use App\Models\Category;
use App\Models\Installment;
use App\Models\Participant;
use App\Models\PaymentMethod;
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

    public function store(StoreInstallmentRequest $request, InstallmentService $service, BalanceService $balanceService): RedirectResponse
    {
        $service->create($request->validated());
        $balanceService->reconcileAll();

        return to_route('installments.index')->with('success', 'Parcelamento criado com sucesso.');
    }

    public function archive(Installment $installment): RedirectResponse
    {
        $installment->update(['archived_at' => now()]);
        $installment->occurrences()->whereDate('purchased_at', '>', now()->toDateString())->whereNull('archived_at')->update(['archived_at' => now()]);

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
