<?php

namespace App\Exceptions;

use RuntimeException;

class PosException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 422,
        private readonly ?array $details = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): ?array
    {
        return $this->details;
    }
}
