<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Tags\HasTags;
use Spatie\Tags\Tag;
use App\Traits\Archivable;

class Purchase extends BaseModel implements HasMedia
{
    use HasTags;
    use Archivable;
    use InteractsWithMedia;

    protected array $uppercaseExcept = [
        'note', 'rejection_note', 'payment_status'
    ];

    public const SOURCE_ORDINARY = 'ORDINARY';
    public const SOURCE_CONSIGNMENT_BILLING = 'CONSIGNMENT_BILLING';

    protected $fillable = [
        'date',
        'reporting_date',
        'due_date',
        'reference',
        'supplier_id',
        'supplier_purchase_number',
        'tax_ref_no',
        'tax_id',
        'tax_percentage',
        'tax_amount',
        'discount_percentage',
        'discount_amount',
        'payment_term_id',
        'shipping_amount',
        'total_amount',
        'due_amount',
        'status',
        'source_type',
        'payment_status',
        'payment_method',
        'note',
        'setting_id',
        'paid_amount',
        'is_tax_included',
        'supplier_reference_no',
        'archived_at',
        'archived_by',
        'rejection_note'
    ];

    protected $casts = [
        'is_tax_included' => 'boolean',
        'date' => 'date',
        'reporting_date' => 'date',
        'due_date' => 'date',
    ];

    const STATUS_DRAFTED = 'DRAFTED';
    const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_RECEIVED_PARTIALLY = 'RECEIVED PARTIALLY';
    const STATUS_RECEIVED = 'RECEIVED';
    const STATUS_RETURNED = 'RETURNED';
    const STATUS_RETURNED_PARTIALLY = 'RETURNED PARTIALLY';

    const STATUS_LABELS = [
        self::STATUS_DRAFTED => 'Draft',
        self::STATUS_WAITING_APPROVAL => 'Menunggu Persetujuan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_RECEIVED_PARTIALLY => 'Penerimaan Sebagian',
        self::STATUS_RECEIVED => 'Diterima',
        self::STATUS_RETURNED => 'Dikembalikan',
        self::STATUS_RETURNED_PARTIALLY => 'Pengembalian Sebagian',
    ];

    const PAYMENT_STATUS_PAID = 'Paid';
    const PAYMENT_STATUS_PARTIAL = 'Partial';
    const PAYMENT_STATUS_UNPAID = 'Unpaid';

    const PAYMENT_STATUS_LABELS = [
        self::PAYMENT_STATUS_PAID => 'Lunas',
        self::PAYMENT_STATUS_PARTIAL => 'Sebagian',
        self::PAYMENT_STATUS_UNPAID => 'Belum Lunas',
    ];

    const EDIT_MODE_FULL = 'FULL';
    const EDIT_MODE_MONETARY_ONLY = 'MONETARY_ONLY';
    const EDIT_MODE_NONE = 'NONE';

