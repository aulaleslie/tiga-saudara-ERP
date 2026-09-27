<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosTerminalPolicy extends BaseModel
{
    protected $table = 'pos_terminal_policies';

    protected $fillable = [
        'terminal_id',
        'require_session_open',
        'require_opening_float',
        'allow_total_only_float_input',
        'close_variance_approval_threshold',
        'cash_threshold',
        'auto_open_drawer_on_session_open',
        'auto_open_drawer_on_cash_sale',
        'auto_open_drawer_on_pickup',
        'auto_open_drawer_on_close',
        'require_pickup_supervisor_approval',
        'metadata',
    ];

    protected $casts = [
        'require_session_open' => 'boolean',
        'require_opening_float' => 'boolean',
        'allow_total_only_float_input' => 'boolean',
        'close_variance_approval_threshold' => 'decimal:2',
        'cash_threshold' => 'decimal:2',
        'auto_open_drawer_on_session_open' => 'boolean',
        'auto_open_drawer_on_cash_sale' => 'boolean',
        'auto_open_drawer_on_pickup' => 'boolean',
        'auto_open_drawer_on_close' => 'boolean',
        'require_pickup_supervisor_approval' => 'boolean',
        'metadata' => 'array',
    ];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }
}
