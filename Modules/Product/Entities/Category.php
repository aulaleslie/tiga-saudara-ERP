<?php

namespace Modules\Product\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Services\ProductTaxonomyCanonicalizer;
use Modules\Setting\Entities\Setting;
use OwenIt\Auditing\Contracts\Auditable;

class Category extends BaseModel implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected array $uppercaseExcept = ['normalized_name'];

    protected $fillable = [
        'category_code',
        'category_name',
        'normalized_name',
        'parent_id',
        'parent_namespace',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            $canonicalizer = app(ProductTaxonomyCanonicalizer::class);
            if ($category->category_name !== null && trim((string) $category->category_name) !== '') {
                $canonical = $canonicalizer->canonicalize((string) $category->category_name);
                $category->category_name = $canonical['display_name'];
                $category->normalized_name = $canonical['normalized_name'];
            }
            $category->parent_namespace = $canonicalizer->parentNamespace(
                $category->parent_id !== null ? (int) $category->parent_id : null
            );

            // Compatibility during preparation phase before product:finalize-taxonomy drops setting_id
            if (
                $category->getAttribute('setting_id') === null
                && Schema::connection($category->getConnectionName())->hasColumn($category->getTable(), 'setting_id')
            ) {
                $category->setAttribute('setting_id', session('setting_id') ?? Setting::query()->min('id'));
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id', 'id');
    }

    /**
     * Get the parent category.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Get the child categories.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Get the user who created the category.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
