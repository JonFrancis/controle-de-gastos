<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenAiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_action_sends_filled_content_to_configured_responses_api(): void
    {
        Config::set('services.openai.api_key', 'sk-test-secret');
        Config::set('services.openai.model', 'test-model');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_test',
                'output' => [[
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Análise gerada.',
                    ]],
                ]],
            ]),
        ]);

        $this->from('/exports')->post('/openai/responses', ['content' => 'Prompt preenchido pelo usuário.'])
            ->assertRedirect('/exports')
            ->assertSessionHas('openai.response', 'Análise gerada.');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer sk-test-secret')
            && $request->data() === [
                'model' => 'test-model',
                'input' => 'Prompt preenchido pelo usuário.',
                'store' => false,
            ]);

        $this->get('/exports')->assertInertia(fn (Assert $page) => $page
            ->missing('flash.openai.api_key')
            ->where('flash.openai.response', 'Análise gerada.'))
            ->assertDontSee('sk-test-secret');
    }

    public function test_missing_api_key_returns_a_recoverable_message_without_calling_openai(): void
    {
        Config::set('services.openai.api_key', null);
        Http::preventStrayRequests();

        $this->from('/exports')->post('/openai/responses', ['content' => 'Prompt preenchido pelo usuário.'])
            ->assertRedirect('/exports')
            ->assertSessionHasErrors('openai');

        Http::assertNothingSent();
    }

    #[DataProvider('upstreamErrorProvider')]
    public function test_upstream_http_errors_return_recoverable_messages(int $status, string $message): void
    {
        Config::set('services.openai.api_key', 'sk-test-secret');
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([], $status)]);

        $this->from('/exports')->post('/openai/responses', ['content' => 'Prompt preenchido pelo usuário.'])
            ->assertRedirect('/exports')
            ->assertSessionHasErrors(['openai' => $message]);
    }

    public static function upstreamErrorProvider(): array
    {
        return [
            'authentication failure' => [401, 'A chave da OpenAI foi recusada. Confira a configuração do servidor.'],
            'permission failure' => [403, 'A chave da OpenAI não tem permissão para esta operação.'],
            'rate limit or quota' => [429, 'A OpenAI informou limite de uso ou cota excedida. Tente novamente mais tarde ou revise a conta.'],
            'server failure' => [503, 'A OpenAI está temporariamente indisponível. Tente novamente mais tarde.'],
        ];
    }

    public function test_network_failure_returns_a_recoverable_message(): void
    {
        Config::set('services.openai.api_key', 'sk-test-secret');
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::failedConnection()]);

        $this->from('/exports')->post('/openai/responses', ['content' => 'Prompt preenchido pelo usuário.'])
            ->assertRedirect('/exports')
            ->assertSessionHasErrors(['openai' => 'Não foi possível conectar à OpenAI. Verifique a rede e tente novamente.']);
    }

    public function test_export_page_remains_available_without_api_configuration(): void
    {
        Config::set('services.openai.api_key', null);
        Http::preventStrayRequests();

        $this->get('/exports')->assertSuccessful();

        Http::assertNothingSent();
    }
}
