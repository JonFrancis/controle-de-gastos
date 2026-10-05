<?php

namespace Tests\Unit;

use App\Services\NativePackageManager;
use PHPUnit\Framework\TestCase;

class NativePackageManagerTest extends TestCase
{
    public function test_pnpm_fallback_is_selected_when_npm_is_missing(): void
    {
        $manager = new NativePackageManager;

        $this->assertTrue($manager->shouldUsePnpmFallback(null, 'C:\\tools\\pnpm.cmd'));
    }

    public function test_pnpm_fallback_is_not_selected_when_npm_is_available(): void
    {
        $manager = new NativePackageManager;

        $this->assertFalse($manager->shouldUsePnpmFallback('C:\\tools\\npm.cmd', 'C:\\tools\\pnpm.cmd'));
    }

    public function test_fallback_directory_is_prepended_only_once(): void
    {
        $manager = new NativePackageManager;
        $path = implode(PATH_SEPARATOR, ['C:\\tools\\pnpm', 'C:\\Windows\\System32']);

        $this->assertSame(
            implode(PATH_SEPARATOR, ['C:\\project\\bin', 'C:\\tools\\pnpm', 'C:\\Windows\\System32']),
            $manager->prependPath('C:\\project\\bin', $path),
        );

        $this->assertSame(
            $path,
            $manager->prependPath('C:\\tools\\pnpm', $path),
        );
    }
}
