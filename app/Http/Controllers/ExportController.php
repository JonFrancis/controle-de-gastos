<?php

namespace App\Http\Controllers;

use App\Services\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function index(Request $request, ExportService $service): Response
    {
        $selection = $this->selection($request, $service);
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

    public function csv(Request $request, string $type, ExportService $service): StreamedResponse
    {
        return $service->csv($type, $this->selection($request, $service));
    }

    public function excel(Request $request, ExportService $service): StreamedResponse
    {
        $export = $service->excel($this->selection($request, $service));

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

    public function markdown(Request $request, ExportService $service): StreamedResponse
    {
        return $service->markdown($this->selection($request, $service));
    }

    public function prompt(Request $request, ExportService $service): StreamedResponse
    {
        $selection = $this->selection($request, $service);
        $content = $service->prompt($selection);

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, 'prompt-'.$selection['label'].'.md', ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    private function selection(Request $request, ExportService $service): array
    {
        $defaultStart = now()->startOfMonth()->toDateString();
        $defaultEnd = now()->endOfMonth()->toDateString();
        $input = [
            'mode' => $request->query('mode', 'period'),
            'start_date' => $request->query('start_date', $defaultStart),
            'end_date' => $request->query('end_date', $defaultEnd),
        ];
        $validated = Validator::make($input, [
            'mode' => ['required', Rule::in(['period', 'history'])],
            'start_date' => ['nullable', 'required_if:mode,period', 'date'],
            'end_date' => ['nullable', 'required_if:mode,period', 'date', 'after_or_equal:start_date'],
        ])->validate();

        return $service->selection($validated);
    }
}
