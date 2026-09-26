<?php

namespace App\Support;

use RuntimeException;

/**
 * A Claude API call failed; the message is safe to show to the user.
 */
class AiException extends RuntimeException {}
