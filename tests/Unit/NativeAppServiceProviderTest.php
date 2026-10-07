<?php

namespace Tests\Unit;

use App\Providers\NativeAppServiceProvider;
use ReflectionMethod;
use Tests\TestCase;

class NativeAppServiceProviderTest extends TestCase
{
    public function test_nativephp_boot_callback_has_no_required_arguments(): void
    {
        $method = new ReflectionMethod(NativeAppServiceProvider::class, 'boot');

        $this->assertSame(0, $method->getNumberOfRequiredParameters());
    }
}
