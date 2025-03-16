<?php

namespace Modules\Setting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_id',
        'chart_of_account_id',
        'amount',
        'type',
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        // Adjust the namespace if necessary
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }
}
