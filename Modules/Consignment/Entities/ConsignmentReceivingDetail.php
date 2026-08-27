<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Tax;

class ConsignmentReceivingDetail extends BaseModel
{
    use HasFactory;

    protected $table = 'consignment_receiving_details';

    protected $fillable = [
        'consignment_receiving_id',
        'consignment_receival_line_id',
        'product_id',
        'quantity_received',
        'unit_cost',
        'unit_dpp',
        'tax_id',
        'tax_rate',
        'tax_amount',
        'pending_serial_numbers',
        'stock_before',
        'stock_after',
        'stock_tax_before',
        'stock_tax_after',
        'stock_non_tax_before',
        'stock_non_tax_after',
        'setting_quantity_before',
        'setting_quantity_after',
        'setting_avg_cost_before',
        'setting_avg_cost_after',
        'transaction_id',
        'reversal_transaction_id',
        'notes',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'unit_dpp' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'stock_before' => 'decimal:3',
        'stock_after' => 'decimal:3',
        'stock_tax_before' => 'decimal:3',
        'stock_tax_after' => 'decimal:3',
        'stock_non_tax_before' => 'decimal:3',
        'stock_non_tax_after' => 'decimal:3',
        'setting_quantity_before' => 'decimal:3',
        'setting_quantity_after' => 'decimal:3',
        'setting_avg_cost_before' => 'decimal:2',
        'setting_avg_cost_after' => 'decimal:2',
        'pending_serial_numbers' => 'array',
    ];

    protected static function newFactory()
    {
        return \Database\Factories\ConsignmentReceivingDetailFactory::new();
    }

    public function consignmentReceiving(): BelongsTo
    {
        return $this->belongsTo(ConsignmentReceiving::class, 'consignment_receiving_id');
    }

    public function receivalLine(): BelongsTo
    {
        return $this->belongsTo(ConsignmentReceivalLine::class, 'consignment_receival_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function reversalTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'reversal_transaction_id');
    }

    public function serialNumbers(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductSerialNumber::class,
            'consignment_receiving_detail_serial_numbers',
            'consignment_receiving_detail_id',
            'product_serial_number_id'
        )->withPivot(['id', 'source_history_id', 'reversal_history_id', 'linked_at'])->withTimestamps();
    }

    public function receiptAllocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ConsignmentReceiptAllocation::class, 'consignment_receiving_detail_id');
    }
}
