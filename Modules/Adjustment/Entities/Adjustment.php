<?php

namespace Modules\Adjustment\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Setting\Entities\Location;

class Adjustment extends Model
{
    use HasFactory;

    protected $guarded = [];

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
            $year = now()->year;
            $month = now()->month;

            // Fetch the latest reference for the current year and month
            $latestReference = Adjustment::whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->latest('id')
                ->value('reference');

            // Extract the number from the latest reference
            $nextNumber = 1; // Default to 1 if no reference exists
            if ($latestReference) {
                $parts = explode('-', $latestReference);
                $lastNumber = (int) end($parts);
                $nextNumber = $lastNumber + 1;
            }

            // Generate the new reference ID
            $model->reference = make_reference_id('ADJ', $year, $month, $nextNumber);
        });
    }

}
