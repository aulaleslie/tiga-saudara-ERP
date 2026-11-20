<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Services\ProductTaxonomyCanonicalizer;
use Modules\Setting\Entities\Setting;

class Brand extends BaseModel
{
    use SoftDeletes;

    protected array $uppercaseExcept = ['normalized_name'];

    protected $fillable = [
        'name',
        'normalized_name',
        'description',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (Brand $brand): void {
            if ($brand->name !== null && trim((string) $brand->name) !== '') {
                $canonical = app(ProductTaxonomyCanonicalizer::class)->canonicalize((string) $brand->name);
                $brand->name = $canonical['display_name'];
                $brand->normalized_name = $canonical['normalized_name'];
            }

            // Compatibility during preparation phase before product:finalize-taxonomy drops setting_id
            if (
                $brand->getAttribute('setting_id') === null
                && Schema::connection($brand->getConnectionName())->hasColumn($brand->getTable(), 'setting_id')
            ) {
                $brand->setAttribute('setting_id', session('setting_id') ?? Setting::query()->min('id'));
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }
}
