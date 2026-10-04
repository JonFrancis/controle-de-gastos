<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSalaryRequest;
use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;

class SalaryController extends Controller
{
    public function update(UpdateSalaryRequest $request): RedirectResponse
    {
        AppSetting::query()->updateOrCreate(
            ['id' => 1],
            ['monthly_salary_cents' => $this->moneyToCents($request->validated('amount'))],
        );

        return to_route('analysis', [
            'month' => $request->query('month'),
            'view' => $request->query('view'),
        ])->with('success', 'Salário mensal salvo.');
    }

    private function moneyToCents(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($decimal, 2, '0');
    }
}
