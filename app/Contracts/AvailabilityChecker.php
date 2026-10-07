<?php

namespace App\Contracts;

interface AvailabilityChecker
{
    public function responds(string $url): bool;
}
