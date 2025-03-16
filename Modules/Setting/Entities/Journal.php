<?php

namespace Modules\Setting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_date',
        'description',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(JournalItem::class);
    }
}
