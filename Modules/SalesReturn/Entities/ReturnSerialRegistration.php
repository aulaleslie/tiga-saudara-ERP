<?php

namespace Modules\SalesReturn\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Entities\ProductSerialNumber;

class ReturnSerialRegistration extends BaseModel
{
    public const WORKFLOW_STANDARD = 'standard';
    public const WORKFLOW_POS = 'pos';

    protected bool $uppercaseAllText = false;

    protected $table = 'return_serial_registrations';

    protected $fillable = [
        'claim_uuid', 'workflow', 'sale_return_id', 'sale_return_detail_id', 'pos_return_id', 'pos_return_line_id',
        'product_id', 'source_key', 'component_product_id', 'source_classification', 'source_basis',
        'source_fingerprint', 'unit_attribution_key', 'proposed_serial', 'registered_serial_id',
        'registered_by', 'registered_at',
    ];

    protected $casts = ['registered_at' => 'datetime'];

    protected static function booted(): void
    {
        // Enforce valid workflow/reference combinations (a claim belongs to exactly one concrete line).
        static::saving(function (self $claim) {
            $standard = $claim->workflow === self::WORKFLOW_STANDARD
                && $claim->sale_return_id && $claim->sale_return_detail_id
                && ! $claim->pos_return_id && ! $claim->pos_return_line_id;
            $pos = $claim->workflow === self::WORKFLOW_POS
                && $claim->pos_return_id && $claim->pos_return_line_id
                && ! $claim->sale_return_detail_id;
            if (! $standard && ! $pos) {
                throw new \InvalidArgumentException('Kombinasi workflow dan referensi registrasi nomor seri tidak valid.');
            }
        });
    }

    public function setProposedSerialAttribute($value): void
    {
        $this->attributes['proposed_serial'] = is_null($value) ? null : ProductSerialNumber::normalize((string) $value);
    }

    public function isRegistered(): bool
    {
        return $this->registered_serial_id !== null;
    }

    public function registeredSerial(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class, 'registered_serial_id');
    }

    public function saleReturnDetail(): BelongsTo
    {
        return $this->belongsTo(SaleReturnDetail::class, 'sale_return_detail_id');
    }
}
