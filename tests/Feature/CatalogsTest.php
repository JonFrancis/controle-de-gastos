<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogs_page_loads_available_catalogs(): void
    {
        Participant::create(['name' => 'Maria']);
        Category::create(['name' => 'Casa']);
        PaymentMethod::create(['name' => 'Cartão principal', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 8]);

        $this->get('/settings/catalogs')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Catalogs')
                ->has('participants', 2)
                ->has('categories', 1)
                ->has('paymentMethods', 1)
            );
    }

    public function test_participant_category_and_payment_method_can_be_created(): void
    {
        $this->post('/participants', ['name' => 'Maria'])->assertRedirect('/settings/catalogs');
        $this->post('/categories', ['name' => 'Casa'])->assertRedirect('/settings/catalogs');
        $this->post('/payment-methods', [
            'name' => 'Cartão principal', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 8, 'due_day' => 15,
        ])->assertRedirect('/settings/catalogs');

        $this->assertDatabaseHas('participants', ['name' => 'Maria', 'active' => true]);
        $this->assertDatabaseHas('categories', ['name' => 'Casa', 'active' => true]);
        $this->assertDatabaseHas('payment_methods', ['name' => 'Cartão principal', 'type' => 'credit', 'closing_day' => 8, 'active' => true]);
    }

    public function test_catalogs_can_be_updated_without_deleting_history(): void
    {
        $participant = Participant::create(['name' => 'Maria']);
        $category = Category::create(['name' => 'Casa']);
        $method = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->patch("/participants/{$participant->id}", ['name' => 'Maria Silva', 'active' => false])->assertRedirect('/settings/catalogs');
        $this->patch("/categories/{$category->id}", ['name' => 'Moradia', 'active' => false])->assertRedirect('/settings/catalogs');
        $this->patch("/payment-methods/{$method->id}", ['name' => 'Pix pessoal', 'type' => PaymentMethod::TYPE_PIX, 'closing_day' => 12, 'active' => false])->assertRedirect('/settings/catalogs');

        $this->assertDatabaseHas('participants', ['id' => $participant->id, 'name' => 'Maria Silva', 'active' => false]);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Moradia', 'active' => false]);
        $this->assertDatabaseHas('payment_methods', ['id' => $method->id, 'name' => 'Pix pessoal', 'closing_day' => null, 'active' => false]);
    }

    public function test_credit_payment_method_creates_an_initial_version_with_due_day(): void
    {
        $this->travelTo('2026-10-06');

        $this->post('/payment-methods', [
            'name' => 'Nubank',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 5,
            'due_day' => 10,
        ])->assertRedirect('/settings/catalogs');

        $paymentMethod = PaymentMethod::query()->where('name', 'Nubank')->firstOrFail();

        $this->assertDatabaseHas('payment_method_invoice_settings', [
            'payment_method_id' => $paymentMethod->id,
            'closing_day' => 5,
            'due_day' => 10,
            'effective_from' => '2026-10-06 00:00:00',
        ]);

        $this->get('/settings/catalogs')
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentMethods.0.due_day', 10)
                ->where('paymentMethods.0.effective_from', '2026-10-06'));

        $this->travelBack();
    }

    public function test_credit_configuration_requires_due_day_and_ignores_invoice_fields_for_non_credit_methods(): void
    {
        $this->from('/settings/catalogs')->post('/payment-methods', [
            'name' => 'Cartão sem vencimento',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 5,
        ])->assertRedirect('/settings/catalogs')->assertSessionHasErrors('due_day');

        $this->from('/settings/catalogs')->post('/payment-methods', [
            'name' => 'Cartão com vencimento inválido',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 5,
            'due_day' => 32,
        ])->assertRedirect('/settings/catalogs')->assertSessionHasErrors('due_day');

        $this->post('/payment-methods', [
            'name' => 'Pix pessoal',
            'type' => PaymentMethod::TYPE_PIX,
            'closing_day' => 12,
            'due_day' => 10,
        ])->assertRedirect('/settings/catalogs');

        $pix = PaymentMethod::query()->where('name', 'Pix pessoal')->firstOrFail();

        $this->assertDatabaseHas('payment_methods', ['id' => $pix->id, 'closing_day' => null]);
        $this->assertDatabaseMissing('payment_method_invoice_settings', ['payment_method_id' => $pix->id]);
    }

    public function test_editing_credit_configuration_requires_a_safe_effective_date_and_preserves_versions(): void
    {
        $this->travelTo('2026-10-06');

        $this->post('/payment-methods', [
            'name' => 'Sofisa',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 30,
            'due_day' => 10,
        ]);

        $paymentMethod = PaymentMethod::query()->where('name', 'Sofisa')->firstOrFail();

        $this->from('/settings/catalogs')->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Sofisa',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 28,
            'due_day' => 12,
            'active' => true,
        ])->assertRedirect('/settings/catalogs')->assertSessionHasErrors('effective_from');

        $this->get('/settings/catalogs')
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentMethods.0.suggested_effective_from', '2026-10-31'));

        $this->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Sofisa',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 28,
            'due_day' => 12,
            'effective_from' => '2026-10-31',
            'active' => true,
        ])->assertRedirect('/settings/catalogs');

        $this->assertDatabaseHas('payment_method_invoice_settings', [
            'payment_method_id' => $paymentMethod->id,
            'closing_day' => 30,
            'due_day' => 10,
            'effective_from' => '2026-10-06 00:00:00',
        ]);
        $this->assertDatabaseHas('payment_method_invoice_settings', [
            'payment_method_id' => $paymentMethod->id,
            'closing_day' => 28,
            'due_day' => 12,
            'effective_from' => '2026-10-31 00:00:00',
        ]);

        $this->get('/settings/catalogs')
            ->assertInertia(fn (Assert $page) => $page
                ->has('paymentMethods.0.invoice_settings', 2)
                ->where('paymentMethods.0.invoice_settings.0.closing_day', 28)
                ->where('paymentMethods.0.invoice_settings.1.closing_day', 30));

        $this->travelBack();
    }

    public function test_credit_payment_method_requires_a_valid_closing_day(): void
    {
        $this->from('/settings/catalogs')->post('/payment-methods', [
            'name' => 'Cartão sem fechamento', 'type' => PaymentMethod::TYPE_CREDIT,
        ])->assertRedirect('/settings/catalogs')->assertSessionHasErrors('closing_day');

        $this->from('/settings/catalogs')->post('/payment-methods', [
            'name' => 'Cartão inválido', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 32,
        ])->assertRedirect('/settings/catalogs')->assertSessionHasErrors('closing_day');
    }

    public function test_duplicate_catalog_names_return_user_facing_errors(): void
    {
        Participant::create(['name' => 'Maria']);
        Category::create(['name' => 'Casa']);
        PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->from('/settings/catalogs')->post('/participants', ['name' => 'Maria'])
            ->assertSessionHasErrors(['name' => 'Já existe uma pessoa com este nome.']);
        $this->from('/settings/catalogs')->post('/categories', ['name' => 'Casa'])
            ->assertSessionHasErrors(['name' => 'Já existe uma categoria com este nome.']);
        $this->from('/settings/catalogs')->post('/payment-methods', ['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX])
            ->assertSessionHasErrors(['name' => 'Já existe uma forma de pagamento com este nome.']);
    }

    public function test_catalogs_can_be_permanently_deleted(): void
    {
        $participant = Participant::create(['name' => 'Maria']);
        $category = Category::create(['name' => 'Casa']);
        $method = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->delete("/participants/{$participant->id}")->assertRedirect('/settings/catalogs');
        $this->delete("/categories/{$category->id}")->assertRedirect('/settings/catalogs');
        $this->delete("/payment-methods/{$method->id}")->assertRedirect('/settings/catalogs');

        $this->assertDatabaseMissing('participants', ['id' => $participant->id]);
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('payment_methods', ['id' => $method->id]);
    }

    public function test_default_eu_participant_cannot_be_changed_or_deleted(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();

        $this->patch("/participants/{$self->id}", ['name' => 'Outro nome', 'active' => false])
            ->assertRedirect('/settings/catalogs');
        $this->delete("/participants/{$self->id}")->assertRedirect('/settings/catalogs');

        $this->assertDatabaseHas('participants', ['id' => $self->id, 'name' => 'Eu', 'active' => true, 'is_default' => true]);
    }
}
