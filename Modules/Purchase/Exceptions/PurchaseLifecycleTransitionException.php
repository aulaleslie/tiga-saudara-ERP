<?php

namespace Modules\Purchase\Exceptions;

use RuntimeException;

class PurchaseLifecycleTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
