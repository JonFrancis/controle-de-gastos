<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MonthlyNavigationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_monthly_urls_reproduce_the_requested_month_and_view_on_every_monthly_page(): void
    {
        $pages = [
            ['uri' => '/?month=2026-12&view=invoice', 'component' => 'Dashboard'],
            ['uri' => '/balances?month=2026-12&view=invoice', 'component' => 'Balances/Index'],
            ['uri' => '/purchases?month=2026-12&view=invoice', 'component' => 'Purchases/Index'],
            ['uri' => '/analysis?month=2026-12&view=invoice', 'component' => 'Analysis/Index'],
        ];

        foreach ($pages as $page) {
            $this->get($page['uri'])
                ->assertInertia(fn (Assert $response) => $response
                    ->component($page['component'])
                    ->where('selectedMonth', '2026-12')
                    ->where('view', 'invoice'));
        }
    }

    public function test_dashboard_navigation_urls_preserve_the_month_and_active_view(): void
    {
        $this->get('/?month=2026-12&view=invoice')
            ->assertInertia(fn (Assert $response) => $response
                ->where('balancesUrl', '/balances?month=2026-12&view=invoice')
                ->where('invoiceListUrl', '/purchases?month=2026-12&view=invoice'));
    }

    public function test_exportations_keep_their_explicit_period_confirmation(): void
    {
        $this->get('/exports?mode=period&start_date=2026-12-01&end_date=2026-12-31')
            ->assertInertia(fn (Assert $response) => $response
                ->component('Exports/Index')
                ->where('mode', 'period')
                ->where('startDate', '2026-12-01')
                ->where('endDate', '2026-12-31'));
    }

    public function test_history_keeps_its_action_filter_contract(): void
    {
        $this->get('/history?action=import')
            ->assertInertia(fn (Assert $response) => $response
                ->component('History/Index')
                ->where('selectedAction', 'import'));
    }
}
