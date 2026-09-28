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

];
