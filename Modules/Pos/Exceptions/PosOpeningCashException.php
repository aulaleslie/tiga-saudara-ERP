<?php

declare(strict_types=1);

namespace Modules\Pos\Exceptions;

use DomainException;

/**
 * Opening cash validation failure tied to a specific form field.
 */
class PosOpeningCashException extends DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}

