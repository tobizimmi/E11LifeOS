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

];
