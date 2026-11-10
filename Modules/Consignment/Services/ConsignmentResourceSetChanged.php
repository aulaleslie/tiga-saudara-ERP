<?php

namespace Modules\Consignment\Services;

/** Thrown inside a lock-planner transaction when the real resource set is larger than the locked one. */
class ConsignmentResourceSetChanged extends \RuntimeException
{
    /** @param array<int, array{0:int,1:int}> $requiredPairs */
    public function __construct(public array $requiredPairs)
    {
        parent::__construct('Resource set changed under lock; retrying with the complete set.');
    }
}
