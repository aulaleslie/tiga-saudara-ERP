<?php

namespace Modules\Pos\Exceptions;

use RuntimeException;
use Throwable;

class PosReprintProjectionException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
