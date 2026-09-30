<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Security & operations
    |--------------------------------------------------------------------------
    |
    | require_two_factor: every member must set up two-factor authentication
    | before using the app. error_report_email: where errors on the server are
    | reported (at most once per error and hour).
    |
    */

    'require_two_factor' => (bool) env('SECURITY_REQUIRE_TWO_FACTOR', false),

    'error_report_email' => env('ERROR_REPORT_EMAIL'),

    // Signed-in browsers without activity for this many minutes are signed out (0 = never).
    'idle_minutes' => (int) env('SECURITY_IDLE_MINUTES', 30),

    // Security log entries are kept this many days.
    'log_days' => (int) env('SECURITY_LOG_DAYS', 365),

    // App tokens (mobile app) unused for this many days stop working and are deleted (0 = never).
    'api_token_idle_days' => (int) env('SECURITY_API_TOKEN_IDLE_DAYS', 90),

];
