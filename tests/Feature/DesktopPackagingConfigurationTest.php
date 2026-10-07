<?php

namespace Tests\Feature;

use Tests\TestCase;

class DesktopPackagingConfigurationTest extends TestCase
{
    public function test_desktop_build_uses_the_application_name_and_includes_the_source_database(): void
    {
        $this->assertSame('Controle de Gastos', config('app.name'));
        $this->assertContains('database/database.sqlite', config('nativephp.cleanup_include_files'));
    }
}
