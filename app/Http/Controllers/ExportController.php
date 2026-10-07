<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportSelectionRequest;
use App\Http\Requests\SavePromptVersionRequest;
use App\Models\AuditLog;
use App\Services\AuditService;
use App\Services\ExportService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function index(ExportSelectionRequest $request, ExportService $service): Response
    {
        $selection = $service->selection($request->validated());
        $data = $service->data($selection);

        return Inertia::render('Exports/Index', [
            'mode' => $selection['mode'],
            'startDate' => $selection['start']?->toDateString(),
            'endDate' => $selection['end']?->toDateString(),
            'summary' => $data['summary'],
            'counts' => $data['counts'],
            'prompt' => $service->prompt($selection, $data),
            'flash' => ['success' => session('success'), 'openai' => session('openai')],
        ]);
    }

    public function csv(ExportSelectionRequest $request, string $type, ExportService $service): StreamedResponse
    {
        return $service->csv($type, $service->selection($request->validated()));
    }

    public function excel(ExportSelectionRequest $request, ExportService $service): StreamedResponse
    {
        $export = $service->excel($service->selection($request->validated()));

        return response()->streamDownload(function () use ($export): void {
            try {
                readfile($export['path']);
            } finally {
                if (file_exists($export['path'])) {
                    unlink($export['path']);
                }
            }
        }, $export['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function markdown(ExportSelectionRequest $request, ExportService $service): StreamedResponse
    {
        return $service->markdown($service->selection($request->validated()));
    }

    public function prompt(ExportSelectionRequest $request, ExportService $service): StreamedResponse
    {
        $selection = $service->selection($request->validated());
        $content = $service->prompt($selection);

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, 'prompt-'.$selection['label'].'.md', ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    public function savePromptVersion(SavePromptVersionRequest $request, ExportService $service, AuditService $audit): RedirectResponse
    {
        $validated = $request->validated();
        $selection = $service->selection($validated);
        $version = $service->savePromptVersion($selection, $validated['content']);
        $audit->record(AuditLog::ACTION_CREATE, $version, newValues: $version->getAttributes(), metadata: ['type' => 'prompt_version', 'scope' => $selection['label']]);

        return back()->with('success', 'Versão do prompt salva.');
    }
}
