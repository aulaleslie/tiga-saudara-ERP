<?php

namespace Modules\Pos\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosTransactionLineSerial extends BaseModel
{
    public $timestamps = false;

    protected bool $uppercaseAllText = false;

    protected $table = 'pos_transaction_line_serials';

    protected $fillable = [
        'pos_transaction_line_id',
        'serial_number',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(PosTransactionLine::class, 'pos_transaction_line_id');
    }
}
