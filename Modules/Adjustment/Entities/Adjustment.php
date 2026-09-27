<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Adjustment\Services\AdjustmentReferenceService;
use Modules\Setting\Entities\Location;

class Adjustment extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'count_draft' => 'array',
        'approval_result' => 'array',
        'status' => AdjustmentStatus::class,
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function isVersionedCountDraft(): bool
    {
        return !empty($this->count_draft) && isset($this->count_draft['schema_version']);
    }

    /**
     * Check if this document uses schema version 2 (multi-location).
     */
    public function isSchemaVersion2(): bool
    {
        return !empty($this->count_draft)
            && ((int) ($this->count_draft['schema_version'] ?? 0)) === 2;
    }

    public function isNormalVersioned(): bool
    {
        return strtolower(trim((string) $this->type)) === 'normal' && $this->isVersionedCountDraft();
    }

    /**
     * The authoritative ordered set of selected locations for a
     * multi-location (schema version 2) Stock Opname document.
     *
     * Historical single-location documents will have zero rows here;
     * callers should use SelectedLocationPoolResolver to transparently
     * handle both schemas.
     */
    public function selectedLocations(): HasMany
    {
        return $this->hasMany(AdjustmentLocation::class, 'adjustment_id', 'id')
            ->orderBy('position')
            ->orderBy('location_id');
    }

    public function adjustmentLocations(): HasMany
    {
        return $this->selectedLocations();
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function getDateAttribute($value): string
    {
        return Carbon::parse($value)->format('d M, Y');
    }

    public function adjustedProducts(): HasMany
    {
        return $this->hasMany(AdjustedProduct::class, 'adjustment_id', 'id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $prefix = AdjustmentReferenceService::prefixForType($model->type);

            // Generate the new reference ID if not provided, or if the
            // create form submitted only the placeholder value for this
            // type's namespace ("ADJ" for normal, "BRK" for breakage).
            if (AdjustmentReferenceService::needsGeneration($model->reference, $prefix)) {
                $model->reference = AdjustmentReferenceService::allocate($prefix);
            }
        });
    }

}
