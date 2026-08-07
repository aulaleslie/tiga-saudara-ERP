<?php

namespace Modules\Sale\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\ReportingDateAudit;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Tags\HasTags;
use Spatie\Tags\Tag;
use App\Traits\Archivable;

class Sale extends BaseModel
{
    use HasTags, Archivable;

    protected $guarded = [];

    protected $casts = [
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
        'archived_at' => 'datetime',
        'date' => 'date',
        'reporting_date' => 'date',
        'due_date' => 'date',
    ];

    const STATUS_DRAFTED = 'DRAFTED';
    const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_DISPATCHED_PARTIALLY = 'DISPATCHED PARTIALLY';
    const STATUS_DISPATCHED = 'DISPATCHED';
    const STATUS_RETURNED = 'RETURNED';
    const STATUS_RETURNED_PARTIALLY = 'RETURNED PARTIALLY';

    public function saleDetails(): HasMany
    {
        return $this->hasMany(SaleDetails::class, 'sale_id', 'id');
    }

    public function salePayments(): HasMany
    {
        return $this->hasMany(SalePayment::class, 'sale_id', 'id');
    }

    public function saleDispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class);
    }

    public function dispatchDetails(): HasMany
    {
        return $this->hasMany(DispatchDetail::class, 'sale_id', 'id');
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(SaleBundleItem::class, 'sale_id', 'id');
    }

    public function posCheckout(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\Modules\Pos\Entities\PosCheckout::class, 'sale_id', 'id');
    }

    public function checkoutSale(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\Modules\Pos\Entities\PosCheckoutSale::class, 'sale_id', 'id');
    }

    public function serialTrackings(): HasMany
    {
        return $this->hasMany(SalesOrderSerialTracking::class, 'sale_id', 'id');
    }

    public function reportingDateAudits(): MorphMany
    {
        return $this->morphMany(ReportingDateAudit::class, 'auditable');
    }

    public function getEffectiveDateAttribute(): ?\Carbon\CarbonInterface {
        return $this->reporting_date ?? $this->date;
    }

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            // Respect provided reference if set manually (e.g. from POS or direct API)
            if ($model->reference) {
                return;
            }

            // Fallback reference allocation when not using DocumentReferenceService.
            // WARNING: This hook provides basic concurrency safety via Setting row locking,
            // but it is NOT suitable for high-concurrency scenarios. Prefer using
            // DocumentReferenceService::createSaleWithReference() which holds the lock
            // from allocation through INSERT for stronger atomicity guarantees.
            // Raw Sale::create() calls bypass the dedicated service and should only be used
            // in tests or when providing an explicit reference.
            $model->reference = DB::transaction(function () use ($model) {
                $saleDate = $model->date ? Carbon::parse($model->date) : now();
                $year = $saleDate->year;
                $month = $saleDate->month;

                // Lock the target setting row to serialize reference allocation
                $setting = Setting::whereKey($model->setting_id)->lockForUpdate()->firstOrFail();

                // Fetch the latest reference for this setting, year, and month
                $latestReference = Sale::withArchived()
                    ->where('setting_id', $model->setting_id)
                    ->whereYear('date', $year)
                    ->whereMonth('date', $month)
                    ->latest('id')
                    ->value('reference');

                // Extract the number from the latest reference
                $nextNumber = 1; // Default to 1 if no reference exists
                if ($latestReference) {
                    $parts = explode('-', $latestReference);
                    $lastNumber = (int) end($parts);
                    $nextNumber = $lastNumber + 1;
                }

                // Build prefix:
                // 1) take document_prefix if truthy, else empty string
                // 2) then take sale_prefix_document if truthy, else fallback to 'SL'
                $prefix = (optional($setting)->document_prefix ?: '') . '-'
                    . (optional($setting)->sale_prefix_document ?: 'SL');

                // Generate and return the new reference ID
                return make_reference_id($prefix, $year, $month, $nextNumber);
            });
        });
    }

    public static function generateReference(int $settingId, ?Carbon $date = null): string
    {
        $saleDate = $date ?? now();
        $year = $saleDate->year;
        $month = $saleDate->month;

        // Lock the Setting row to serialize all reference allocations for this business
        return DB::transaction(function () use ($settingId, $year, $month) {
            // Lock the target setting row to serialize reference allocation
            $setting = Setting::whereKey($settingId)->lockForUpdate()->firstOrFail();

            $latestReference = Sale::withArchived()
                ->where('setting_id', $settingId)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->latest('id')
                ->value('reference');

            $nextNumber = 1;
            if ($latestReference) {
                $parts = explode('-', $latestReference);
                $lastNumber = (int) end($parts);
                $nextNumber = $lastNumber + 1;
            }

            $prefix = (optional($setting)->document_prefix ?: '') . '-'
                . (optional($setting)->sale_prefix_document ?: 'SL');

            return make_reference_id($prefix, $year, $month, $nextNumber);
        });
    }

    public function scopeCompleted($query) {
        return $query->where('status', self::STATUS_DISPATCHED);
    }

    /**
     * Get the canonical settlement SQL formula for live due amount.
     * This formula is the single source of truth for settlement calculations:
     *   live_due = total_amount - (active cash payments + active credit applications)
     *
     * Used by scopeWhereLiveDueAmountGreaterThan and scopeWhereLiveDueAmountLessThanOrEqual
     * to ensure both scopes implement the exact same calculation without drift.
     */
    private static function canonicalLiveDueFormula(): string
    {
        return 'total_amount - COALESCE((
            SELECT SUM(amount) FROM sale_payments
            WHERE sale_payments.sale_id = sales.id
            AND sale_payments.status = ?
        ), 0) - COALESCE((
            SELECT SUM(spca.amount) FROM sale_payment_credit_applications spca
            INNER JOIN sale_payments sp ON spca.sale_payment_id = sp.id
            WHERE sp.sale_id = sales.id
            AND sp.status = ?
        ), 0)';
    }

    /**
     * Filter sales by live due amount using the canonical settlement calculation.
     * Live due = total_amount - (active cash payments + active credit applications).
     * Includes customer-credit applications to match getEffectivePaidAmount().
     *
     * Excludes invalidated payments and their associated credits.
     */
    public function scopeWhereLiveDueAmountGreaterThan($query, $amount = 0)
    {
        return $query->whereRaw(
            self::canonicalLiveDueFormula() . ' > ?',
            [SalePayment::STATUS_ACTIVE, SalePayment::STATUS_ACTIVE, $amount]
        );
    }

    /**
     * Filter sales by live due amount using the canonical settlement calculation.
     * Live due = total_amount - (active cash payments + active credit applications).
     * Includes customer-credit applications to match getEffectivePaidAmount().
     *
     * Excludes invalidated payments and their associated credits.
     */
    public function scopeWhereLiveDueAmountLessThanOrEqual($query, $amount = 0)
    {
        return $query->whereRaw(
            self::canonicalLiveDueFormula() . ' <= ?',
            [SalePayment::STATUS_ACTIVE, SalePayment::STATUS_ACTIVE, $amount]
        );
    }

    public function scopeApprovedUp($query)
    {
        return $query->whereIn('status', [
            self::STATUS_APPROVED,
            self::STATUS_DISPATCHED_PARTIALLY,
            self::STATUS_DISPATCHED,
        ]);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    /**
     * Calculate the canonical settlement total from active payments and credits.
     * This is the single source of truth for settlement calculations:
     *   - SUM(sale_payments.amount WHERE status = ACTIVE)
     *   + SUM(sale_payment_credit_applications.amount WHERE sale_payment.status = ACTIVE)
     *
     * Used consistently by:
     *   - getEffectivePaidAmount() (PHP accessor for individual sale)
     *   - getLiveDueAmountAttribute() (PHP accessor for live balance)
     *   - reconcileFromActivePayments() (atomic header reconciliation)
     *   - scopeWhereLiveDueAmountGreaterThan() (query-level filtering)
     *   - scopeWhereLiveDueAmountLessThanOrEqual() (query-level filtering)
     *
     * Excludes invalidated payments and their associated credits.
     */
    public function getEffectivePaidAmount(): float
    {
        if (array_key_exists('active_payments_sum', $this->attributes)) {
            $monetarySum = (float) ($this->attributes['active_payments_sum'] ?: 0);
        } else {
            $monetarySum = (float) $this->salePayments()
                ->where('status', SalePayment::STATUS_ACTIVE)
                ->sum('amount');
        }

        // Get sum of credit applications attached to active payments
        $creditSum = (float) DB::table('sale_payment_credit_applications')
            ->join('sale_payments', 'sale_payment_credit_applications.sale_payment_id', '=', 'sale_payments.id')
            ->where('sale_payments.sale_id', $this->id)
            ->where('sale_payments.status', SalePayment::STATUS_ACTIVE)
            ->sum('sale_payment_credit_applications.amount');

        return round($monetarySum + $creditSum, 2);
    }

    /**
     * Get live outstanding balance derived from total_amount minus active payments and credits.
     * Returns max(0, total_amount - getEffectivePaidAmount()).
     * Preserves any existing customer-credit settlement effects when reconciling.
     */
    public function getLiveDueAmountAttribute(): float
    {
        return max(0, round($this->total_amount - $this->getEffectivePaidAmount(), 2));
    }

    /**
     * Get live paid amount derived from active payments and credits.
     * Returns the same value as getEffectivePaidAmount() as a dynamic accessor.
     */
    public function getLivePaidAmountAttribute(): float
    {
        return $this->getEffectivePaidAmount();
    }

    public function getPaymentDueDateAttribute(): ?string
    {
        return $this->due_date ? \Carbon\Carbon::parse($this->due_date)->format('Y-m-d') : null;
    }

    /**
     * Reconcile sale header from canonical settlement totals.
     * Updates paid_amount, due_amount, and payment_status from active payments and existing credits.
     * Used after payment allocation to ensure consistency.
     */
    public function reconcileFromActivePayments(): void
    {
        $paidAmount = $this->getEffectivePaidAmount();
        $dueAmount = round($this->total_amount - $paidAmount, 2);
        $dueAmount = max(0, $dueAmount);

        $status = $dueAmount <= 0 ? 'PAID' : ($paidAmount > 0.01 ? 'PARTIAL' : 'UNPAID');

        $this->update([
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_status' => $status,
        ]);
    }

    /**
     * Get all serial numbers associated with this sale through sale details.
     */
    public function serialNumbers(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProductSerialNumber::class,
            SaleDetails::class,
            'sale_id',
            'id'
        )->distinct();
    }

    /**
     * Get the seller (user) who created this sale.
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * Get the setting (tenant) for this sale through the location.
     */
    public function tenantSetting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id', 'id');
    }

    /**
     * Get the location associated with this sale.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'id');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        \Illuminate\Support\Facades\Log::info('Sale::resolveRouteBinding called', ['value' => $value, 'field' => $field]);
        $model = $this->where($field ?? $this->getRouteKeyName(), $value)
            ->withoutGlobalScopes()
            ->first();
        
        if (!$model) {
            \Illuminate\Support\Facades\Log::warning('Sale::resolveRouteBinding: Model NOT found', ['value' => $value]);
        }
        
        return $model;
    }
}
