<?php

namespace Modules\Product\Services;

class ProductCanonicalizer
{
    /**
     * Parse and canonicalize a product name for identity resolution.
     * 
     * 1. Replaces non-breaking and narrow Unicode spaces with ordinary spaces
     * 2. Trims and collapses all whitespace runs to one space
     * 3. Removes import-context owner markers (leading `*` and trailing standalone `TP`)
     * 4. Returns the cleaned, case-preserving display name and case-folded canonical key.
     *
     * @param string $rawName
     * @return array{display_name: string, canonical_key: string}
     */
    public function canonicalize(string $rawName): array
    {
        // 1. Replace various Unicode spaces with ordinary spaces
        // \u{00A0} is NBSP, \u{200B} is zero-width space, etc.
        // We'll use a regex that matches common whitespace, including non-breaking.
        // mb_ereg_replace or preg_replace with /u flag.
        
        $name = preg_replace('/[\p{Z}\p{C}]+/u', ' ', $rawName);
        $name = trim((string) $name);

        // 3. Remove import-context owner markers
        // Remove leading `*` and any spaces after it
        if (str_starts_with($name, '*')) {
            $name = trim(substr($name, 1));
        }

        // Remove trailing ` TP` case-insensitively, requiring a preceding space OR start of string
        if (preg_match('/(?i)(?:^|\s+)TP$/', $name)) {
            $name = trim(preg_replace('/(?i)(?:^|\s+)TP$/', '', $name));
        }

        // 2. Trim and collapse whitespace runs to one space (already mostly done by \p{Z} replace, but ensure we don't have multiple spaces left)
        $name = preg_replace('/\s+/', ' ', $name);
        $displayName = trim((string) $name);

        if ($displayName === '') {
            throw new \InvalidArgumentException('Product name cannot be blank after canonicalization.');
        }

        // 5. Case-fold the cleaned value for the canonical key
        $canonicalKey = mb_strtolower($displayName, 'UTF-8');

        return [
            'display_name' => $displayName,
            'canonical_key' => $canonicalKey,
        ];
    }
}
