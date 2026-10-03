<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Dashboard', [
        'monthLabel' => 'Outubro de 2026',
        'summary' => [
            'ownExpenses' => 'R$ 0,00',
            'toReceive' => 'R$ 0,00',
            'salaryRemaining' => 'R$ 0,00',
        ],
        'pendingReview' => 0,
    ]);
});
