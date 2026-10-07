<?php

namespace App\Http\Controllers;

use App\Exceptions\ApplicationLifecycleException;
use App\Exceptions\OperationInProgressException;
use App\Services\ApplicationLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApplicationLifecycleController extends Controller
{
    public function status(Request $request, ApplicationLifecycleService $lifecycle): JsonResponse
    {
        $this->ensureLocalRequest($request);

        return response()->json($lifecycle->status());
    }

    public function shutdown(Request $request, ApplicationLifecycleService $lifecycle): JsonResponse
    {
        $this->ensureLocalRequest($request);

        try {
            $result = $lifecycle->prepareShutdown(
                hasPendingOperation: $request->boolean('has_pending_operation'),
                confirmed: $request->boolean('confirmed'),
            );
        } catch (OperationInProgressException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'requires_confirmation' => true], 409);
        } catch (ApplicationLifecycleException $exception) {
            Log::error('Falha ao encerrar o Controle de Gastos.', ['message' => $exception->getMessage()]);

            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            Log::error('Falha inesperada ao encerrar o Controle de Gastos.', ['message' => $exception->getMessage()]);

            return response()->json(['message' => 'Não foi possível encerrar a aplicação com segurança.'], 500);
        }

        return response()->json([
            'status' => 'shutdown_requested',
            'message' => 'A aplicação foi encerrada com segurança. Você pode fechar esta aba.',
            'backup' => $result['backup']['filename'] ?? null,
        ]);
    }

    private function ensureLocalRequest(Request $request): void
    {
        abort_unless(
            in_array($request->ip(), ['127.0.0.1', '::1'], true)
                && in_array($request->getHost(), ['127.0.0.1', 'localhost', '[::1]'], true),
            403,
        );
    }
}
