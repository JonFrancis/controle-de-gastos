<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Installment;
use App\Models\InstallmentAllocation;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallmentAllocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_installment_materializes_equal_allocations_with_exact_occurrence_totals(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $category = Category::create(['name' => 'Casa', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);

        $response = $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra compartilhada',
            'total' => '100,00',
            'installment_count' => 3,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'equal',
            'allocations' => [
                ['participant_id' => $self->id, 'category_id' => $category->id],
                ['participant_id' => $maria->id],
            ],
        ]);

        $response->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $this->assertSame('equal', $installment->allocation_mode);
        $this->assertSame([3333, 3333, 3334], $installment->occurrences()->orderBy('installment_number')->pluck('amount_cents')->all());
        $this->assertSame([
            [1667, 1666],
            [1666, 1667],
            [1667, 1667],
        ], $installment->occurrences()->with('allocations')->orderBy('installment_number')->get()->map(fn ($occurrence): array => $occurrence->allocations->sortBy('participant_id')->pluck('amount_cents')->all())->all());
        $this->assertDatabaseHas('installment_allocations', ['participant_id' => $self->id, 'category_id' => $category->id]);
        $this->assertDatabaseHas('installment_allocations', ['participant_id' => $maria->id, 'category_id' => null]);
    }

    public function test_amount_mode_preserves_defined_participant_totals_across_occurrences(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra por valores',
            'total' => '100,00',
            'installment_count' => 3,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '60,00'],
                ['participant_id' => $maria->id, 'amount' => '40,00'],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $this->assertSame(6000, InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->where('participant_id', $self->id)->sum('amount_cents'));
        $this->assertSame(4000, InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->where('participant_id', $maria->id)->sum('amount_cents'));
        $this->assertSame([3333, 3333, 3334], $installment->occurrences()->with('allocations')->orderBy('installment_number')->get()->map(fn ($occurrence): int => (int) $occurrence->allocations->sum('amount_cents'))->all());
    }

    public function test_percentage_mode_rejects_invalid_sums(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->from('/installments')->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Percentuais inválidos',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'percentage',
            'allocations' => [
                ['participant_id' => $self->id, 'percentage' => '30,00'],
                ['participant_id' => $maria->id, 'percentage' => '60,00'],
            ],
        ])->assertRedirect('/installments')->assertSessionHasErrors('allocations');
    }

    public function test_percentage_mode_materializes_exact_percentages(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra percentual',
            'total' => '100,00',
            'installment_count' => 3,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'percentage',
            'allocations' => [
                ['participant_id' => $self->id, 'percentage' => '25,00'],
                ['participant_id' => $maria->id, 'percentage' => '75,00'],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $this->assertSame(2500, InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->where('participant_id', $self->id)->sum('amount_cents'));
        $this->assertSame(7500, InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->where('participant_id', $maria->id)->sum('amount_cents'));
        $this->assertSame([3333, 3333, 3334], $installment->occurrences()->with('allocations')->orderBy('installment_number')->get()->map(fn ($occurrence): int => (int) $occurrence->allocations->sum('amount_cents'))->all());
    }

    public function test_adjusting_one_occurrence_redistributes_only_its_allocations_proportionally(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra ajustável',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '50,00'],
                ['participant_id' => $maria->id, 'amount' => '50,00'],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $occurrences = $installment->occurrences()->orderBy('installment_number')->get();

        $this->patch("/installment-occurrences/{$occurrences[0]->id}", ['amount' => '75,00'])->assertRedirect('/installments');

        $updated = $installment->fresh()->occurrences()->with('allocations')->orderBy('installment_number')->get();
        $this->assertSame([7500, 5000], $updated->pluck('amount_cents')->all());
        $this->assertSame([[3750, 3750], [2500, 2500]], $updated->map(fn ($occurrence): array => $occurrence->allocations->sortBy('participant_id')->pluck('amount_cents')->all())->all());
    }

    public function test_adjusting_an_occurrence_remaps_existing_receipt_applications_to_preserved_rateios(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra com recebimento histórico',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '50,00'],
                ['participant_id' => $maria->id, 'amount' => '50,00'],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $occurrence = $installment->occurrences()->where('installment_number', 1)->firstOrFail();
        $allocation = $occurrence->allocations()->where('participant_id', $self->id)->firstOrFail();
        $allocation->delete();
        $allocation = InstallmentAllocation::factory()
            ->forOccurrence($occurrence)
            ->forParticipant($self)
            ->create(['amount_cents' => 5000]);
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-20', 'amount_cents' => 1000]);
        $application = ReceiptApplication::create([
            'receipt_id' => $receipt->id,
            'source_type' => 'installment_allocation',
            'source_id' => $allocation->id,
            'amount_cents' => 1000,
            'source' => 'manual',
        ]);

        $this->patch("/installment-occurrences/{$occurrence->id}", ['amount' => '75,00'])->assertRedirect('/installments');

        $remappedAllocation = $occurrence->fresh()->allocations()->where('participant_id', $self->id)->firstOrFail();
        $this->assertSame($allocation->id, $remappedAllocation->id);
        $this->assertSame($remappedAllocation->id, $application->fresh()->source_id);
        $this->assertModelExists($application->fresh());
    }

    public function test_edit_form_reloads_the_total_rateio_rule_after_an_adjusted_occurrence(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra com regra editável',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '50,00'],
                ['participant_id' => $maria->id, 'amount' => '50,00'],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $occurrence = $installment->occurrences()->where('installment_number', 1)->firstOrFail();

        $this->patch("/installment-occurrences/{$occurrence->id}", ['amount' => '75,00'])->assertRedirect('/installments');

        $this->get("/installment-occurrences/{$occurrence->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installments/Edit')
                ->where('installment.allocationMode', 'amount')
                ->where('installment.allocations.0.amountCents', 5000)
                ->where('installment.allocations.1.amountCents', 5000)
                ->where('installment.allocations.0.participantId', $self->id)
                ->where('installment.allocations.1.participantId', $maria->id));
    }

    public function test_complete_edit_accepts_the_normalized_rateio_after_adjusting_50_to_75(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra com edição após ajuste',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '50,00'],
                ['participant_id' => $maria->id, 'amount' => '50,00'],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $occurrence = $installment->occurrences()->where('installment_number', 1)->firstOrFail();

        $this->patch("/installment-occurrences/{$occurrence->id}", ['amount' => '75,00'])->assertRedirect('/installments');

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '50,00'],
                ['participant_id' => $maria->id, 'amount' => '50,00'],
            ],
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $occurrences = $installment->fresh()->occurrences()->with('allocations')->orderBy('installment_number')->get();
        $this->assertSame([7500, 5000], $occurrences->map(fn ($row): int => (int) $row->allocations->sum('amount_cents'))->all());
    }

    public function test_adjusting_an_occurrence_records_old_and_new_rateios_in_the_audit_log(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra com ajuste auditado',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '50,00'],
                ['participant_id' => $maria->id, 'amount' => '50,00'],
            ],
        ])->assertRedirect('/installments');

        $occurrence = Installment::query()->latest('id')->firstOrFail()->occurrences()->where('installment_number', 1)->firstOrFail();

        $this->patch("/installment-occurrences/{$occurrence->id}", ['amount' => '75,00'])->assertRedirect('/installments');

        $audit = AuditLog::query()->where('action', AuditLog::ACTION_UPDATE)->latest('id')->firstOrFail();
        $this->assertSame(2, count($audit->old_values['allocations']));
        $this->assertSame(2, count($audit->new_values['allocations']));
        $this->assertSame(2500, $audit->old_values['allocations'][0]['amount_cents']);
        $this->assertSame(3750, $audit->new_values['allocations'][0]['amount_cents']);
        $this->assertSame('occurrence_adjustment', $audit->metadata['type']);
        $this->assertSame(3750, $audit->metadata['new_allocations'][0]['amount_cents']);
    }

    public function test_legacy_multi_occurrence_rateios_load_the_default_participant_and_category_in_the_edit_form(): void
    {
        [$self, , $paymentMethod] = $this->catalogs();
        $category = Category::create(['name' => 'Categoria legada', 'active' => true]);
        $installment = Installment::create([
            'start_date' => '2026-10-12',
            'description' => 'Parcelamento legado com Eu',
            'total_cents' => 10000,
            'installment_count' => 2,
            'allocation_mode' => 'equal',
            'payment_method_id' => $paymentMethod->id,
            'participant_id' => null,
            'category_id' => $category->id,
        ]);

        foreach ([1 => '2026-10-12', 2 => '2026-11-12'] as $number => $date) {
            $occurrence = $installment->occurrences()->create([
                'installment_number' => $number,
                'purchased_at' => $date,
                'description' => $installment->description,
                'amount_cents' => 5000,
                'payment_method_id' => $paymentMethod->id,
                'participant_id' => null,
                'category_id' => $category->id,
            ]);
            $occurrence->allocations()->create([
                'participant_id' => null,
                'category_id' => $category->id,
                'amount_cents' => 5000,
                'percentage_basis_points' => null,
            ]);
        }

        $this->get("/installment-occurrences/{$installment->occurrences()->firstOrFail()->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installments/Edit')
                ->where('installment.allocations.0.participantId', $self->id)
                ->where('installment.allocations.0.categoryId', $category->id));
    }

    public function test_complete_rateio_edit_after_an_adjustment_closes_every_occurrence_without_remainder(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra com ajuste e Rateio',
            'total' => '100,00',
            'installment_count' => 3,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'equal',
            'allocations' => [
                ['participant_id' => $self->id],
                ['participant_id' => $maria->id],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $adjustedOccurrence = $installment->occurrences()->where('installment_number', 2)->firstOrFail();

        $this->patch("/installment-occurrences/{$adjustedOccurrence->id}", ['amount' => '35,00'])->assertRedirect('/installments');

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-12-12',
            'total' => '100,00',
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '70,00'],
                ['participant_id' => $maria->id, 'amount' => '30,00'],
            ],
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $occurrences = $installment->fresh()->occurrences()->with('allocations')->whereNull('archived_at')->orderBy('installment_number')->get();
        $this->assertSame([3333, 3500, 3334], $occurrences->pluck('amount_cents')->all());
        $this->assertSame([3333, 3500, 3334], $occurrences->map(fn ($occurrence): int => (int) $occurrence->allocations->sum('amount_cents'))->all());
    }

    public function test_schedule_audit_payloads_include_old_and_new_rateios(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra auditada',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'equal',
            'allocations' => [
                ['participant_id' => $self->id],
                ['participant_id' => $maria->id],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '70,00'],
                ['participant_id' => $maria->id, 'amount' => '30,00'],
            ],
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $audit = AuditLog::query()->where('action', AuditLog::ACTION_UPDATE)->latest('id')->firstOrFail();
        $this->assertSame(4, count($audit->old_values['allocations']));
        $this->assertSame(4, count($audit->new_values['allocations']));
        $this->assertSame(2500, $audit->old_values['allocations'][0]['amount_cents']);
        $this->assertSame(3500, $audit->new_values['allocations'][0]['amount_cents']);
        $this->assertSame(2, count($audit->metadata['old_occurrences'][0]['allocations']));
        $this->assertSame(2, count($audit->metadata['new_occurrences'][0]['allocations']));
    }

    public function test_rateio_validation_messages_use_the_glossary_term(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->from('/installments')->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Rateio inválido',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '30,00'],
                ['participant_id' => $maria->id, 'amount' => '30,00'],
            ],
        ])->assertRedirect('/installments')->assertSessionHasErrors(['allocations' => 'A soma dos Rateios precisa ser exatamente igual ao valor do parcelamento.']);
    }

    public function test_complete_schedule_edit_replaces_the_rule_for_active_occurrences(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra editável',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'equal',
            'allocations' => [
                ['participant_id' => $self->id],
                ['participant_id' => $maria->id],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '70,00'],
                ['participant_id' => $maria->id, 'amount' => '30,00'],
            ],
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $this->assertSame('amount', $installment->fresh()->allocation_mode);
        $this->assertSame(7000, InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->where('participant_id', $self->id)->sum('amount_cents'));
        $this->assertSame(3000, InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->where('participant_id', $maria->id)->sum('amount_cents'));
    }

    public function test_receipt_reconciliation_applies_to_installment_allocations_in_date_order(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Compra para Maria',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '0,00'],
                ['participant_id' => $maria->id, 'amount' => '100,00'],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $this->post('/receipts', ['participant_id' => $maria->id, 'received_at' => '2026-10-03', 'amount' => '60,00'])->assertRedirect('/balances');

        $applications = ReceiptApplication::query()->where('source_type', 'installment_allocation')->orderBy('source_id')->get();
        $this->assertSame([5000, 1000], $applications->pluck('amount_cents')->all());
        $this->assertSame(2, $applications->count());
    }

    public function test_manual_receipt_application_rejects_installment_rateios(): void
    {
        [, $maria] = $this->catalogs();
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-20', 'amount_cents' => 1000]);

        $this->from("/receipts/{$receipt->id}/edit")
            ->put("/receipts/{$receipt->id}/applications", [
                'applications' => [[
                    'source_type' => 'installment_allocation',
                    'source_id' => 1,
                    'amount' => '10,00',
                ]],
            ])
            ->assertRedirect("/receipts/{$receipt->id}/edit")
            ->assertSessionHasErrors('applications.0.source_type');
        $this->assertSame(0, ReceiptApplication::query()->count());
    }

    public function test_monthly_analysis_uses_installment_allocations_without_duplicating_occurrence_totals(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Compra analisada',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '50,00'],
                ['participant_id' => $maria->id, 'amount' => '50,00'],
            ],
        ])->assertRedirect('/installments');

        $this->get('/analysis?month=2026-10&view=calendar')->assertInertia(fn (Assert $page) => $page
            ->where('summary.ownConsumptionCents', 2500)
            ->where('summary.paidForOthersCents', 2500)
            ->where('summary.totalDisbursedCents', 5000)
            ->where('participants.0.name', 'Maria')
            ->where('participants.0.grossCents', 2500)
            ->where('participants.0.items.0.amountCents', 2500));
    }

    public function test_inertia_create_screen_exposes_rateio_fields(): void
    {
        $this->get('/installments/create')->assertInertia(fn (Assert $page) => $page
            ->component('Installments/Create')
            ->has('participants')
            ->has('categories')
            ->has('paymentMethods'));
    }

    public function test_inertia_complete_edit_screen_exposes_rateio_fields(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra para edição',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'percentage',
            'allocations' => [
                ['participant_id' => $self->id, 'percentage' => '25,00'],
                ['participant_id' => $maria->id, 'percentage' => '75,00'],
            ],
        ])->assertRedirect('/installments');

        $occurrence = Installment::query()->latest('id')->firstOrFail()->occurrences()->where('installment_number', 1)->firstOrFail();
        $this->get("/installment-occurrences/{$occurrence->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->component('Installments/Edit')
            ->where('installment.allocationMode', 'percentage')
            ->has('installment.allocations', 2)
            ->where('installment.allocations.1.percentageBasisPoints', 7500));
    }

    public function test_migration_converts_legacy_occurrences_and_preserves_receipt_application_history(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();
        $category = Category::create(['name' => 'Legado', 'active' => true]);
        $installment = Installment::create([
            'start_date' => '2026-10-01',
            'description' => 'Parcelamento legado',
            'total_cents' => 10000,
            'installment_count' => 2,
            'payer_id' => $self->id,
            'participant_id' => null,
            'payment_method_id' => $paymentMethod->id,
            'category_id' => $category->id,
        ]);
        $occurrences = collect([1 => '2026-10-01', 2 => '2026-11-01'])->map(function (string $date, int $number) use ($installment, $self, $paymentMethod, $category): InstallmentOccurrence {
            return $installment->occurrences()->create([
                'installment_number' => $number,
                'purchased_at' => $date,
                'description' => $installment->description,
                'amount_cents' => 5000,
                'payer_id' => $self->id,
                'participant_id' => null,
                'payment_method_id' => $paymentMethod->id,
                'category_id' => $category->id,
            ]);
        });
        $occurrence = $occurrences->first();
        $secondOccurrence = $occurrences->last();
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-02', 'amount_cents' => 2000]);
        $application = ReceiptApplication::create(['receipt_id' => $receipt->id, 'source_type' => 'installment_occurrence', 'source_id' => $occurrence->id, 'amount_cents' => 2000, 'source' => 'manual']);
        $secondReceipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-11-02', 'amount_cents' => 3000]);
        $secondApplication = ReceiptApplication::create(['receipt_id' => $secondReceipt->id, 'source_type' => 'installment_occurrence', 'source_id' => $secondOccurrence->id, 'amount_cents' => 3000, 'source' => 'manual']);

        Schema::dropIfExists('installment_allocations');
        Installment::query()->whereKey($installment->id)->update(['allocation_mode' => null]);

        $migration = require base_path('database/migrations/2026_10_09_013953_create_installment_allocations_table.php');
        $migration->up();

        $allocations = InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->orderBy('installment_occurrence_id')->get();
        $this->assertSame([null, null], $allocations->pluck('participant_id')->all());
        $this->assertSame([$category->id, $category->id], $allocations->pluck('category_id')->all());
        $this->assertSame([5000, 5000], $allocations->pluck('amount_cents')->all());
        $this->assertSame('installment_allocation', $application->fresh()->source_type);
        $this->assertSame($allocations[0]->id, $application->fresh()->source_id);
        $this->assertSame(2000, $application->fresh()->amount_cents);
        $this->assertSame('installment_allocation', $secondApplication->fresh()->source_type);
        $this->assertSame($allocations[1]->id, $secondApplication->fresh()->source_id);
        $this->assertSame(3000, $secondApplication->fresh()->amount_cents);
    }

    /** @return array{0: Participant, 1: Participant, 2: PaymentMethod} */
    private function catalogs(): array
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);

        return [$self, $maria, $paymentMethod];
    }
}
