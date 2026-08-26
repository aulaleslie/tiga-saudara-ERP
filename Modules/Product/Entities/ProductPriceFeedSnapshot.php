<?php

namespace Modules\Product\Entities;

use Modules\Setting\Entities\Setting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceFeedSnapshot extends Model
{
    use HasFactory;

    protected $table = 'product_price_feed_snapshots';

    protected $fillable = [
        'event_id',
        'setting_id',
        'setting_name',
        'before_snapshot',
        'after_snapshot',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo($this->getEventModelClass(), 'event_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    protected function getEventModelClass(): string
    {
        return ProductPriceFeedEvent::class;
    }
}
