<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use App\Traits\Archivable;
use Modules\Pos\Entities\PosReturn;

class SaleReturn extends BaseModel
{
    use Archivable;

    public const STATUS_PENDING = 'Pending';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_CANCELLED = 'Cancelled';
    public const STATUS_AWAITING_RECEIVING = 'Awaiting Receiving';
    public const STATUS_AWAITING_SETTLEMENT = 'Awaiting Settlement';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_DRAFT = 'Draft';

    public const STATUS_LABELS = [
        'draft' => 'Draf',
        'Draft' => 'Draf',
        'pending' => 'Menunggu',
        'Pending' => 'Menunggu',
        'pending approval' => 'Menunggu Persetujuan',
        'Pending Approval' => 'Menunggu Persetujuan',
        'awaiting receiving' => 'Menunggu Penerimaan',
        'Awaiting Receiving' => 'Menunggu Penerimaan',
        'awaiting settlement' => 'Menunggu Penyelesaian',
        'Awaiting Settlement' => 'Menunggu Penyelesaian',
        'rejected' => 'Ditolak',
        'Rejected' => 'Ditolak',
        'completed' => 'Selesai',
        'Completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
        'Cancelled' => 'Dibatalkan',
    ];

    public const APPROVAL_STATUS_LABELS = [
        'draft' => 'Draf',
        'pending' => 'Menunggu',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
    ];

    public static function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '';
        }
        return self::STATUS_LABELS[$status] ?? (self::STATUS_LABELS[strtolower($status)] ?? $status);
    }

    public static function approvalStatusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '';
        }
        return self::APPROVAL_STATUS_LABELS[strtolower($status)] ?? ucfirst($status);
    }
    
    protected $fillable = [
        'date',
        'reference',
        'sale_id',
        'sale_reference',
        'customer_id',
        'customer_name',
        'setting_id',
        'location_id',
        'tax_percentage',
        'tax_amount',
        'discount_percentage',
        'discount_amount',
        'shipping_amount',
        'total_amount',
        'paid_amount',
        'due_amount',
        'status',
        'payment_status',
        'payment_method',
        'note',
        'pos_return_id',
        'approval_status',
        'return_type',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'settled_at',
        'settled_by',
        'received_at',
        'received_by',
        // The archive action writes these; without them it silently archived nothing.
        'archived_at',
        'archived_by',
        // Custody policy markers: NULL = legacy/unknown, set on FIRST receiving after deployment.
        'custody_policy_version',
        'custody_policy_applied_at',
    ];

    protected $casts = [
        'tax_amount'       => 'decimal:2',
        'discount_amount'  => 'decimal:2',
        'shipping_amount'  => 'decimal:2',
        'total_amount'     => 'decimal:2',
        'paid_amount'      => 'decimal:2',
        'due_amount'       => 'decimal:2',
        'date'             => 'date',
        'approved_at'      => 'datetime',
        'rejected_at'      => 'datetime',
        'settled_at'       => 'datetime',
        'received_at'      => 'datetime',
        'custody_policy_applied_at' => 'datetime',
        'archived_at'      => 'datetime',
    ];

    /**
     * Generate the document reference (SLRN-YYYY-MM-N) when none was supplied. An explicitly
     * supplied reference (e.g. POS) is never overwritten.
     *
     * Numbering is serialized per business and month through the locked document-sequence row
     * (the same allocator Sale and Purchase use), so concurrent returns of DIFFERENT source
     * sales can never read the same "latest + 1". The counter is reconciled against historical
     * references first, so the first allocation continues the existing numbering.
     */
    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (! empty($model->reference)) {
                return;
            }

            $allocate = function () use ($model) {
                $allocator = app(\App\Services\Sequence\DocumentSequenceAllocator::class);
                $date = $model->date ? \Illuminate\Support\Carbon::parse($model->date) : now();
                $namespace = $allocator->buildNamespace(\App\Services\Sequence\DocumentType::SALE_RETURN, (int) $model->setting_id, $date);

                $allocator->reconcileCounter($namespace);

                return $allocator->allocate($namespace)->reference;
            };

            // Normal path runs inside the caller's transaction (after the source-sale lock).
            $model->reference = \Illuminate\Support\Facades\DB::transactionLevel() > 0
                ? $allocate()
                : \Illuminate\Support\Facades\DB::transaction($allocate);
        });
    }

    public function custodies(): HasMany
    {
        return $this->hasMany(SaleReturnCustody::class, 'sale_return_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(SaleReturnInspection::class, 'sale_return_id');
    }

    public function usesHeldCustody(): bool
    {
        return ! is_null($this->custody_policy_version);
    }

    public function saleReturnGoods(): HasMany
    {
        return $this->hasMany(SaleReturnGood::class, 'sale_return_id', 'id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function posReturn(): BelongsTo
    {
        return $this->belongsTo(PosReturn::class, 'pos_return_id');
    }

    public function saleReturnDetails(): Builder|HasMany|SaleReturn
    {
        return $this->hasMany(SaleReturnDetail::class, 'sale_return_id', 'id');
    }

    public function saleReturnPayments(): Builder|HasMany|SaleReturn
    {
        return $this->hasMany(SaleReturnPayment::class, 'sale_return_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\Modules\People\Entities\Customer::class, 'customer_id', 'id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id', 'id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by', 'id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by', 'id');
    }

    public function scopeActive(Builder $query)
    {
        return $query->where('status', '!=', 'Cancelled');
    }

    public function scopePending(Builder $query)
    {
        return $query->where('status', 'Pending');
    }

    public function scopeCompleted(Builder $query)
    {
        return $query->where('status', 'Completed');
    }

    /**
     * Get the credit associated with this return.
     */
    public function customerCredit(): HasOne
    {
        return $this->hasOne(CustomerCredit::class, 'sale_return_id', 'id');
    }

    /**
     * Get the goods received associated with this return.
     */
    public function returnGoods(): HasMany
    {
        return $this->hasMany(SaleReturnGood::class, 'sale_return_id', 'id');
    }

    /**
     * Get the settlement items associated with this return.
     */
    public function settlementItems(): HasMany
    {
        return $this->hasMany(SaleReturnItemSettlement::class, 'sale_return_id', 'id');
    }

    /**
     * Get the settlement status of the return.
     */
    public function getSettlementStatusAttribute(): string
    {
        $items = $this->settlementItems;
        if ($items->isEmpty()) {
            return 'Pending';
        }

        $allSettled = $items->every(function ($item) {
            return in_array($item->status, SaleReturnItemSettlement::finalSettlementStatuses());
        });

        return $allSettled ? 'Settled' : 'Pending';
    }
}
