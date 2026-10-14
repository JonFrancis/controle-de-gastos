<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Services\AuditService;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BackupAndAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_backups_page_exposes_inertia_props_and_write_endpoints(): void
    {
        $backupDirectory = $this->backupDirectory();

        $this->get('/settings/backups')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Backups')
                ->has('settings.automaticBackupEnabled')
                ->has('backups'));

        $this->patch('/settings/backups', [
            'automatic_backup_enabled' => true,
            'backup_path' => $backupDirectory,
        ])->assertRedirect('/settings/backups');

        $this->post('/settings/backups')->assertRedirect('/settings/backups');
        $this->assertCount(1, File::glob($backupDirectory.'/*.json'));
    }

    public function test_manual_backup_is_identifiable_and_contains_the_database_state(): void
    {
        $backupDirectory = $this->backupDirectory();
        AppSetting::query()->update(['backup_path' => $backupDirectory]);
        $this->createPurchase('Mercado antes do backup');

        $this->post('/settings/backups')->assertRedirect('/settings/backups');

        $backupFiles = File::files($backupDirectory);
        $this->assertCount(1, $backupFiles);
        $this->assertStringContainsString('controle-de-gastos-backup-', $backupFiles[0]->getFilename());

        $backup = json_decode(File::get($backupFiles[0]->getPathname()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('controle-de-gastos-backup', $backup['format']);
        $this->assertSame('Mercado antes do backup', $backup['tables']['purchases'][0]['description']);
    }

    public function test_invalid_restore_is_rejected_without_changing_the_database(): void
    {
        $purchase = $this->createPurchase('Compra preservada');

        $this->from('/settings/backups')->post('/settings/backups/restore', [
            'backup' => UploadedFile::fake()->createWithContent('invalid.json', '{"format":"outro-formato"}'),
            'confirmation' => '1',
        ])->assertRedirect('/settings/backups')->assertSessionHasErrors('backup');

        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'description' => 'Compra preservada']);
        $this->assertCount(0, File::glob($this->backupDirectory().'/*.json'));
    }

    public function test_json_backup_with_missing_expected_tables_is_rejected_before_restore(): void
    {
        $purchase = $this->createPurchase('Compra preservada');
        $payload = json_encode([
            'format' => 'controle-de-gastos-backup',
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'source' => 'manual',
            'tables' => [],
        ], JSON_THROW_ON_ERROR);

        $this->from('/settings/backups')->post('/settings/backups/restore', [
            'backup' => UploadedFile::fake()->createWithContent('malformed.json', $payload, 'application/json'),
            'confirmation' => '1',
        ])->assertRedirect('/settings/backups')->assertSessionHasErrors('backup');

        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'description' => 'Compra preservada']);
    }

    public function test_restore_preserves_a_copy_of_the_previous_database(): void
    {
        $backupDirectory = $this->backupDirectory();
        AppSetting::query()->update(['backup_path' => $backupDirectory]);
        $purchase = $this->createPurchase('Estado original');

        $this->post('/settings/backups')->assertRedirect('/settings/backups');
        $sourceBackup = File::files($backupDirectory)[0]->getPathname();
        $purchase->update(['description' => 'Estado atual']);

        $this->post('/settings/backups/restore', [
            'backup' => UploadedFile::fake()->createWithContent('backup.json', File::get($sourceBackup)),
            'confirmation' => '1',
        ])->assertRedirect('/settings/backups');

        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'description' => 'Estado original']);
        $this->assertCount(2, File::glob($backupDirectory.'/*.json'));
    }

    public function test_automatic_backup_can_be_configured_and_runs_after_audited_changes(): void
    {
        $backupDirectory = $this->backupDirectory();

        $this->from('/settings/backups')->patch('/settings/backups', [
            'automatic_backup_enabled' => '1',
            'backup_path' => $backupDirectory,
        ])->assertRedirect('/settings/backups');

        $this->createPurchase('Compra automática');

        $this->assertDatabaseHas('app_settings', [
            'automatic_backup_enabled' => 1,
            'backup_path' => $backupDirectory,
        ]);
        $this->assertCount(1, File::glob($backupDirectory.'/*.json'));
    }

    public function test_automatic_backup_is_not_repeated_when_the_database_has_not_changed(): void
    {
        $backupDirectory = $this->backupDirectory();
        AppSetting::query()->update(['automatic_backup_enabled' => true, 'backup_path' => $backupDirectory]);
        $this->createPurchase('Compra única');

        app(BackupService::class)->createAutomaticIfNeeded();

        $this->assertCount(1, File::glob($backupDirectory.'/*.json'));
    }

    public function test_automatic_backup_retains_only_the_thirty_most_recent_files(): void
    {
        $backupDirectory = $this->backupDirectory();
        AppSetting::query()->update(['automatic_backup_enabled' => true, 'backup_path' => $backupDirectory]);

        for ($index = 0; $index < 31; $index++) {
            AuditLog::create(['action' => 'backup-test-'.$index]);
            app(BackupService::class)->create(source: 'automatic');
        }

        $this->assertCount(30, File::glob($backupDirectory.'/*.json'));
        $this->assertStringContainsString('automatic', File::files($backupDirectory)[0]->getFilename());
    }

    public function test_history_can_be_filtered_for_purchase_lifecycle_and_import_events(): void
    {
        $purchase = $this->createPurchase('Compra auditada');
        $this->patch("/purchases/{$purchase->id}", $this->purchaseData('Compra editada'))->assertRedirect('/');
        $this->patch("/purchases/{$purchase->id}/archive")->assertRedirect('/');
        $this->patch("/purchases/{$purchase->id}/restore")->assertRedirect('/');
        app(AuditService::class)->recordImport('historico.xlsx', 4);

        $this->get('/history?action=import')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('History/Index')
                ->where('selectedAction', 'import')
                ->has('logs', 1)
                ->where('logs.0.action', AuditLog::ACTION_IMPORT)
                ->where('logs.0.metadata.source', 'historico.xlsx')
                ->where('logs.0.metadata.records', 4));

        $this->assertSame(
            [AuditLog::ACTION_CREATE, AuditLog::ACTION_UPDATE, AuditLog::ACTION_ARCHIVE, AuditLog::ACTION_RESTORE, AuditLog::ACTION_IMPORT],
            AuditLog::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    private function createPurchase(string $description): Purchase
    {
        [$payer, $participant, $method, $category] = $this->catalogs();

        $response = $this->post('/purchases', $this->purchaseData($description, $payer, $method));
        $purchase = Purchase::query()->where('description', $description)->firstOrFail();
        $response->assertRedirect("/purchases/{$purchase->id}/allocations/edit");

        return $purchase;
    }

    /** @return array<string, mixed> */
    private function purchaseData(string $description, ?Participant $payer = null, ?PaymentMethod $method = null): array
    {
        $payer ??= Participant::query()->where('is_default', true)->firstOrFail();
        $method ??= PaymentMethod::query()->firstOrFail();

        return [
            'purchased_at' => '2026-10-12',
            'description' => $description,
            'card_name' => 'MERCADO',
            'amount' => '100,00',
            'payer_id' => $payer->id,
            'payment_method_id' => $method->id,
        ];
    }

    /** @return array{0: Participant, 1: Participant, 2: PaymentMethod, 3: Category} */
    private function catalogs(): array
    {
        $payer = Participant::query()->where('is_default', true)->firstOrFail();
        $participant = Participant::create(['name' => 'Maria '.fake()->unique()->numerify('####')]);
        $method = PaymentMethod::create(['name' => 'Pix '.fake()->unique()->numerify('####'), 'type' => PaymentMethod::TYPE_PIX]);
        $category = Category::create(['name' => 'Casa '.fake()->unique()->numerify('####')]);

        return [$payer, $participant, $method, $category];
    }

    private function backupDirectory(): string
    {
        $directory = sys_get_temp_dir().'\\controle-de-gastos-backups-'.uniqid('', true);
        File::makeDirectory($directory, 0755, true);

        return $directory;
    }
}
