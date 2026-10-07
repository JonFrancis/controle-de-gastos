<?php

namespace App\Services;

use App\Contracts\AvailabilityChecker;
use Illuminate\Support\Facades\Http;

class HttpAvailabilityChecker implements AvailabilityChecker
{
    public function responds(string $url): bool
    {
        try {
            return Http::timeout(1)->get($url)->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
