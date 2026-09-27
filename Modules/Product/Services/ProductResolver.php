<?php

namespace Modules\Product\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Product\Entities\Product;
use Modules\Product\Exceptions\AmbiguousProductResolutionException;

class ProductResolver
{
    public function __construct(
        protected ProductCanonicalizer $canonicalizer
    ) {
    }

    protected function findCandidates(string $canonicalKey)
    {
        $existing = Product::active()
            ->where('canonical_name', $canonicalKey)
            ->orderBy('id', 'asc')
            ->get();
            
        // Fetch legacy products that haven't been backfilled (e.g. collision groups)
        // We fetch the remaining NULLs and apply the exact same canonicalizer in PHP
        $legacy = Product::active()
            ->whereNull('canonical_name')
            ->orderBy('id', 'asc')
            ->get()
            ->filter(function ($product) use ($canonicalKey) {
                try {
                    $identity = $this->canonicalizer->canonicalize((string)$product->product_name);
                    return $identity['canonical_key'] === $canonicalKey;
                } catch (\InvalidArgumentException $e) {
                    return false;
                }
            })
            ->values();

        return $existing->concat($legacy)->unique('id');
    }

    /**
     * Resolves an existing product by canonical identity without mutation.
     * 
     * @param string $rawName
     * @return Product|null Returns the product if exactly one canonical match is found, null otherwise.
     * @throws AmbiguousProductResolutionException If multiple legacy products match.
     */
    public function resolveExisting(string $rawName): ?Product
    {
        $identity = $this->canonicalizer->canonicalize($rawName);
        $canonicalKey = $identity['canonical_key'];

        $products = $this->findCandidates($canonicalKey);

        if ($products->count() === 1) {
            return $products->first();
        }

        if ($products->count() > 1) {
            throw new AmbiguousProductResolutionException("Ambiguous legacy products found for canonical identity '{$canonicalKey}'. Cannot safely resolve.");
        }

        return null;
    }

    /**
     * Atomically resolves an existing product or creates a new one using the canonical identity.
     * 
     * @param string $rawName
     * @param Closure(string $displayName, string $canonicalKey): array $attributesProvider
     * @return Product
     */
    public function resolveOrCreate(string $rawName, Closure $attributesProvider): Product
    {
        $identity = $this->canonicalizer->canonicalize($rawName);
        $canonicalKey = $identity['canonical_key'];
        $displayName = $identity['display_name'];

        $existing = $this->findCandidates($canonicalKey);

        if ($existing->count() === 1) {
            return $existing->first();
        }

        if ($existing->count() > 1) {
            throw new AmbiguousProductResolutionException("Ambiguous legacy products found for canonical identity '{$canonicalKey}'. Cannot safely resolve or create.");
        }

        $attributes = $attributesProvider($displayName, $canonicalKey);
        $attributes['product_name'] = $displayName;
        $attributes['canonical_name'] = $canonicalKey;

        try {
            return Product::create($attributes);
        } catch (QueryException $e) {
            // Check for unique constraint violation on canonical_name
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate entry')) {
                if (str_contains($e->getMessage(), 'canonical_name')) {
                    // A concurrent job created it first. Reload the winner.
                    $winner = Product::where('canonical_name', $canonicalKey)->first();
                    if ($winner) {
                        Log::info('[ProductResolver] Concurrent creation detected, reloaded existing product.', [
                            'canonical_key' => $canonicalKey
                        ]);
                        return $winner;
                    }
                }
            }
            
            // If it's a different constraint (like product_code) or we couldn't find the winner, rethrow
            throw $e;
        }
    }
}
