<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Currency\Entities\Currency;

class Setting extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'pos_idle_threshold_minutes' => 'integer',
        'pos_default_cash_threshold' => 'decimal:2',
    ];

    protected $with = ['currency'];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id', 'id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_setting', 'setting_id', 'user_id')
            ->withPivot('role_id');
    }

    public function saleLocations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'setting_sale_locations')
            ->withTimestamps()
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function saleLocationAssignments(): HasMany
    {
        return $this->hasMany(SettingSaleLocation::class);
    }

}
