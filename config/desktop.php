<?php

return [
    'source_database_path' => env('DESKTOP_SOURCE_DATABASE_PATH', database_path('database.sqlite')),
    'target_database_path' => env('DESKTOP_TARGET_DATABASE_PATH', storage_path('app/native/database.sqlite')),
    'migration_marker_path' => env('DESKTOP_MIGRATION_MARKER_PATH', storage_path('app/native/.database-migrated.json')),
    'migration_notice_path' => env('DESKTOP_MIGRATION_NOTICE_PATH', storage_path('app/native/.database-migration-notice.json')),
    'migration_backup_path' => env('DESKTOP_MIGRATION_BACKUP_PATH', null),
];
