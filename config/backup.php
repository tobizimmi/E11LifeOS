<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backups (php artisan backup:run, daily via the scheduler)
    |--------------------------------------------------------------------------
    |
    | Where the ZIP files (database dump + uploaded files) are written and how
    | many are kept. Point BACKUP_PATH to a folder outside the app (ideally one
    | that is copied off the server) so a lost server does not take them along.
    |
    */

    'path' => env('BACKUP_PATH') ?: storage_path('backups'),

    'keep' => (int) env('BACKUP_KEEP', 14),

    /*
    | Copy off the server: every backup is encrypted (XChaCha20-Poly1305) and uploaded to a WebDAV folder
    | (e.g. Hetzner Storage Box, Nextcloud, many NAS). Without a password nothing is uploaded.
    */

    'remote_url' => env('BACKUP_REMOTE_URL'),

    'remote_username' => env('BACKUP_REMOTE_USERNAME'),

    'remote_password' => env('BACKUP_REMOTE_PASSWORD'),

    'encryption_password' => env('BACKUP_ENCRYPTION_PASSWORD'),

    'remote_keep' => (int) env('BACKUP_REMOTE_KEEP', 30),

];
