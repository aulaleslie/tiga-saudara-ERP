<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Utils\BarcodeUtils;

class BarcodePreflightService
{
    /**
     * Finds and reports all historical barcode duplicates within and across
     * the products and product_unit_conversions tables.
     *
     * @return array An array of canonical keys and their conflicting owners.
     */
    public function detectDuplicates(): array
    {
        $registry = [];
        $conflicts = [];
        $invalid = [];

        DB::table('products')
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($products) use (&$registry, &$invalid) {
                foreach ($products as $p) {
                    $key = BarcodeUtils::canonicalize($p->barcode);
                    if (!$key) {
                        $invalid[] = ['type' => 'product', 'id' => $p->id, 'barcode' => $p->barcode];
                        continue;
                    }
                    $registry[$key][] = ['type' => 'product', 'id' => $p->id, 'barcode' => $p->barcode];
                }
            });

        DB::table('product_unit_conversions')
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($conversions) use (&$registry, &$invalid) {
                foreach ($conversions as $c) {
                    $key = BarcodeUtils::canonicalize($c->barcode);
                    if (!$key) {
                        $invalid[] = ['type' => 'conversion', 'id' => $c->id, 'product_id' => $c->product_id, 'barcode' => $c->barcode];
                        continue;
                    }
                    $registry[$key][] = ['type' => 'conversion', 'id' => $c->id, 'product_id' => $c->product_id, 'barcode' => $c->barcode];
                }
            });

        foreach ($registry as $key => $owners) {
            if (count($owners) > 1) {
                $conflicts[$key] = $owners;
            }
        }

        return [
            'conflicts' => $conflicts,
            'invalid' => $invalid,
        ];
    }
}
