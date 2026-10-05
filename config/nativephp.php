<?php

use App\Providers\NativeAppServiceProvider;

return [
    'version' => env('NATIVEPHP_APP_VERSION', '1.0.0'),
    'app_id' => env('NATIVEPHP_APP_ID', 'com.controlegastos.desktop'),
    'author' => env('NATIVEPHP_APP_AUTHOR', 'Controle de Gastos'),
    'copyright' => env('NATIVEPHP_APP_COPYRIGHT', 'Controle de Gastos'),
    'description' => 'Controle de Gastos local',
    'provider' => NativeAppServiceProvider::class,
    'cleanup_env_keys' => ['AWS_*', 'AZURE_*', 'GITHUB_*', '*_SECRET', 'NATIVEPHP_UPDATER_PATH'],
    'cleanup_exclude_files' => ['build', 'temp', 'content', 'node_modules', '*/tests'],
    'updater' => [
        'enabled' => false,
        'default' => 'github',
        'providers' => [],
    ],
    'queue_workers' => [],
    'prebuild' => ['npm run build'],
    'postbuild' => [],
    'nsis' => [
        'delete_app_data_on_uninstall' => false,
    ],
    'binary_path' => env('NATIVEPHP_PHP_BINARY_PATH'),
];
