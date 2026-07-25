<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\PaymentMethod;
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
        'pos_transaction_id',
        'pos_session_id',
        'terminal_id',
        'cashier_user_id',
        'customer_id',
        'status',
        'idempotency_key',
        'payload_hash',
        'original_cart_snapshot',
        'subtotal',
        'discount_total',
        'tax_total',
        'grand_total',
        'paid_total',
        'change_total',
        'payment_method_id',
        'payment_reference',
        'receipt_number',
        'sale_id',
        'sale_payment_id',
        'dispatch_ids',
        'response_payload',
        'split_summary',
        'failure_code',
        'failure_message',
        'metadata',
        'finalized_at',
        'note',
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
        'split_summary' => 'array',
        'metadata' => 'array',
        'original_cart_snapshot' => 'array',
        'finalized_at' => 'datetime',
        'sale_id' => 'integer',
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

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PosTransaction::class, 'pos_transaction_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function printLogs()
    {
        return $this->hasMany(PosReceiptPrintLog::class, 'pos_checkout_id', 'id');
    }

    public function checkoutSales(): HasMany
    {
        return $this->hasMany(PosCheckoutSale::class, 'pos_checkout_id', 'id');
    }

    public function sales(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\Modules\Sale\Entities\Sale::class, 'pos_checkout_sales', 'pos_checkout_id', 'sale_id')
            ->withPivot(['split_key', 'source_setting_id', 'source_location_id', 'tax_bucket'])
            ->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PosCheckoutPayment::class, 'pos_checkout_id')->orderBy('sequence_order');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PosCheckoutPaymentAllocation::class, 'pos_checkout_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PosTransaction::class, 'completed_checkout_id');
    }

    public function getReachableSales()
    {
        // Task 1b: Return Sales reachable from this checkout.
        // Bypass ArchivingScope on both pivot and fallback branches so archived Sales appear.

        // First check pivot-based sales (split posting)
        $pivotSales = $this->sales()->withoutGlobalScope(\App\Scopes\ArchivingScope::class)->get();

        if ($pivotSales->isNotEmpty()) {
            return $pivotSales;
        }

        // Fallback to inline path: checkout's sale_id
        if ($this->sale_id) {
            return Sale::withoutGlobalScope(\App\Scopes\ArchivingScope::class)
                ->where('id', $this->sale_id)
                ->get();
        }

        return collect();
    }
}
