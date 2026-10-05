<?php

namespace App\Contracts;

interface BrowserLauncher
{
    public function open(string $url): void;
}
