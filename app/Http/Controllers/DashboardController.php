<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $selectedMonth = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : now()->format('Y-m');
        $month = Carbon::createFromFormat('Y-m', $selectedMonth);
        $selfId = Participant::query()->where('is_default', true)->value('id');
        $purchases = Purchase::query()->active()->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name', 'category:id,name', 'allocations.participant:id,name', 'allocations' => fn ($query) => $query->with('category:id,name')])->whereBetween('purchased_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])->orderByDesc('purchased_at')->get();

        return Inertia::render('Dashboard', [
            'monthLabel' => ucfirst($month->locale('pt_BR')->translatedFormat('F \d\e Y')),
            'selectedMonth' => $selectedMonth,
            'summary' => ['ownExpenses' => 'R$ 0,00', 'toReceive' => 'R$ 0,00', 'salaryRemaining' => 'R$ 0,00'],
            'pendingReview' => 0,
            'monthTotalCents' => (int) $purchases->sum('amount_cents'),
            'purchases' => $purchases->map(fn (Purchase $purchase) => [
                'id' => $purchase->id, 'purchasedAt' => $purchase->purchased_at->toDateString(), 'description' => $purchase->description, 'cardName' => $purchase->card_name,
                'amountCents' => $purchase->amount_cents, 'payer' => $purchase->payer?->name, 'participant' => $purchase->allocations->isNotEmpty() ? $purchase->allocations->map(fn ($allocation) => $allocation->participant?->name)->filter()->join(', ') : $purchase->participant?->name,
                'paymentMethod' => $purchase->paymentMethod?->name, 'category' => $purchase->allocations->firstWhere('participant_id', $selfId)?->category?->name ?? $purchase->category?->name,
            ])->values(),
            'catalogs' => [
                'participants' => Participant::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
                'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
                'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'type', 'closing_day']),
            ],
        ]);
    }
}
