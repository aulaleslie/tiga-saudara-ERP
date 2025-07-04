<?php

namespace Modules\Expense\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Expense extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $guarded = [];

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
    public function details(): HasMany
    {
        return $this->hasMany(ExpenseDetail::class);
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
            $year = now()->year;
            $month = now()->month;

            $latestReference = Expense::whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->latest('id')
                ->value('reference');

            $nextNumber = 1;
            if ($latestReference) {
                $parts = explode('-', $latestReference);
                $lastNumber = (int) end($parts);
                $nextNumber = $lastNumber + 1;
            }

            $model->reference = make_reference_id('EXP', $year, $month, $nextNumber);
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
