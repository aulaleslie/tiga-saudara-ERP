<?php

namespace Modules\Expense\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Expense extends BaseModel implements HasMedia
{
    use InteractsWithMedia;

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
                $year = now()->year;
                $month = str_pad(now()->month, 2, '0', STR_PAD_LEFT);
                $settingId = $model->setting_id;

                $setting = \Modules\Setting\Entities\Setting::find($settingId);
                $documentPrefix = $setting ? $setting->document_prefix : 'INV';
                $prefix = $documentPrefix ? "{$documentPrefix}-EXP" : 'EXP';

                $latestReference = Expense::where('setting_id', $settingId)
                    ->whereYear('created_at', $year)
                    ->whereMonth('created_at', now()->month)
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
