<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class Transaction extends BaseModel
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'setting_id',
        'type',
        'quantity',
        'current_quantity',
        'broken_quantity',
        'location_id',
        'user_id',
        'reason',
        'previous_quantity',
        'previous_quantity_at_location',
        'after_quantity',
        'after_quantity_at_location',
        'quantity_tax',
        'quantity_non_tax',
        'broken_quantity_tax',
        'broken_quantity_non_tax'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'current_quantity' => 'integer',
        'broken_quantity' => 'integer',
    ];

    /**
     * Get the product associated with the transaction.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the setting associated with the transaction.
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    /**
     * Get the location associated with the transaction.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the user who created the transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include transactions of a given type.
     *
     * @param Builder $query
     * @param string $type
     * @return Builder
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to only include transactions for a specific product.
     *
     * @param Builder $query
     * @param int $productId
     * @return Builder
     */
    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    protected array $dates = ['created_at', 'updated_at'];

    // Accessor for formatted created_at
    public function getFormattedCreatedAtAttribute()
    {
        return $this->created_at->format('d-m-Y H:i:s');
    }
}
