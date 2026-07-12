<?php

namespace Modules\Adjustment\Exceptions;

use Exception;

class AllocationDriftException extends Exception
{
    public string $hash;
    public array $allocations;

    public function __construct(string $message, string $hash, array $allocations = [])
    {
        parent::__construct($message);
        $this->hash = $hash;
        $this->allocations = $allocations;
    }
}
