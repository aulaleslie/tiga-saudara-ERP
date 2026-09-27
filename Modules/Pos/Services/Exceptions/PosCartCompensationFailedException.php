<?php

namespace Modules\Pos\Services\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when a row override failed after the cart was persisted AND the
 * pre-operation cart could not be restored.
 *
 * This is an operational alarm, not a domain error. It means the invariant
 * "failed token or audit persistence must not leave the override applied" could
 * not be upheld: the cashier's cart may still show an override that was never
 * consumed or audited. It must never be reported as a plain validation failure,
 * and it must never be silently swallowed.
 *
 * The failure that triggered compensation is attached as the previous exception
 * so the root cause is not lost behind the compensation failure.
 */
class PosCartCompensationFailedException extends RuntimeException
{
    public function __construct(
        private readonly int $settingId,
        private readonly int $posSessionId,
        private readonly string $action,
        private readonly ?Throwable $restorationFailure,
        ?Throwable $originalFailure = null
    ) {
        parent::__construct(
            'CART_COMPENSATION_FAILED: POS cart could not be restored after a failed row override.',
            0,
            $originalFailure
        );
    }

    public function errorCode(): string
    {
        return 'CART_COMPENSATION_FAILED';
    }

    public function httpStatus(): int
    {
        return 500;
    }

    public function settingId(): int
    {
        return $this->settingId;
    }

    public function posSessionId(): int
    {
        return $this->posSessionId;
    }

    public function action(): string
    {
        return $this->action;
    }

    /**
     * The exception raised while attempting restoration, if restoration threw
     * rather than merely producing an unverifiable result.
     */
    public function restorationFailure(): ?Throwable
    {
        return $this->restorationFailure;
    }
}
