<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;

/**
 * Append-only evidence of a reduction/removal applied to a PosOwnerPaymentTransfer (e.g. refund of an overpaid replacement).
 */
class PosOwnerPaymentTransferReversal extends BaseModel
{
    protected bool $uppercaseAllText = false;

    protected $table = 'pos_owner_payment_transfer_reversals';

    protected $fillable = [
        'transfer_id',
        'sale_return_id',
        'actor_id',
        'reduced_minor',
        'amount_minor_before',
        'amount_minor_after',
        'transfer_deleted',
        'reason',
        'transfer_snapshot',
    ];

    protected $casts = [
        'transfer_deleted' => 'boolean',
        'transfer_snapshot' => 'array',
        'reduced_minor' => 'integer',
        'amount_minor_before' => 'integer',
        'amount_minor_after' => 'integer',
    ];
}
