<?php

namespace Modules\Product\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use App\Models\User;

class ProductBarcodeAssignment extends Model
{
    use HasFactory;

    public const ACTION_INITIALIZE = 'initialize';
    public const ACTION_REPLACE = 'replace';

    protected $fillable = [
        'product_id',
        'product_name',
        'product_code',
        'old_barcode',
        'new_barcode',
        'action',
        'actor_id',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
