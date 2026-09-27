<?php

namespace Modules\Product\Entities;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductPriceFeedEvent extends Model
{
    use HasFactory;

    public const TYPE_PRODUCT_CREATED = 'product_created';
    public const TYPE_PRODUCT_PRICE_UPDATED = 'product_price_updated';
    public const TYPE_BUNDLE_CREATED = 'bundle_created';
    public const TYPE_BUNDLE_PRICE_UPDATED = 'bundle_price_updated';

    public const SUBJECT_PRODUCT = 'product';
    public const SUBJECT_BUNDLE = 'bundle';

    public const SOURCE_MANUAL = 'Manual';
    public const SOURCE_QUICK_ADD = 'QuickAdd';
    public const SOURCE_PURCHASE_SYNC = 'PurchaseSync';
    public const SOURCE_IMPORT = 'Import';
    public const SOURCE_SYSTEM = 'System';

    protected $table = 'product_price_feed_events';

    protected $fillable = [
        'operation_uuid',
        'event_type',
        'subject_type',
        'subject_id',
        'subject_name',
        'subject_code',
        'user_id',
        'actor_name',
        'source',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ProductPriceFeedSnapshot::class, 'event_id');
    }
}
