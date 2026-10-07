<?php

namespace App\Services;

use App\Exceptions\OpenAiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenAiResponseService
{
    public function send(string $content): string
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            throw new OpenAiException('A integração com a OpenAI não está configurada. Defina OPENAI_API_KEY no ambiente e tente novamente.');
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout((int) config('services.openai.connect_timeout', 5))
                ->timeout((int) config('services.openai.timeout', 60))
                ->post(rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/').'/responses', [
                    'model' => (string) config('services.openai.model', 'gpt-5'),
                    'input' => $content,
                    'store' => false,
                ]);
        } catch (ConnectionException $exception) {
            throw new OpenAiException('Não foi possível conectar à OpenAI. Verifique a rede e tente novamente.', $exception);
        } catch (Throwable $exception) {
            throw new OpenAiException('Não foi possível concluir a solicitação à OpenAI. Tente novamente.', $exception);
        }

        if ($response->unauthorized()) {
            throw new OpenAiException('A chave da OpenAI foi recusada. Confira a configuração do servidor.');
        }

        if ($response->forbidden()) {
            throw new OpenAiException('A chave da OpenAI não tem permissão para esta operação.');
        }

        if ($response->status() === 429) {
            throw new OpenAiException('A OpenAI informou limite de uso ou cota excedida. Tente novamente mais tarde ou revise a conta.');
        }

        if ($response->serverError()) {
            throw new OpenAiException('A OpenAI está temporariamente indisponível. Tente novamente mais tarde.');
        }

        if (! $response->successful()) {
            throw new OpenAiException('A OpenAI rejeitou o conteúdo enviado. Revise o texto e tente novamente.');
        }

        $output = collect($response->json('output', []))
            ->flatMap(fn (mixed $item): array => is_array($item) ? ($item['content'] ?? []) : [])
            ->filter(fn (mixed $part): bool => is_array($part) && ($part['type'] ?? null) === 'output_text')
            ->map(fn (array $part): string => (string) ($part['text'] ?? ''))
            ->filter()
            ->implode("\n");

        if (! is_string($output) || trim($output) === '') {
            throw new OpenAiException('A OpenAI retornou uma resposta vazia. Tente novamente.');
        }

        return trim($output);
    }
}
