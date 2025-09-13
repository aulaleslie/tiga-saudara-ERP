<?php

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends BaseModel
{
    protected $fillable = [
        'name',
        'account_number',
        'category',
        'parent_account_id',
        'tax_id',
        'description',
        'setting_id'
    ];

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_account_id');
    }

    public function childAccounts(): ChartOfAccount|Builder|HasMany
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_account_id');
    }


}
