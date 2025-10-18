<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettingSaleLocation extends BaseModel
{
    protected $guarded = [];

    protected $table = 'setting_sale_locations';

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
