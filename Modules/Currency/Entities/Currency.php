<?php

namespace Modules\Currency\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Currency extends BaseModel
{
    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\CurrencyFactory::new();
    }
    
    protected $guarded = [];

}
