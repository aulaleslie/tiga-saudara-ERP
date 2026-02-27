<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;

class PosCheckout extends BaseModel
{
    public const STATUS_FINALIZING = 'FINALIZING';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_FAILED = 'FAILED';

    protected bool $uppercaseAllText = false;

    protected $table = 'pos_checkouts';

    protected $fillable = [
        'setting_id',
        'pos_session_id',
        'terminal_id',
        'cashier_user_id',
        'customer_id',
        'status',
        'idempotency_key',
        'payload_hash',
        'subtotal',
        'discount_total',
        'tax_total',
        'grand_total',
        'paid_total',
        'change_total',
        'payment_method_code',
        'payment_reference',
        'receipt_number',
        'sale_id',
        'sale_payment_id',
        'dispatch_ids',
        'response_payload',
        'failure_code',
        'failure_message',
        'metadata',
        'finalized_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'paid_total' => 'decimal:2',
        'change_total' => 'decimal:2',
        'dispatch_ids' => 'array',
        'response_payload' => 'array',
        'metadata' => 'array',
        'finalized_at' => 'datetime',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function salePayment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class, 'sale_payment_id');
    }

    public function printLogs()
    {
        return $this->hasMany(PosReceiptPrintLog::class, 'pos_checkout_id', 'id');
    }
}
