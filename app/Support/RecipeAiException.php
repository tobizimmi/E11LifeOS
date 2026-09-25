<?php

namespace App\Support;

use RuntimeException;

/**
 * A recipe import or estimate failed; the message is safe to show to the user.
 */
class RecipeAiException extends RuntimeException {}
