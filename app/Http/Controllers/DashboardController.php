<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'monthLabel' => 'Outubro de 2026',
            'summary' => ['ownExpenses' => 'R$ 0,00', 'toReceive' => 'R$ 0,00', 'salaryRemaining' => 'R$ 0,00'],
            'pendingReview' => 0,
            'catalogs' => [
                'participants' => Participant::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
                'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
                'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'type', 'closing_day']),
            ],
        ]);
    }
}
