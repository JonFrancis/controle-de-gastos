<?php

namespace Tests\Unit;

use App\Services\DesktopMigrationNoticeService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DesktopMigrationNoticeServiceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'\\controle-de-gastos-notice-'.uniqid('', true).'.json';
        config(['desktop.migration_notice_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        File::delete($this->path);
        parent::tearDown();
    }

    public function test_notice_is_persisted_and_can_be_cleared(): void
    {
        $service = app(DesktopMigrationNoticeService::class);

        $service->record('Banco incompatível.');

        $this->assertSame('Banco incompatível.', $service->current()['message']);

        $service->clear();

        $this->assertNull($service->current());
    }
}
