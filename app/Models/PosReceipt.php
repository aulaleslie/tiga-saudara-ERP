<?php

namespace App\Models;

use App\Models\BaseModel;
use App\Models\PosSession;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class PosReceipt extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
        'change_due' => 'decimal:2',
        'payment_breakdown' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (PosReceipt $receipt) {
            if (! $receipt->receipt_number) {
                $year = now()->year;
                $month = now()->month;

                $latest = static::query()
                    ->whereYear('created_at', $year)
                    ->whereMonth('created_at', $month)
                    ->latest('id')
                    ->value('receipt_number');

                $nextNumber = 1;
                if ($latest) {
                    $parts = explode('-', $latest);
                    $lastNumber = (int) end($parts);
                    $nextNumber = $lastNumber + 1;
                }

                $receipt->receipt_number = make_reference_id('PR', $year, $month, $nextNumber);
            }
        });
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'pos_receipt_id');
    }

    public function salePayments(): HasMany
    {
        return $this->hasMany(SalePayment::class, 'pos_receipt_id');
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }
}
