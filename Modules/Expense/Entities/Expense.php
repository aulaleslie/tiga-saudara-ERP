<?php

namespace Modules\Expense\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\People\Entities\Supplier;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Tags\HasTags;
use Spatie\Tags\Tag;

class Expense extends BaseModel implements HasMedia
{
    use InteractsWithMedia, HasTags;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'archived_at' => 'datetime',
        'is_tax_included' => 'boolean',
    ];

    /**
     * Category relationship
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id', 'id');
    }

    /**
     * Supplier relationship
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }

    /**
     * Tags relationship (explicit morphToMany for report filter compatibility)
     */
    public function tags(): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Detail rows
     */
    public function detailRows(): HasMany
    {
        return $this->hasMany(ExpenseDetail::class);
    }

    /**
     * User who archived the expense
     */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'archived_by');
    }

    /**
     * Scope for active approved expenses (not archived)
     */
    public function scopeActiveApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED)
                     ->whereNull('archived_at');
    }

    /**
     * Media collection for uploaded files
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments');
    }

    /**
     * Auto-generate reference
     */
    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->reference)) {
                $rawDate = $model->getAttributes()['date'] ?? null;
                $date = $rawDate ? Carbon::parse($rawDate) : now();

                $year = $date->year;
                $month = str_pad($date->month, 2, '0', STR_PAD_LEFT);
                $settingId = $model->setting_id;

                $setting = \Modules\Setting\Entities\Setting::find($settingId);
                $documentPrefix = $setting ? $setting->document_prefix : 'INV';
                $prefix = $documentPrefix ? "{$documentPrefix}-EXP" : 'EXP';

                $latestReference = Expense::where('setting_id', $settingId)
                    ->whereYear('date', $year)
                    ->whereMonth('date', $date->month)
                    ->latest('id')
                    ->value('reference');

                $nextNumber = 1;
                if ($latestReference) {
                    $parts = explode('-', $latestReference);
                    $lastNumber = (int) end($parts);
                    $nextNumber = $lastNumber + 1;
                }

                $model->reference = sprintf('%s-%s-%s-%05d', $prefix, $year, $month, $nextNumber);
            }
        });
    }

    /**
     * Mutator & accessor for amount (stored in cents)
     */
    public function setAmountAttribute($value): void
    {
        $this->attributes['amount'] = $value * 100;
    }

    public function getAmountAttribute($value): float
    {
        return $value / 100;
    }

    /**
     * Accessor for formatted date
     */
    public function getDateAttribute($value): string
    {
        return Carbon::parse($value)->format('d M, Y');
    }
}
