<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUomCorrectionRemovedDocument extends BaseModel
{
    public $timestamps = false;

    protected $table = 'product_uom_correction_removed_documents';

    protected $fillable = [
        'audit_id',
        'document_type',
        'document_id',
        'reference',
        'status',
        'payment_amount',
        'owner_or_customer',
        'document_created_at',
        'created_at',
    ];

    protected $casts = [
        'payment_amount' => 'decimal:2',
        'document_created_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(ProductUomCorrectionAudit::class, 'audit_id');
    }
}
