<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\PaymentMethod;

class PosKasBonCollectionMethod extends BaseModel
{
    protected bool $uppercaseAllText = false;

    protected $table = 'pos_kas_bon_collection_methods';

    protected $fillable = [
        'pos_kas_bon_collection_id',
        'payment_method_id',
        'payment_method_name',
        'amount',
        'tender_amount',
        'change_amount',
        'sequence_order',
        'reference',
        'evidence_path',
        'evidence_metadata',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tender_amount' => 'decimal:2',
        'change_amount' => 'decimal:2',
        'sequence_order' => 'integer',
        'evidence_metadata' => 'array',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(PosKasBonCollection::class, 'pos_kas_bon_collection_id');
    }

    public function getIsCashAttribute(): bool
    {
        return (bool) ($this->paymentMethod?->is_cash ?? false);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PosKasBonCollectionAllocation::class, 'pos_kas_bon_collection_method_id');
    }
}
