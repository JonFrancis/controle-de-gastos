<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRecurrenceRequest;
use App\Http\Requests\UpdateRecurrenceRequest;
use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Services\RecurrenceService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RecurrenceController extends Controller
{
    public function index(RecurrenceService $service): Response
    {
        $service->ensureOccurrencesForRange(now()->startOfMonth(), now()->addMonthsNoOverflow(12)->endOfMonth());
        $recurrences = Recurrence::query()
            ->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name,type,closing_day', 'category:id,name', 'occurrences' => fn ($query) => $query->orderBy('purchased_at')])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Recurrence $recurrence): array => [
                'id' => $recurrence->id,
                'startDate' => $recurrence->start_date->toDateString(),
                'endDate' => $recurrence->end_date?->toDateString(),
                'dayOfMonth' => $recurrence->day_of_month,
                'description' => $recurrence->description,
                'cardName' => $recurrence->card_name,
                'amountCents' => $recurrence->amount_cents,
                'payer' => $recurrence->payer?->name,
                'participant' => $recurrence->participant?->name,
                'paymentMethod' => $recurrence->paymentMethod?->name,
                'category' => $recurrence->category?->name,
                'active' => $recurrence->active,
                'occurrences' => $recurrence->occurrences->map(fn ($occurrence): array => [
                    'id' => $occurrence->id,
                    'purchasedAt' => $occurrence->purchased_at->toDateString(),
                    'amountCents' => $occurrence->amount_cents,
                    'isAdjusted' => $occurrence->is_adjusted,
                    'archivedAt' => $occurrence->archived_at?->toIso8601String(),
                ])->values(),
            ]);

        return Inertia::render('Recurrences/Index', ['recurrences' => $recurrences, 'flash' => ['success' => session('success')]]);
    }

    public function create(): Response
    {
        return Inertia::render('Recurrences/Create', $this->catalogs());
    }

    public function store(StoreRecurrenceRequest $request, RecurrenceService $service): RedirectResponse
    {
        $service->create($request->validated());

        return to_route('recurrences.index')->with('success', 'Recorrência criada com sucesso.');
    }

    public function edit(Recurrence $recurrence): Response
    {
        return Inertia::render('Recurrences/Edit', ['recurrence' => [
            'id' => $recurrence->id,
            'description' => $recurrence->description,
            'startDate' => $recurrence->start_date->toDateString(),
            'endDate' => $recurrence->end_date?->toDateString(),
            'active' => $recurrence->active,
        ]]);
    }

    public function update(UpdateRecurrenceRequest $request, Recurrence $recurrence, RecurrenceService $service): RedirectResponse
    {
        $data = $request->validated();
        $recurrence->update(['end_date' => $data['end_date'] ?? null, 'active' => $data['active']]);
        $this->synchronizeFutureOccurrences($recurrence, $service);

        return to_route('recurrences.index')->with('success', 'Recorrência atualizada com sucesso.');
    }

    public function activate(Recurrence $recurrence, RecurrenceService $service): RedirectResponse
    {
        $recurrence->update(['active' => true]);
        $futureOccurrences = $recurrence->occurrences()->whereDate('purchased_at', '>=', now()->toDateString());

        if ($recurrence->end_date !== null) {
            $futureOccurrences->whereDate('purchased_at', '<=', $recurrence->end_date->toDateString());
        }

        $futureOccurrences->update(['archived_at' => null]);
        $service->ensureOccurrencesForRange(now()->startOfMonth(), now()->addMonthsNoOverflow(12)->endOfMonth());

        return to_route('recurrences.index')->with('success', 'Recorrência ativada.');
    }

    public function deactivate(Recurrence $recurrence): RedirectResponse
    {
        $recurrence->update(['active' => false]);
        $recurrence->occurrences()->whereDate('purchased_at', '>', now()->toDateString())->whereNull('archived_at')->update(['archived_at' => now()]);

        return to_route('recurrences.index')->with('success', 'Recorrência desativada.');
    }

    /** @return array<string, mixed> */
    private function catalogs(): array
    {
        return [
            'participants' => Participant::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'type', 'closing_day']),
        ];
    }

    private function synchronizeFutureOccurrences(Recurrence $recurrence, RecurrenceService $service): void
    {
        if (! $recurrence->active) {
            $recurrence->occurrences()->whereDate('purchased_at', '>', now()->toDateString())->whereNull('archived_at')->update(['archived_at' => now()]);

            return;
        }

        $futureOccurrences = $recurrence->occurrences()->whereDate('purchased_at', '>=', now()->toDateString());

        if ($recurrence->end_date !== null) {
            $recurrence->occurrences()->whereDate('purchased_at', '>', $recurrence->end_date->toDateString())->update(['archived_at' => now()]);
            $futureOccurrences->whereDate('purchased_at', '<=', $recurrence->end_date->toDateString());
        }

        $futureOccurrences->update(['archived_at' => null]);
        $service->ensureOccurrencesForRange(now()->startOfMonth(), now()->addMonthsNoOverflow(12)->endOfMonth());
    }
}
