<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of a version 3 goods manifest frozen at submission.
 *
 * lines: [{product_id, quantity, serials: [{id, serial_number}]}]
 */
class TransferRequestRevision extends BaseModel
{
    protected $fillable = [
        'transfer_id',
        'revision_number',
        'stock_condition',
        'lines',
        'submitted_by',
        'submitted_in_setting_id',
        'submitted_at',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'lines'           => 'array',
        'submitted_at'    => 'datetime',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Tracking mode frozen at submission: a serialized product was submitted
     * with exactly one serial per unit, a non-serialized one with none.
     */
    public function isSerializedProduct(int $productId): bool
    {
        return ($this->linesByProduct()[$productId]['serials'] ?? []) !== [];
    }

    /**
     * @return array<int, array{product_id: int, quantity: int, serials: array<int, array{id: int, serial_number: string}>}>
     */
    public function linesByProduct(): array
    {
        $lines = [];

        foreach ($this->lines ?? [] as $line) {
            $lines[(int) $line['product_id']] = [
                'product_id' => (int) $line['product_id'],
                'quantity'   => (int) $line['quantity'],
                'serials'    => array_values($line['serials'] ?? []),
            ];
        }

        return $lines;
    }
}
