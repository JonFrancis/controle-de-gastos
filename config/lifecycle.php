<?php

return [
    'host' => env('LIFECYCLE_HOST', '127.0.0.1'),
    'port' => (int) env('LIFECYCLE_PORT', 8000),
    'state_path' => env('LIFECYCLE_STATE_PATH', storage_path('app/control-de-gastos/lifecycle.json')),
    'log_channel' => env('LIFECYCLE_LOG_CHANNEL', 'stack'),
    'startup_timeout_seconds' => (int) env('LIFECYCLE_STARTUP_TIMEOUT_SECONDS', 30),
];
