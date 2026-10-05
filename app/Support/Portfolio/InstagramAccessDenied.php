<?php

namespace App\Support\Portfolio;

use RuntimeException;

/**
 * Apify refused the request (key invalid, plan or monthly limit used up): a setup problem for
 * the administrator, not an error of the app.
 */
class InstagramAccessDenied extends RuntimeException {}
