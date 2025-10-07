<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashierCashMovement extends BaseModel
{
    use HasFactory;

    protected bool $uppercaseAllText = false;

    protected $fillable = [
        'user_id',
        'movement_type',
        'cash_total',
        'expected_total',
        'variance',
        'denominations',
        'documents',
        'metadata',
        'notes',
        'recorded_at',
    ];

    protected $casts = [
        'cash_total' => 'decimal:2',
        'expected_total' => 'decimal:2',
        'variance' => 'decimal:2',
        'denominations' => 'array',
        'documents' => 'array',
        'metadata' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
