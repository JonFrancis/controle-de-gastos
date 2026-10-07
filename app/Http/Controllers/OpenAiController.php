<?php

namespace App\Http\Controllers;

use App\Exceptions\OpenAiException;
use App\Http\Requests\SendToOpenAiRequest;
use App\Services\OpenAiResponseService;
use Illuminate\Http\RedirectResponse;

class OpenAiController extends Controller
{
    public function __invoke(SendToOpenAiRequest $request, OpenAiResponseService $service): RedirectResponse
    {
        try {
            $response = $service->send($request->validated('content'));
        } catch (OpenAiException $exception) {
            return back()->withErrors(['openai' => $exception->getMessage()])->withInput();
        }

        return back()->with('openai', ['response' => $response]);
    }
}
