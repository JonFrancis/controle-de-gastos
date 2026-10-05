<?php

namespace App\Services;

use App\Contracts\BrowserLauncher;

class SystemBrowserLauncher implements BrowserLauncher
{
    public function open(string $url): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" "'.$url.'"', 'r'));

            return;
        }

        $command = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
        pclose(popen($command.' '.escapeshellarg($url).' >/dev/null 2>&1 &', 'r'));
    }
}
