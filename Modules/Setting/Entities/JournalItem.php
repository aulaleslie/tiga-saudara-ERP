<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalItem extends BaseModel
{
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
