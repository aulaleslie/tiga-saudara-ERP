<?php

namespace Modules\Pos\Services\Exceptions;

use RuntimeException;

class PosCheckoutConflictException extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
