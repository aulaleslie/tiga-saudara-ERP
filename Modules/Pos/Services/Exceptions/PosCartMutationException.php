<?php

namespace Modules\Pos\Services\Exceptions;

use DomainException;

class PosCartMutationException extends DomainException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus = 422
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
