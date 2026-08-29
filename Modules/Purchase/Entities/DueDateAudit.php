<?php

namespace Modules\Purchase\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DueDateAudit extends BaseModel
{
    protected $table = 'due_date_audits';

    /**
     * BaseModel uppercases string attributes by default, which would mangle the
     * morph class stored in auditable_type (breaking the dueDateAudits
     * relation) and destroy the casing of user-entered reasons.
     */
    protected bool $uppercaseAllText = false;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'setting_id',
        'user_id',
        'reason',
        'prior_due_date',
        'resulting_due_date',
    ];

    protected $casts = [
        'prior_due_date' => 'datetime',
        'resulting_due_date' => 'datetime',
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
