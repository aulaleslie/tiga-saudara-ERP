<?php

namespace Modules\Pos\Exceptions;

use RuntimeException;
use Throwable;

class PosReturnManualCorrectionRequiredException extends RuntimeException
{
    public function __construct(
        protected string $lifecycleAction,
        string $auditReason,
        ?Throwable $previous = null
    ) {
        parent::__construct($auditReason, 0, $previous);
    }

    public function lifecycleAction(): string
    {
        return $this->lifecycleAction;
    }

    public function auditReason(): string
    {
        return $this->getMessage();
    }

    public static function forAction(string $lifecycleAction, string $auditReason, ?Throwable $previous = null): self
    {
        return new self($lifecycleAction, $auditReason, $previous);
    }
}