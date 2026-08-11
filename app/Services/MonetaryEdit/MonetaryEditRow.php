<?php

namespace App\Services\MonetaryEdit;

/**
 * One submitted cart row, already resolved to the persisted detail row it
 * claims to be. Produced by the row-mapping step so persistence never has to
 * guess which detail an incoming line belongs to.
 */
class MonetaryEditRow
{
    /**
     * @param  object  $detail  the locked, persisted detail model
     * @param  mixed  $cartItem  the submitted cart row
     */
    public function __construct(
        public readonly int $detailId,
        public readonly object $detail,
        public readonly mixed $cartItem,
    ) {
    }
}
