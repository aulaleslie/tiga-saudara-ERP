<?php

namespace App\Services\MonetaryEdit;

use RuntimeException;

/**
 * Raised when a post-fulfillment monetary edit violates a lifecycle,
 * ownership, row-identity, or payment invariant.
 *
 * These are deliberate rejections, not failures: the caller is expected to
 * surface the message to the user and leave the document untouched.
 */
class MonetaryEditException extends RuntimeException
{
    public function __construct(string $message, private readonly string $field = 'cart')
    {
        parent::__construct($message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
