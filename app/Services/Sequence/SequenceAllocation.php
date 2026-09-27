<?php

namespace App\Services\Sequence;

use InvalidArgumentException;

final class SequenceAllocation
{
    public readonly SequenceNamespace $namespace;
    public readonly int $number;
    public readonly string $reference;

    public function __construct(SequenceNamespace $namespace, int $number, string $reference)
    {
        if ($number <= 0) {
            throw new InvalidArgumentException("Allocation number must be a positive integer, got: {$number}");
        }

        $this->namespace = $namespace;
        $this->number = $number;
        $this->reference = $reference;
    }

    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace->toArray(),
            'number' => $this->number,
            'reference' => $this->reference,
        ];
    }
}
