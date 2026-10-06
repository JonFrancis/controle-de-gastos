<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodInvoiceSetting;
use App\Services\PaymentMethodInvoiceSettingService;
use Carbon\CarbonImmutable;
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

    public function test_metadata_only_update_on_a_new_credit_payment_method_does_not_change_invoice_version(): void
    {
        $this->travelTo('2026-10-06');

        $this->post('/payment-methods', [
            'name' => 'Cartão novo',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'due_day' => 15,
        ])->assertRedirect('/settings/catalogs');

        $paymentMethod = PaymentMethod::query()->where('name', 'Cartão novo')->firstOrFail();
        $setting = $paymentMethod->invoiceSettings()->firstOrFail();

        $this->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Cartão novo renomeado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'active' => false,
        ])->assertRedirect('/settings/catalogs');

        $this->assertDatabaseCount('payment_method_invoice_settings', 1);
        $this->assertDatabaseHas('payment_method_invoice_settings', [
            'id' => $setting->id,
            'closing_day' => 10,
            'due_day' => 15,
            'effective_from' => '2026-10-06 00:00:00',
            'retired_at' => null,
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'id' => $paymentMethod->id,
            'name' => 'Cartão novo renomeado',
            'active' => false,
        ]);

        $this->travelBack();
    }

    public function test_metadata_only_update_on_a_legacy_credit_payment_method_allows_a_null_due_day(): void
    {
        $this->travelTo('2026-10-06');

        $paymentMethod = PaymentMethod::create([
            'name' => 'Cartão legado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
        ]);
        $setting = $paymentMethod->invoiceSettings()->firstOrFail();

        $this->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Cartão legado renomeado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'active' => false,
        ])->assertRedirect('/settings/catalogs');

        $this->assertDatabaseCount('payment_method_invoice_settings', 1);
        $this->assertDatabaseHas('payment_method_invoice_settings', [
            'id' => $setting->id,
            'closing_day' => 10,
            'due_day' => null,
            'retired_at' => null,
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'id' => $paymentMethod->id,
            'name' => 'Cartão legado renomeado',
            'active' => false,
        ]);

        $this->travelBack();
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

    public function test_changing_only_effective_date_creates_a_new_invoice_version_with_existing_configuration(): void
    {
        $this->travelTo('2026-10-06');

        $this->post('/payment-methods', [
            'name' => 'Cartão com nova vigência',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'due_day' => 15,
        ])->assertRedirect('/settings/catalogs');

        $paymentMethod = PaymentMethod::query()->where('name', 'Cartão com nova vigência')->firstOrFail();

        $this->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Cartão com nova vigência',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'due_day' => 15,
            'effective_from' => '2026-11-11',
            'active' => true,
        ])->assertRedirect('/settings/catalogs');

        $this->assertDatabaseCount('payment_method_invoice_settings', 2);
        $this->assertDatabaseHas('payment_method_invoice_settings', [
            'payment_method_id' => $paymentMethod->id,
            'closing_day' => 10,
            'due_day' => 15,
            'effective_from' => '2026-11-11 00:00:00',
            'retired_at' => null,
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'id' => $paymentMethod->id,
            'closing_day' => 10,
        ]);

        $this->travelBack();
    }

    public function test_invoice_setting_lifecycle_is_audited_with_its_version_fields(): void
    {
        $this->travelTo('2026-10-06 12:00:00');
        $this->post('/payment-methods', [
            'name' => 'Cartão auditado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'due_day' => 15,
        ])->assertRedirect('/settings/catalogs');
        $paymentMethod = PaymentMethod::query()->where('name', 'Cartão auditado')->firstOrFail();
        $initialSetting = $paymentMethod->invoiceSettings()->firstOrFail();
        $initialAudit = AuditLog::query()
            ->where('action', AuditLog::ACTION_CREATE)
            ->where('auditable_type', PaymentMethodInvoiceSetting::class)
            ->where('auditable_id', $initialSetting->id)
            ->firstOrFail();

        $this->assertSame(PaymentMethodInvoiceSetting::class, $initialAudit->auditable_type);
        $this->assertSame($initialSetting->id, $initialAudit->auditable_id);
        $this->assertSame(10, $initialAudit->metadata['closing_day']);
        $this->assertSame(15, $initialAudit->metadata['due_day']);
        $this->assertSame('2026-10-06', $initialAudit->metadata['effective_from']);
        $this->assertSame(null, $initialAudit->metadata['retired_at']);

        $this->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Cartão auditado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 20,
            'due_day' => 25,
            'effective_from' => '2026-10-15',
            'active' => true,
        ])->assertRedirect('/settings/catalogs');

        $setting = PaymentMethodInvoiceSetting::query()
            ->where('payment_method_id', $paymentMethod->id)
            ->where('closing_day', 20)
            ->firstOrFail();
        $createdAudit = AuditLog::query()
            ->where('action', AuditLog::ACTION_CREATE)
            ->where('auditable_type', PaymentMethodInvoiceSetting::class)
            ->where('auditable_id', $setting->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(20, $createdAudit->new_values['closing_day']);
        $this->assertSame(25, $createdAudit->new_values['due_day']);
        $this->assertSame('2026-11-11 00:00:00', $createdAudit->new_values['effective_from']);
        $this->assertSame(null, $createdAudit->new_values['retired_at']);
        $this->assertSame(20, $createdAudit->metadata['closing_day']);
        $this->assertSame(25, $createdAudit->metadata['due_day']);
        $this->assertSame('2026-11-11', $createdAudit->metadata['effective_from']);

        $this->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Cartão auditado',
            'type' => PaymentMethod::TYPE_PIX,
            'active' => true,
        ])->assertRedirect('/settings/catalogs');

        $retiredAudit = AuditLog::query()
            ->where('action', AuditLog::ACTION_ARCHIVE)
            ->where('auditable_type', PaymentMethodInvoiceSetting::class)
            ->where('auditable_id', $setting->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(20, $retiredAudit->old_values['closing_day']);
        $this->assertSame(25, $retiredAudit->old_values['due_day']);
        $this->assertSame('2026-11-11 00:00:00', $retiredAudit->old_values['effective_from']);
        $this->assertSame(null, $retiredAudit->old_values['retired_at']);
        $this->assertSame('2026-10-06 12:00:00', $retiredAudit->new_values['retired_at']);
        $this->assertSame('2026-10-06 12:00:00', $retiredAudit->metadata['retired_at']);

        $this->travelBack();
    }

    public function test_catalogs_use_the_current_version_and_suggest_after_a_future_version(): void
    {
        $this->travelTo('2026-10-06');
        $this->post('/payment-methods', [
            'name' => 'Cartão com versão futura',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'due_day' => 15,
        ]);
        $paymentMethod = PaymentMethod::query()->where('name', 'Cartão com versão futura')->firstOrFail();
        PaymentMethodInvoiceSetting::factory()->for($paymentMethod)->create([
            'closing_day' => 20,
            'due_day' => 25,
            'effective_from' => '2026-11-11',
        ]);

        $this->get('/settings/catalogs')
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentMethods.0.closing_day', 10)
                ->where('paymentMethods.0.due_day', 15)
                ->where('paymentMethods.0.effective_from', '2026-10-06')
                ->where('paymentMethods.0.suggested_effective_from', '2026-11-21')
            );

        $this->travelBack();
    }

    public function test_updating_payment_method_metadata_preserves_a_future_invoice_version(): void
    {
        $this->travelTo('2026-10-06');
        $this->post('/payment-methods', [
            'name' => 'Cartão com metadados',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'due_day' => 15,
        ]);
        $paymentMethod = PaymentMethod::query()->where('name', 'Cartão com metadados')->firstOrFail();
        $futureSetting = PaymentMethodInvoiceSetting::factory()->for($paymentMethod)->create([
            'closing_day' => 20,
            'due_day' => 25,
            'effective_from' => '2026-11-11',
        ]);

        $this->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Cartão renomeado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'active' => false,
        ])->assertRedirect('/settings/catalogs');

        $this->assertDatabaseCount('payment_method_invoice_settings', 2);
        $this->assertDatabaseHas('payment_method_invoice_settings', [
            'id' => $futureSetting->id,
            'closing_day' => 20,
            'due_day' => 25,
            'effective_from' => '2026-11-11 00:00:00',
            'retired_at' => null,
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'id' => $paymentMethod->id,
            'name' => 'Cartão renomeado',
            'active' => false,
        ]);

        $this->travelBack();
    }

    public function test_editing_a_legacy_credit_payment_method_name_and_status_does_not_require_invoice_configuration(): void
    {
        $paymentMethod = PaymentMethod::withoutEvents(fn (): PaymentMethod => PaymentMethod::create([
            'name' => 'Cartão legado sem versão',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'active' => true,
        ]));

        $this->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Cartão legado renomeado',
            'active' => false,
        ])->assertRedirect('/settings/catalogs')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payment_methods', [
            'id' => $paymentMethod->id,
            'name' => 'Cartão legado renomeado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'active' => false,
        ]);
        $this->assertDatabaseCount('payment_method_invoice_settings', 0);
    }

    public function test_historical_selection_falls_back_to_the_oldest_version_instead_of_a_future_version(): void
    {
        $this->travelTo('2026-10-06');
        $this->post('/payment-methods', [
            'name' => 'Cartão com histórico aproximado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'due_day' => 15,
        ]);
        $paymentMethod = PaymentMethod::query()->where('name', 'Cartão com histórico aproximado')->firstOrFail();
        PaymentMethodInvoiceSetting::factory()->for($paymentMethod)->create([
            'closing_day' => 20,
            'due_day' => 25,
            'effective_from' => '2026-11-11',
        ]);

        $setting = app(PaymentMethodInvoiceSettingService::class)->currentForDate($paymentMethod, CarbonImmutable::parse('2026-09-15'));

        $this->assertNotNull($setting);
        $this->assertSame(10, $setting->closing_day);
        $this->assertSame('2026-10-06', $setting->effective_from?->toDateString());

        $this->travelBack();
    }

    public function test_scheduling_a_change_after_a_future_version_compares_with_the_last_scheduled_version(): void
    {
        $this->travelTo('2026-10-06');
        $this->post('/payment-methods', [
            'name' => 'Cartão com agendamento encadeado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'due_day' => 15,
        ]);
        $paymentMethod = PaymentMethod::query()->where('name', 'Cartão com agendamento encadeado')->firstOrFail();
        PaymentMethodInvoiceSetting::factory()->for($paymentMethod)->create([
            'closing_day' => 20,
            'due_day' => 25,
            'effective_from' => '2026-11-11',
        ]);

        $this->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Cartão com agendamento encadeado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'due_day' => 15,
            'effective_from' => '2026-12-21',
            'active' => true,
        ])->assertRedirect('/settings/catalogs');

        $this->assertDatabaseCount('payment_method_invoice_settings', 3);
        $this->assertDatabaseHas('payment_method_invoice_settings', [
            'payment_method_id' => $paymentMethod->id,
            'closing_day' => 10,
            'due_day' => 15,
            'effective_from' => '2026-12-21 00:00:00',
            'retired_at' => null,
        ]);

        $this->travelBack();
    }

    public function test_reactivating_credit_creates_a_new_active_version_after_retirement(): void
    {
        $this->travelTo('2026-10-06');
        $this->post('/payment-methods', [
            'name' => 'Cartão reativado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'due_day' => 15,
        ]);
        $paymentMethod = PaymentMethod::query()->where('name', 'Cartão reativado')->firstOrFail();
        $initialSetting = $paymentMethod->invoiceSettings()->firstOrFail();

        $this->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Pix temporário',
            'type' => PaymentMethod::TYPE_PIX,
            'active' => true,
        ])->assertRedirect('/settings/catalogs');

        $this->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Cartão reativado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'due_day' => 15,
            'effective_from' => '2026-10-31',
            'active' => true,
        ])->assertRedirect('/settings/catalogs');

        $this->assertDatabaseHas('payment_method_invoice_settings', [
            'id' => $initialSetting->id,
            'retired_at' => '2026-10-06 00:00:00',
        ]);
        $this->assertDatabaseHas('payment_method_invoice_settings', [
            'payment_method_id' => $paymentMethod->id,
            'closing_day' => 10,
            'due_day' => 15,
            'effective_from' => '2026-11-11 00:00:00',
            'retired_at' => null,
        ]);

        $this->travelBack();
    }

    public function test_changing_credit_to_non_credit_retires_invoice_settings_without_deleting_history(): void
    {
        $this->travelTo('2026-10-06');
        $this->post('/payment-methods', [
            'name' => 'Cartão desativado',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 10,
            'due_day' => 15,
        ]);
        $paymentMethod = PaymentMethod::query()->where('name', 'Cartão desativado')->firstOrFail();
        $setting = $paymentMethod->invoiceSettings()->firstOrFail();

        $this->patch("/payment-methods/{$paymentMethod->id}", [
            'name' => 'Pix convertido',
            'type' => PaymentMethod::TYPE_PIX,
            'closing_day' => 10,
            'due_day' => 15,
            'active' => true,
        ])->assertRedirect('/settings/catalogs');

        $this->assertDatabaseHas('payment_methods', [
            'id' => $paymentMethod->id,
            'type' => PaymentMethod::TYPE_PIX,
            'closing_day' => null,
        ]);
        $this->assertDatabaseHas('payment_method_invoice_settings', [
            'id' => $setting->id,
            'retired_at' => '2026-10-06 00:00:00',
        ]);
        $this->get('/settings/catalogs')
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentMethods.0.type', PaymentMethod::TYPE_PIX)
                ->where('paymentMethods.0.invoice_settings', []));

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
