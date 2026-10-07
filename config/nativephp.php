<?php

use App\Providers\NativeAppServiceProvider;

return [
    'version' => env('NATIVEPHP_APP_VERSION', '1.0.0'),
    'app_id' => env('NATIVEPHP_APP_ID', 'com.controlegastos.desktop'),
    'author' => env('NATIVEPHP_APP_AUTHOR', 'Controle de Gastos'),
    'copyright' => env('NATIVEPHP_APP_COPYRIGHT', 'Controle de Gastos'),
    'description' => 'Controle de Gastos local',
    'provider' => NativeAppServiceProvider::class,
    'cleanup_env_keys' => ['AWS_*', 'AZURE_*', 'GITHUB_*', '*_SECRET', 'DESKTOP_INCLUDE_SOURCE_DATABASE', 'NATIVEPHP_UPDATER_PATH'],
    'cleanup_exclude_files' => ['build', 'temp', 'content', 'node_modules', '*/tests'],
    'cleanup_include_files' => array_values(array_filter([
        env('DESKTOP_INCLUDE_SOURCE_DATABASE', false) ? 'database/database.sqlite' : null,
    ])),
    'updater' => [
        'enabled' => false,
        'default' => env('NATIVEPHP_UPDATER_PROVIDER', 'github'),
        'providers' => [
            'github' => [
                'driver' => 'github',
                'repo' => env('GITHUB_REPO', 'controle-de-gastos'),
                'owner' => env('GITHUB_OWNER', 'JonFrancis'),
                'token' => env('GITHUB_TOKEN'),
                'vPrefixedTagName' => env('GITHUB_V_PREFIXED_TAG_NAME', true),
                'private' => env('GITHUB_PRIVATE', false),
                'autoupdate_token' => env('GITHUB_AUTOUPDATE_TOKEN'),
                'channel' => env('GITHUB_CHANNEL', 'latest'),
                'releaseType' => env('GITHUB_RELEASE_TYPE', 'draft'),
            ],
        ],
    ],
    'queue_workers' => [],
    'prebuild' => ['npm run build'],
    'postbuild' => [],
    'nsis' => [
        'delete_app_data_on_uninstall' => false,
    ],
    'binary_path' => env('NATIVEPHP_PHP_BINARY_PATH'),
];
