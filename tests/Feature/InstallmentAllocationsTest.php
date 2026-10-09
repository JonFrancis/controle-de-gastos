<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Installment;
use App\Models\InstallmentAllocation;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use Illuminate\Database\Eloquent\Builder;
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

        $response->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $this->assertSame('equal', $installment->allocation_mode);
        $this->assertSame([3333, 3333, 3334], $installment->occurrences()->orderBy('installment_number')->pluck('amount_cents')->all());
        $this->assertSame([
            [1667, 1666],
            [1666, 1667],
            [1667, 1667],
        ], $installment->occurrences()->with('allocations')->orderBy('installment_number')->get()->map(fn (InstallmentOccurrence $occurrence): array => $occurrence->allocations->sortBy('participant_id')->pluck('amount_cents')->all())->all());
        $this->assertSame(6, InstallmentAllocation::query()->whereHas('occurrence', fn (Builder $query): Builder => $query->where('installment_id', $installment->id))->count());
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
        $this->assertSame(6000, InstallmentAllocation::query()->whereHas('occurrence', fn (Builder $query): Builder => $query->where('installment_id', $installment->id))->where('participant_id', $self->id)->sum('amount_cents'));
        $this->assertSame(4000, InstallmentAllocation::query()->whereHas('occurrence', fn (Builder $query): Builder => $query->where('installment_id', $installment->id))->where('participant_id', $maria->id)->sum('amount_cents'));
        $this->assertSame([3333, 3333, 3334], $installment->occurrences()->with('allocations')->orderBy('installment_number')->get()->map(fn (InstallmentOccurrence $occurrence): int => (int) $occurrence->allocations->sum('amount_cents'))->all());
    }

    public function test_amount_mode_parses_decimal_strings_without_float_rounding_loss(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Valores com precisão decimal',
            'total' => '2,02',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '1,005'],
                ['participant_id' => $maria->id, 'amount' => '1,005'],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $this->assertSame(101, (int) InstallmentAllocation::query()->whereHas('occurrence', fn (Builder $query): Builder => $query->where('installment_id', $installment->id))->where('participant_id', $self->id)->sum('amount_cents'));
        $this->assertSame(101, (int) InstallmentAllocation::query()->whereHas('occurrence', fn (Builder $query): Builder => $query->where('installment_id', $installment->id))->where('participant_id', $maria->id)->sum('amount_cents'));
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
        $this->assertSame(2500, InstallmentAllocation::query()->whereHas('occurrence', fn (Builder $query): Builder => $query->where('installment_id', $installment->id))->where('participant_id', $self->id)->sum('amount_cents'));
        $this->assertSame(7500, InstallmentAllocation::query()->whereHas('occurrence', fn (Builder $query): Builder => $query->where('installment_id', $installment->id))->where('participant_id', $maria->id)->sum('amount_cents'));
        $this->assertSame([3333, 3333, 3334], $installment->occurrences()->with('allocations')->orderBy('installment_number')->get()->map(fn (InstallmentOccurrence $occurrence): int => (int) $occurrence->allocations->sum('amount_cents'))->all());
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

    public function test_inertia_create_screen_exposes_rateio_fields(): void
    {
        $this->get('/installments/create')->assertInertia(fn (Assert $page) => $page
            ->component('Installments/Create')
            ->has('participants')
            ->has('categories')
            ->has('paymentMethods'));
    }

    public function test_migration_converts_multi_occurrence_legacy_rateios_and_preserves_receipt_application_history(): void
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
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-02', 'amount_cents' => 2000]);
        $application = ReceiptApplication::create(['receipt_id' => $receipt->id, 'source_type' => 'installment_occurrence', 'source_id' => $occurrences->first()->id, 'amount_cents' => 2000, 'source' => 'manual']);
        $secondReceipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-11-02', 'amount_cents' => 3000]);
        $secondApplication = ReceiptApplication::create(['receipt_id' => $secondReceipt->id, 'source_type' => 'installment_occurrence', 'source_id' => $occurrences->last()->id, 'amount_cents' => 3000, 'source' => 'manual']);

        Schema::dropIfExists('installment_allocations');
        Installment::query()->whereKey($installment->id)->update(['allocation_mode' => null]);

        $migration = require base_path('database/migrations/2026_10_09_013953_create_installment_allocations_table.php');
        $migration->up();

        $allocations = InstallmentAllocation::query()->whereHas('occurrence', fn (Builder $query): Builder => $query->where('installment_id', $installment->id))->orderBy('installment_occurrence_id')->get();
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
