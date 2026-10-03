<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_the_dashboard_starts_as_a_navigable_inertia_page(): void
    {
        $this->get('/')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('monthLabel', 'Outubro de 2026')
                ->where('pendingReview', 0)
                ->has('summary')
            );
    }
}
