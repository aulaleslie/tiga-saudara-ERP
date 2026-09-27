<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PosActionApprovalRequest extends BaseModel
{
    public const STATUS_PENDING   = 'PENDING';
    public const STATUS_APPROVED  = 'APPROVED';
    public const STATUS_REJECTED  = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_EXPIRED   = 'EXPIRED';
    public const STATUS_CONSUMED  = 'CONSUMED';

    public const ACTION_CART_CLEAR = 'CART_CLEAR';
    public const ACTION_LINE_REMOVE = 'LINE_REMOVE';
    public const ACTION_QTY_REDUCE = 'QTY_REDUCE';
    public const ACTION_TRANSACTION_CANCEL = 'TRANSACTION_CANCEL';
    public const ACTION_CHECKOUT_AS_DEBT = 'CHECKOUT_AS_DEBT';

    /**
     * Active row-scoped monetary overrides.
     *
     * Two distinct types rather than one type with a mode flag: tokens are
     * action-specific, and a shared type would let a unit-price approval
     * authorize a row-total change through a mutable discriminator.
     */
    public const ACTION_LINE_UNIT_PRICE_OVERRIDE = 'LINE_UNIT_PRICE_OVERRIDE';
    public const ACTION_LINE_TOTAL_OVERRIDE = 'LINE_TOTAL_OVERRIDE';

    /**
     * Retired action types.
     *
     * Retained so historical rows still deserialize and render read-only.
     * They MUST NOT be created for new requests and MUST NOT authorize any new
     * operation — `PRICE_OVERRIDE` was ambiguous about whether it carried a
     * unit price or a row total, and `TOTAL_PRICE_OVERRIDE` was cart-wide.
     */
    public const ACTION_PRICE_OVERRIDE = 'PRICE_OVERRIDE';
    public const ACTION_TOTAL_PRICE_OVERRIDE = 'TOTAL_PRICE_OVERRIDE';

    /**
     * Action types that may be created and may authorize a new operation.
     *
     * @var array<int, string>
     */
    public const ACTIVE_ACTIONS = [
        self::ACTION_CART_CLEAR,
        self::ACTION_LINE_REMOVE,
        self::ACTION_QTY_REDUCE,
        self::ACTION_TRANSACTION_CANCEL,
        self::ACTION_CHECKOUT_AS_DEBT,
        self::ACTION_LINE_UNIT_PRICE_OVERRIDE,
        self::ACTION_LINE_TOTAL_OVERRIDE,
    ];

    /**
     * Readable-but-never-authorizing action types.
     *
     * @var array<int, string>
     */
    public const RETIRED_ACTIONS = [
        self::ACTION_PRICE_OVERRIDE,
        self::ACTION_TOTAL_PRICE_OVERRIDE,
    ];

    /**
     * The two active row-scoped monetary override actions.
     *
     * @var array<int, string>
     */
    public const ROW_OVERRIDE_ACTIONS = [
        self::ACTION_LINE_UNIT_PRICE_OVERRIDE,
        self::ACTION_LINE_TOTAL_OVERRIDE,
    ];

    public static function isRetiredAction(string $actionType): bool
    {
        return in_array(strtoupper($actionType), self::RETIRED_ACTIONS, true);
    }

    public static function isActiveAction(string $actionType): bool
    {
        return in_array(strtoupper($actionType), self::ACTIVE_ACTIONS, true);
    }

    public static function isRowOverrideAction(string $actionType): bool
    {
        return in_array(strtoupper($actionType), self::ROW_OVERRIDE_ACTIONS, true);
    }

    protected $table = 'pos_action_approval_requests';

    protected $fillable = [
        'setting_id',
        'pos_session_id',
        'action_type',
        'target_type',
        'target_id',
        'request_payload',
        'requested_by',
        'status',
        'decided_by',
        'decided_at',
        'decision_reason',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'decided_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function token(): HasOne
    {
        return $this->hasOne(PosActionApprovalToken::class, 'approval_request_id');
    }
}
