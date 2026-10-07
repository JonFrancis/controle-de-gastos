<?php

namespace Tests\Feature;

use App\Exceptions\OperationInProgressException;
use App\Services\ApplicationLifecycleService;
use Mockery;
use Tests\TestCase;

class ApplicationLifecycleTest extends TestCase
{
    public function test_local_clients_can_read_lifecycle_status(): void
    {
        $lifecycle = Mockery::mock(ApplicationLifecycleService::class);
        $lifecycle->expects('status')->andReturn([
            'running' => true,
            'pid' => 1234,
            'url' => 'http://127.0.0.1:8000',
            'owned' => true,
            'shutdownRequested' => false,
        ]);
        $this->app->instance(ApplicationLifecycleService::class, $lifecycle);

        $this->getJson(route('application.status'))
            ->assertOk()
            ->assertJsonPath('owned', true)
            ->assertJsonPath('pid', 1234);
    }

    public function test_shutdown_requires_confirmation_when_an_operation_is_in_progress(): void
    {
        $lifecycle = Mockery::mock(ApplicationLifecycleService::class);
        $lifecycle->expects('prepareShutdown')
            ->with(true, false)
            ->andThrow(new OperationInProgressException('Confirme o encerramento.'));
        $this->app->instance(ApplicationLifecycleService::class, $lifecycle);

        $this->postJson(route('application.shutdown'), ['has_pending_operation' => true])
            ->assertStatus(409)
            ->assertJson(['requires_confirmation' => true]);
    }

    public function test_shutdown_returns_a_confirmation_after_the_response_is_prepared(): void
    {
        $lifecycle = Mockery::mock(ApplicationLifecycleService::class);
        $lifecycle->expects('prepareShutdown')
            ->with(false, false)
            ->andReturn(['pid' => 1234, 'backup' => null]);
        $this->app->instance(ApplicationLifecycleService::class, $lifecycle);

        $this->postJson(route('application.shutdown'))
            ->assertOk()
            ->assertJsonPath('status', 'shutdown_requested')
            ->assertJsonPath('message', 'A aplicação foi encerrada com segurança. Você pode fechar esta aba.');
    }

    public function test_external_clients_cannot_request_shutdown(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.168.1.20',
            'HTTP_HOST' => '192.168.1.20',
        ])->postJson(route('application.shutdown'))
            ->assertForbidden();
    }
}
