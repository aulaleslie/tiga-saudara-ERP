<?php

namespace Modules\Adjustment\Entities;

/**
 * Backed status values for Adjustment documents.
 *
 * `Pending` remains the legacy/breakage status. Normal versioned Stock Opname
 * documents use Draft, WaitingApproval, Rejected, and Approved.
 *
 * Adjustment extends BaseModel, which uppercases all string attributes on
 * write (see App\Models\BaseModel::setAttribute). Case values are uppercase
 * so the enum's backing value matches what is actually persisted.
 */
enum AdjustmentStatus: string
{
    case Pending = 'PENDING';
    case Draft = 'DRAFT';
    case WaitingApproval = 'WAITING_APPROVAL';
    case Rejected = 'REJECTED';
    case Approved = 'APPROVED';

    /** @return list<self> */
    public static function normalLifecycle(): array
    {
        return [self::Draft, self::WaitingApproval, self::Rejected, self::Approved];
    }

    public static function normalize(self|string|null $status): self
    {
        if ($status instanceof self) {
            return $status;
        }

        return self::from(mb_strtoupper(trim((string) $status), 'UTF-8'));
    }
}
