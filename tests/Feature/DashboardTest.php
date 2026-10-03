<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_starts_as_a_navigable_inertia_page(): void
    {
        $this->get('/')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('monthLabel', 'Outubro de 2026')
                ->where('pendingReview', 0)
                ->has('summary')
                ->has('catalogs.participants')
                ->has('catalogs.categories')
                ->has('catalogs.paymentMethods')
            );
    }
}
