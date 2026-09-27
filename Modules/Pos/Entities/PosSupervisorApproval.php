<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSupervisorApproval extends BaseModel
{
    /**
     * Retired audit action types. Historical rows remain readable and render
     * read-only; neither may be written for a new execution.
     */
    public const ACTION_PRICE_OVERRIDE = 'PRICE_OVERRIDE';

    public const ACTION_TOTAL_PRICE_OVERRIDE_APPROVAL = 'TOTAL_PRICE_OVERRIDE_APPROVAL';

    /** Active row-scoped monetary override audits. */
    public const ACTION_LINE_UNIT_PRICE_OVERRIDE = 'LINE_UNIT_PRICE_OVERRIDE';

    public const ACTION_LINE_TOTAL_OVERRIDE = 'LINE_TOTAL_OVERRIDE';

    public const ACTION_SAFE_DROP_APPROVAL = 'SAFE_DROP_APPROVAL';

    public const ACTION_SESSION_CLOSE_VARIANCE_APPROVAL = 'SESSION_CLOSE_VARIANCE_APPROVAL';

    public const ACTION_CART_CLEAR_APPROVAL = 'CART_CLEAR_APPROVAL';

    public const ACTION_LINE_REMOVE_APPROVAL = 'LINE_REMOVE_APPROVAL';

    public const ACTION_QTY_REDUCE_APPROVAL = 'QTY_REDUCE_APPROVAL';

    public const ACTION_TRANSACTION_CANCEL_APPROVAL = 'TRANSACTION_CANCEL_APPROVAL';

    public const ACTION_CHECKOUT_AS_DEBT_APPROVAL = 'CHECKOUT_AS_DEBT_APPROVAL';

    public const RESULT_APPROVED = 'APPROVED';

    public const RESULT_REJECTED = 'REJECTED';

    protected $table = 'pos_supervisor_approvals';

    protected $fillable = [
        'setting_id',
        'action_type',
        'target_type',
        'target_id',
        'requested_by',
        'approved_by',
        'approval_result',
        'reason',
        'context_snapshot',
        'occurred_at',
    ];

    protected $casts = [
        'context_snapshot' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected function shouldUppercase(string $key): bool
    {
        if ($key === 'target_type') {
            return false;
        }

        return parent::shouldUppercase($key);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