    /**
     * Resolve how far this document may be edited by the given user.
     *
     * Pre-approval states keep their historical behaviour: route/controller
     * `purchases.update` gates already guard entry, so this method does not
     * re-check it there. The exceptional lifecycle states each require the
     * ordinary edit permission *plus* their own lifecycle permission.
     */
    public function resolveEditMode(?\Illuminate\Contracts\Auth\Authenticatable $user = null): string
    {
        if ($this->isConsignmentBilling()) {
            return self::EDIT_MODE_NONE;
        }

        $user = $user ?? auth()->user();
        if (!$user) {
            return self::EDIT_MODE_NONE;
        }

        if (in_array($this->status, [self::STATUS_DRAFTED, self::STATUS_WAITING_APPROVAL, self::STATUS_REJECTED])) {
            return self::EDIT_MODE_FULL;
        }

        // Beyond approval, ordinary edit authority is a prerequisite for the
        // lifecycle-specific permission.
        if (!$user->can('purchases.update')) {
            return self::EDIT_MODE_NONE;
        }

        if ($this->status === self::STATUS_APPROVED) {
            return $user->can('purchases.approved.edit') ? self::EDIT_MODE_FULL : self::EDIT_MODE_NONE;
        }

        if (in_array($this->status, [self::STATUS_RECEIVED_PARTIALLY, self::STATUS_RECEIVED])) {
            return $user->can('purchases.received.monetary.edit') ? self::EDIT_MODE_MONETARY_ONLY : self::EDIT_MODE_NONE;
        }

        return self::EDIT_MODE_NONE;
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFTED,
            self::STATUS_WAITING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_RECEIVED_PARTIALLY,
            self::STATUS_RECEIVED,
        ];
    }

    public static function generateReference(int $settingId, ?Carbon $date = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Purchase::generateReference() requires an active database transaction to guarantee atomic persistence and prevent counter gaps.');
        }

        $allocator = app(\App\Services\Sequence\DocumentSequenceAllocator::class);
        $purchaseDate = $date ?? now();
        $namespace = $allocator->buildNamespace(\App\Services\Sequence\DocumentType::PURCHASE, $settingId, $purchaseDate);

        return $allocator->allocate($namespace)->reference;
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
    }

    public function purchaseDetails() {
        return $this->hasMany(PurchaseDetail::class, 'purchase_id', 'id');
    }

    public function consignmentBillingConfirmation() {
        return $this->hasOne(\Modules\Consignment\Entities\ConsignmentBillingConfirmation::class, 'purchase_id', 'id');
    }

    public function consignmentLineages() {
        return $this->hasMany(\Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage::class, 'purchase_id', 'id');
    }

    public function scopeConsignmentBilling($query) {
        return $query->where('source_type', self::SOURCE_CONSIGNMENT_BILLING);
    }

    public function scopeOrdinary($query) {
        return $query->where('source_type', self::SOURCE_ORDINARY);
    }

    public function isConsignmentBilling(): bool {
        return $this->source_type === self::SOURCE_CONSIGNMENT_BILLING;
    }

    public function isOrdinary(): bool {
        return empty($this->source_type) || $this->source_type === self::SOURCE_ORDINARY;
    }

    public function purchasePayments() {
        return $this->hasMany(PurchasePayment::class, 'purchase_id', 'id');
    }

    public function corrections() {
        return $this->hasMany(PurchaseCorrection::class, 'purchase_id', 'id');
    }

    public function receivingCompletions() {
        return $this->hasMany(PurchaseReceivingCompletion::class, 'purchase_id', 'id');
    }

    /**
     * UOM normalization batches that include lines from this purchase's details.
     */
    public function uomNormalizationLines()
    {
        return $this->hasManyThrough(
            UomNormalizationLine::class,
            PurchaseDetail::class,
            'purchase_id',         // FK on purchase_details
            'purchase_detail_id',  // FK on uom_normalization_lines
            'id',                  // Local key on purchases
            'id'                   // Local key on purchase_details
        );
    }

    public function receivedNotes() {
        return $this->hasMany(ReceivedNote::class, 'po_id', 'id');
    }

    public function reportingDateAudits() {
        return $this->morphMany(ReportingDateAudit::class, 'auditable');
    }

    public function dueDateAudits() {
        return $this->morphMany(DueDateAudit::class, 'auditable');
    }

    public function getEffectiveDateAttribute(): ?\Carbon\CarbonInterface {
        return $this->reporting_date ?? $this->date;
    }

    public static function boot(): void
    {
        parent::boot();

        static::saving(function ($model) {
            // Backward compatibility: some callers still pass legacy supplier_name.
            unset($model->attributes['supplier_name']);
        });

        static::creating(function ($model) {
            if (empty($model->source_type)) {
                $model->source_type = self::SOURCE_ORDINARY;
            }

            // Only auto-generate if not already set (allow service/explicit allocation to take precedence)
            if ($model->reference) {
                return;
            }

            // If created raw without an existing reference, require an active database transaction to guarantee atomic persistence
            if (DB::transactionLevel() === 0) {
                throw new \LogicException('Creating a Purchase without an explicit reference requires an active database transaction to ensure sequence atomicity.');
            }

            // Fallback allocation via authoritative sequence allocator
            $allocator = app(\App\Services\Sequence\DocumentSequenceAllocator::class);
            $purchaseDate = $model->date ? Carbon::parse($model->date) : now();
            $namespace = $allocator->buildNamespace(\App\Services\Sequence\DocumentType::PURCHASE, (int) $model->setting_id, $purchaseDate);

            $allocation = $allocator->allocate($namespace);
            $model->reference = $allocation->reference;
        });

        static::updating(function ($model) {
            if ($model->getOriginal('source_type') === self::SOURCE_CONSIGNMENT_BILLING) {
                $dirty = array_keys($model->getDirty());
                $allowed = ['paid_amount', 'due_amount', 'payment_status', 'updated_at'];
                $forbidden = array_diff($dirty, $allowed);

                if (!empty($forbidden)) {
                    $forbiddenStr = implode(', ', $forbidden);
                    throw new \DomainException("Commercial header fields [{$forbiddenStr}] on consignment-billing Purchase #{$model->id} ({$model->reference}) are immutable. Only settlement balances may be updated.");
                }
            }
        });
    }

    public function scopeCompleted($query) {
        return $query->where('status', 'Completed');
    }

    public function scopeGlobalPaymentEligible($query)
    {
        return $query->whereIn('status', [
            self::STATUS_RECEIVED_PARTIALLY,
            self::STATUS_RECEIVED,
            self::STATUS_RETURNED_PARTIALLY,
        ]);
    }

    public function scopeWhereLiveDueAmountGreaterThan($query, $amount = 0)
    {
        return $query->whereRaw('total_amount - COALESCE((SELECT SUM(amount) FROM purchase_payments WHERE purchase_payments.purchase_id = purchases.id AND purchase_payments.status = ?), 0) > ?', [\Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE, $amount]);
    }

    public function scopeWhereLiveDueAmountLessThanOrEqual($query, $amount = 0)
    {
        return $query->whereRaw('total_amount - COALESCE((SELECT SUM(amount) FROM purchase_payments WHERE purchase_payments.purchase_id = purchases.id AND purchase_payments.status = ?), 0) <= ?', [\Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE, $amount]);
    }

    public function getShippingAmountAttribute($value) {
        return $value;
    }

    public function getPaidAmountAttribute($value) {
        return $value;
    }

    public function getTotalAmountAttribute($value) {
        return $value;
    }

    public function getDueAmountAttribute($value) {
        return $value;
    }

    public function getTaxAmountAttribute($value) {
        return $value;
    }

    public function getDiscountAmountAttribute($value) {
        return $value;
    }
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    public function tenantSetting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    /**
     * Get effective paid amount from active payments only.
     * Per FR-006: SUM(purchase_payments.amount WHERE status = ACTIVE)
     */
    public function getEffectivePaidAmount(): float
    {
        if (array_key_exists('active_payments_sum', $this->attributes)) {
            return (float) ($this->attributes['active_payments_sum'] ?: 0);
        }

        if ($this->relationLoaded('purchasePayments')) {
            return (float) $this->purchasePayments
                ->where('status', PurchasePayment::STATUS_ACTIVE)
                ->sum('amount');
        }

        return (float) $this->purchasePayments()
            ->where('status', PurchasePayment::STATUS_ACTIVE)
            ->sum('amount');
    }

    public function getLiveDueAmountAttribute(): float
    {
        return max(0, $this->total_amount - $this->getEffectivePaidAmount());
    }

    /**
     * Reconcile purchase header from canonical settlement totals.
     * Updates paid_amount, due_amount, and payment_status from active payments.
     */
    public function reconcileFromActivePayments(): void
    {
        $paidAmount = $this->getEffectivePaidAmount();
        $dueAmount = max(0, round($this->total_amount - $paidAmount, 2));

        $status = $dueAmount <= 0.01 ? self::PAYMENT_STATUS_PAID : ($paidAmount > 0.01 ? self::PAYMENT_STATUS_PARTIAL : self::PAYMENT_STATUS_UNPAID);

        $this->update([
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_status' => $status,
        ]);
    }

    /**
     * Filter purchases by payment status, matching all stored casing variants safely.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|array<int, string> $statusOrStatuses
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWherePaymentStatus($query, $statusOrStatuses)
    {
        if (is_array($statusOrStatuses)) {
            return $query->whereIn('payment_status', \App\Constants\PaymentStatus::variantsFor($statusOrStatuses));
        }

        return $query->whereIn('payment_status', \App\Constants\PaymentStatus::variants((string) $statusOrStatuses));
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
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->withArchived()
            ->first();
    }
}
