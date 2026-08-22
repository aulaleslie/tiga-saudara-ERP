<?php

namespace App\Services\Sequence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Setting\Entities\Setting;

class DocumentSequence extends Model
{
    protected $table = 'document_sequences';

    protected $fillable = [
        'document_type',
        'setting_id',
        'prefix',
        'period_year',
        'period_month',
        'last_number',
    ];

    protected $casts = [
        'setting_id' => 'integer',
        'period_year' => 'integer',
        'period_month' => 'integer',
        'last_number' => 'integer',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }
}
