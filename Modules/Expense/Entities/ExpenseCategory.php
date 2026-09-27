<?php

namespace Modules\Expense\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends BaseModel
{
    protected $guarded = [];

    public function expenses(): Builder|HasMany|ExpenseCategory
    {
        return $this->hasMany(Expense::class, 'category_id', 'id');
    }
}
