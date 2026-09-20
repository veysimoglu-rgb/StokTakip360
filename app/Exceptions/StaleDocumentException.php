<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a document was changed by someone else between opening the edit
 * form and saving it. Nothing is written; the user must reload.
 */
class StaleDocumentException extends RuntimeException {}
