<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class OpenAiException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
