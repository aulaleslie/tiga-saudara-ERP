<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReportingDateAudit extends BaseModel
{
    protected $table = 'reporting_date_audits';

    /**
     * BaseModel uppercases string attributes by default, which would mangle the
     * morph class stored in auditable_type (breaking the reportingDateAudits
     * relation) and destroy the casing of user-entered reasons.
     */
    protected bool $uppercaseAllText = false;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'setting_id',
        'user_id',
        'reason',
        'original_date',
        'prior_override',
        'resulting_override',
    ];

    protected $casts = [
        'original_date' => 'datetime',
        'prior_override' => 'datetime',
        'resulting_override' => 'datetime',
    ];

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
