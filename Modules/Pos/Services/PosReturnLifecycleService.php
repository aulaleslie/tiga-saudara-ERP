<?php

namespace Modules\Pos\Services;

class PosReturnLifecycleService
{
    /**
     * Approve a POS return.
     *
     * @param int $posReturnId
     * @return void
     */
    public function approve(int $posReturnId): void
    {
        // TODO: Implement approve logic
    }

    /**
     * Reject a POS return.
     *
     * @param int $posReturnId
     * @param string|null $reason
     * @return void
     */
    public function reject(int $posReturnId, ?string $reason = null): void
    {
        // TODO: Implement reject logic
    }
}
