<?php

namespace Modules\People\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\People\Database\factories\SupplierFactory;
use Modules\Setting\Entities\Setting;

class Supplier extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }
}
