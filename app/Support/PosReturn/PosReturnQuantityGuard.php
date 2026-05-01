<?php

namespace App\Support\PosReturn;

class PosReturnQuantityGuard
{
    /**
     * Check if the requested return quantity is valid.
     *
     * @param int|null $dispatchDetailId
     * @param float $requestedQuantity
     * @param array $options
     * @return bool
     */
    public function isValid(?int $dispatchDetailId, float $requestedQuantity, array $options = []): bool
    {
        // TODO: Implement cumulative quantity check
        return true;
    }
}
