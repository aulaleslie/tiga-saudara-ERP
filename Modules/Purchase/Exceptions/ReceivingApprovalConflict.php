<?php

namespace Modules\Purchase\Exceptions;

use RuntimeException;

/**
 * Raised inside the receiving-approval transaction when the approval cannot
 * proceed: the note was already processed, or approving it would over-receive.
 *
 * Throwing rather than returning is deliberate. These conditions are only safe to
 * evaluate after the database row locks are held, which means they are evaluated
 * inside DB::transaction(); an exception is what rolls that transaction back so no
 * stock is posted, while still carrying the detail out to the caller for rendering.
 */
class ReceivingApprovalConflict extends RuntimeException
{
    /** @param array<int, array<string, mixed>> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
