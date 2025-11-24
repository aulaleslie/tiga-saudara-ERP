<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Location;

class PosSession extends BaseModel
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CLOSED = 'closed';

    protected bool $uppercaseAllText = false;

    protected $fillable = [
        'user_id',
        'location_id',
        'device_name',
        'cash_float',
        'expected_cash',
        'actual_cash',
        'discrepancy',
        'status',
        'started_at',
        'paused_at',
        'resumed_at',
        'closed_at',
    ];

    protected $casts = [
        'cash_float' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'actual_cash' => 'decimal:2',
        'discrepancy' => 'decimal:2',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'resumed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'pos_session_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class, 'pos_session_id');
    }
}
