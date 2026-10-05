<?php

namespace App\Services;

use Symfony\Component\Process\ExecutableFinder;

class NativePackageManager
{
    public function __construct(private readonly ExecutableFinder $executableFinder = new ExecutableFinder) {}

    public function activatePnpmFallback(): bool
    {
        $npm = $this->executableFinder->find('npm');
        $pnpm = $this->executableFinder->find('pnpm');

        if (! $this->shouldUsePnpmFallback($npm, $pnpm)) {
            return false;
        }

        $fallbackDirectory = base_path('bin');

        if (! is_file($fallbackDirectory.DIRECTORY_SEPARATOR.'npm.cmd')) {
            return false;
        }

        putenv('PATH='.$this->prependPath($fallbackDirectory, (string) getenv('PATH')));

        return true;
    }

    public function shouldUsePnpmFallback(?string $npm, ?string $pnpm): bool
    {
        return $npm === null && $pnpm !== null;
    }

    public function prependPath(string $directory, string $path): string
    {
        $paths = array_filter(explode(PATH_SEPARATOR, $path));

        if (! in_array($directory, $paths, true)) {
            array_unshift($paths, $directory);
        }

        return implode(PATH_SEPARATOR, $paths);
    }
}
