<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\People\Entities\Customer;
use Modules\Setting\Entities\Setting;

class PosTransaction extends BaseModel
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_LOADED = 'LOADED';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected bool $uppercaseAllText = false;

    protected $table = 'pos_transactions';

    protected $fillable = [
        'setting_id',
        'code',
        'status',
        'created_by',
        'owner_user_id',
        'last_saved_by',
        'customer_id',
        'source_pos_session_id',
        'completed_checkout_id',
        'snapshot_totals',
        'metadata',
    ];

    protected $casts = [
        'snapshot_totals' => 'array',
        'metadata' => 'array',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function lastSavedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_saved_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function sourceSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'source_pos_session_id');
    }

    public function completedCheckout(): BelongsTo
    {
        return $this->belongsTo(PosCheckout::class, 'completed_checkout_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosTransactionLine::class, 'pos_transaction_id');
    }
}
