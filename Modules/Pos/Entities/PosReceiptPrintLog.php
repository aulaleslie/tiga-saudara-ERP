<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosReceiptPrintLog extends BaseModel
{
    protected $table = 'pos_receipt_print_logs';

    const TYPE_PRINT = 'PRINT';
    const TYPE_REPRINT = 'REPRINT';

    protected $fillable = [
        'setting_id',
        'pos_checkout_id',
        'print_type',
        'printed_by',
        'printed_at',
        'metadata',
    ];

    protected $casts = [
        'printed_at' => 'datetime',
        'metadata' => 'json',
    ];

    public $timestamps = true;

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(PosCheckout::class, 'pos_checkout_id', 'id');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by', 'id');
    }
}
