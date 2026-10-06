<?php

namespace App\Support;

use Exception;

/**
 * A recipe file (schema.org JSON, a Paprika archive, …) could not be read.
 */
class RecipeFileImportException extends Exception {}
