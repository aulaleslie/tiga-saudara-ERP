<?php

namespace App\Support;

class SalesImportMarkerResolver
{
    /**
     * Parse product name and extract marker type.
     *
     * @return array{clean_name: string, marker: string}
     */
    public function parseProductName(string $rawName): array
    {
        $name = trim($rawName);

        // Check for asterisk prefix
        if (str_starts_with($name, '*')) {
            return [
                'clean_name' => trim(ltrim($name, '* ')),
                'marker' => 'asterisk',
            ];
        }

        // Check for TP suffix
        if (str_ends_with($name, ' TP')) {
            return [
                'clean_name' => trim(substr($name, 0, -3)),
                'marker' => 'tp',
            ];
        }

        // No marker
        return [
            'clean_name' => $name,
            'marker' => 'default',
        ];
    }

    /**
     * Normalize product name: uppercase, collapse whitespace.
     * Does NOT rewrite product names via aliases or fuzzy matching.
     * Preserves punctuation and all words for canonical identity.
     */
    public function normalizeProductName(string $rawName): string
    {
        $normalized = strtoupper($rawName);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);

        return $normalized;
    }

    /**
     * Detect if a product name matches Daizu criteria (contains whole-word KEDELE, KEDELAI, or RAGI).
     */
    public function isDaizuProduct(string $rawName): bool
    {
        $normalized = $this->normalizeProductName($rawName);
        return preg_match('/\b(KEDELE|KEDELAI|RAGI)\b/', $normalized) === 1;
    }

    /**
     * Resolve a stable effective-owner grouping key for a row.
     * Daizu (Priority 0), then product marker (Priority 1).
     */
    public function resolveEffectiveOwnerKey(string $productName): string
    {
        if ($this->isDaizuProduct($productName)) {
            return 'daizu';
        }

        $parsed = $this->parseProductName($productName);
        return 'marker:' . $parsed['marker'];
    }

    /**
     * Resolve the target company name search string for a given product name.
     */
    public function resolveOwnerCompanyName(string $productName): string
    {
        if ($this->isDaizuProduct($productName)) {
            return 'DAIZU';
        }

        $parsed = $this->parseProductName($productName);
        $marker = $parsed['marker'];

        if ($marker === 'asterisk') {
            return 'CV TIGA NUSA COMPUTER';
        }

        if ($marker === 'tp') {
            return 'CV TOP IT INTERNUSA';
        }

        // No marker — always Perdana
        return 'PERDANA';
    }
}
