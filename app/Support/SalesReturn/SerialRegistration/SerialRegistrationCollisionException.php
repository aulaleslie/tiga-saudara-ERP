<?php

namespace App\Support\SalesReturn\SerialRegistration;

use App\Support\SalesReturn\SalesReturnStateException;

/**
 * A proposed serial was independently registered (product-scoped) before this receiving
 * transaction. The whole action must roll back; proposed text is retained for review.
 */
class SerialRegistrationCollisionException extends SalesReturnStateException
{
    public function __construct(
        public readonly string $claimUuid,
        public readonly int $productId,
        public readonly string $serial,
    ) {
        parent::__construct("Nomor seri {$serial} sudah terdaftar. Tinjau melalui pencarian nomor seri yang ada; penerimaan dibatalkan tanpa perubahan.");
    }
}
