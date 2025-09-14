<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends BaseModel
{
    protected $fillable = [
        'transaction_date',
        'description',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(JournalItem::class);
    }
}
