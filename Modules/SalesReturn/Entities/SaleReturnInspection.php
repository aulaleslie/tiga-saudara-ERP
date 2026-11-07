<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturnInspection extends BaseModel
{
    protected $table = 'sale_return_inspections';
    protected $guarded = [];

    protected $casts = ['inspected_at' => 'datetime', 'released_quantity' => 'decimal:3'];

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SaleReturnCustodyMovement::class, 'inspection_id');
    }
}
