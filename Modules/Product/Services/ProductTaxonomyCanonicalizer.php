<?php

namespace Modules\Product\Services;

use InvalidArgumentException;

class ProductTaxonomyCanonicalizer
{
    public const ROOT_PARENT_NAMESPACE = 0;

    /**
     * Normalize and format a taxonomy name (brand or category).
     *
     * Replaces Unicode separator/control whitespace with ordinary spaces,
     * collapses repeated whitespace, trims, and preserves spelling and punctuation
     * (including leading '*' or trailing 'TP' markers).
     *
     * @return array{display_name: string, normalized_name: string}
     */
    public function canonicalize(?string $rawName): array
    {
        $collapsed = $this->collapseWhitespace($rawName);

        if ($collapsed === '') {
            throw new InvalidArgumentException('Taxonomy name cannot be blank after normalization.');
        }

        $displayName = mb_strtoupper($collapsed, 'UTF-8');

        return [
            'display_name' => $displayName,
            'normalized_name' => mb_strtolower($displayName, 'UTF-8'),
        ];
    }

    /**
     * Return the case-folded normalized name or throw if blank.
     */
    public function normalizeName(?string $rawName): string
    {
        return $this->canonicalize($rawName)['normalized_name'];
    }

    /**
     * Return the normalized name or null if the input is blank.
     */
    public function tryNormalizeName(?string $rawName): ?string
    {
        $collapsed = $this->collapseWhitespace($rawName);

        if ($collapsed === '') {
            return null;
        }

        return mb_strtolower(mb_strtoupper($collapsed, 'UTF-8'), 'UTF-8');
    }

    /**
     * Return the uppercase display name or throw if blank.
     */
    public function displayName(?string $rawName): string
    {
        return $this->canonicalize($rawName)['display_name'];
    }

    /**
     * Map a nullable category parent ID to a non-null namespace integer.
     */
    public function parentNamespace(?int $parentId): int
    {
        if ($parentId === null || $parentId <= 0) {
            return self::ROOT_PARENT_NAMESPACE;
        }

        return $parentId;
    }

    /**
     * Compute the canonical identity key for a brand.
     */
    public function brandKey(?string $rawName): string
    {
        return $this->normalizeName($rawName);
    }

    /**
     * Compute the canonical identity key for a category within its parent namespace.
     */
    public function categoryKey(?string $rawName, ?int $parentId = null): string
    {
        return $this->parentNamespace($parentId) . ':' . $this->normalizeName($rawName);
    }

    private function collapseWhitespace(?string $rawName): string
    {
        if ($rawName === null) {
            return '';
        }

        // Replace Unicode separator whitespace (\p{Z}), ASCII whitespace (\s), and zero-width spaces (\x{200B}-\x{200D}\x{FEFF})
        $normalized = preg_replace('/[\p{Z}\s\x{200B}-\x{200D}\x{FEFF}]+/u', ' ', $rawName);

        return trim((string) $normalized);
    }
}
