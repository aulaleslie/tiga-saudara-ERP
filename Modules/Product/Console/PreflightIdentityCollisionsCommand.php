<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Entities\Product;
use Modules\Product\Services\ProductCanonicalizer;

class PreflightIdentityCollisionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'product:identity-preflight';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Preflight report listing catalog identity collisions and their reference counts.';

    /**
     * Execute the console command.
     */
    public function handle(ProductCanonicalizer $canonicalizer)
    {
        $this->info('Scanning products for canonical identity collisions...');

        $products = Product::get();
        
        $groups = [];
        $uncanonicalizable = [];

        foreach ($products as $product) {
            try {
                $canonical = $canonicalizer->canonicalize($product->product_name);
                $key = $canonical['canonical_key'];
                
                if (!isset($groups[$key])) {
                    $groups[$key] = [];
                }
                $groups[$key][] = $product;
            } catch (\Exception $e) {
                // Invalid names that can't be canonicalized
                $uncanonicalizable[] = [
                    'product' => $product,
                    'error' => $e->getMessage()
                ];
            }
        }

        $collisions = array_filter($groups, fn($group) => count($group) > 1);

        if (empty($collisions) && empty($uncanonicalizable)) {
            $this->info('No canonical identity collisions found. All products are unique.');
            return 0;
        }

        if (!empty($uncanonicalizable)) {
            $this->warn(sprintf('Found %d product identities that cannot be canonicalized.', count($uncanonicalizable)));
            $this->line('');
            $this->error("Un-canonicalizable Identities:");
            
            $headers = ['ID', 'Name', 'Code', 'Error'];
            $rows = [];
            foreach ($uncanonicalizable as $item) {
                $p = $item['product'];
                $rows[] = [$p->id, $p->product_name, $p->product_code, $item['error']];
            }
            $this->table($headers, $rows);
        }

        if (!empty($collisions)) {
            $this->warn(sprintf('Found %d canonical identity collisions.', count($collisions)));

            foreach ($collisions as $key => $group) {
                $this->line('');
                $this->info("Canonical Key: {$key}");
                
                $headers = [
                    'ID', 
                    'Name', 
                    'Code', 
                    'DB Canonical', 
                    'Ref Counts (Supported)',
                    'Ref Counts (Unsupported)'
                ];
                $rows = [];
                
                foreach ($group as $product) {
                    $supportedCounts = $this->getSupportedReferenceCounts($product);
                    $unsupportedCounts = $this->getUnsupportedReferenceCounts($product);
                    
                    $supportedStr = implode("\n", array_map(
                        fn($k, $v) => "$k: $v",
                        array_keys(array_filter($supportedCounts)),
                        array_filter($supportedCounts)
                    ));
                    
                    $unsupportedStr = implode("\n", array_map(
                        fn($k, $v) => "$k: $v",
                        array_keys(array_filter($unsupportedCounts)),
                        array_filter($unsupportedCounts)
                    ));
                    
                    $rows[] = [
                        $product->id,
                        $product->product_name,
                        $product->product_code,
                        $product->canonical_name ?? 'NULL',
                        $supportedStr ?: 'None',
                        $unsupportedStr ?: 'None',
                    ];
                }
                
                $this->table($headers, $rows);
            }
        }

        return 0;
    }

    private function getSupportedReferenceCounts(Product $product): array
    {
        $counts = [
            'transactions' => $product->transactions()->count(),
            'product_prices' => $product->prices()->count(),
            'product_stocks' => $product->productStocks()->count(),
            'purchase_details' => $product->purchaseDetails()->count(),
            'product_bundles_parent' => $product->bundles()->count(),
            'product_bundle_items' => $product->bundledIn()->count(),
            'product_unit_conversions' => $product->conversions()->count(),
        ];
        
        $counts['sale_details'] = $this->tableCount('sale_details', 'product_id', $product->id);
        $counts['dispatch_details'] = $this->tableCount('dispatch_details', 'product_id', $product->id);
        $counts['sale_return_details'] = $this->tableCount('sale_return_details', 'product_id', $product->id);
        $counts['purchase_return_details'] = $this->tableCount('purchase_return_details', 'product_id', $product->id);
        
        return $counts;
    }

    private function getUnsupportedReferenceCounts(Product $product): array
    {
        $counts = [
            'product_serial_numbers' => $product->serialNumbers()->count(),
        ];
        
        $counts['quotation_details'] = $this->tableCount('quotation_details', 'product_id', $product->id);
        $counts['pos_draft_items'] = $this->tableCount('pos_draft_items', 'product_id', $product->id);
        $counts['purchase_return_goods'] = $this->tableCount('purchase_return_goods', 'product_id', $product->id);
        $counts['sale_return_goods'] = $this->tableCount('sale_return_goods', 'product_id', $product->id);
        $counts['sale_bundle_items'] = $this->tableCount('sale_bundle_items', 'product_id', $product->id);
        $counts['import_rows'] = $this->tableCount('import_rows', 'product_id', $product->id);
        $counts['barcode_identities'] = $this->tableCount('barcode_identities', 'product_id', $product->id);
        $counts['product_barcode_assignments'] = $this->tableCount('product_barcode_assignments', 'product_id', $product->id);
        $counts['adjusted_products'] = $this->tableCount('adjusted_products', 'product_id', $product->id);
        $counts['transfer_products'] = $this->tableCount('transfer_products', 'product_id', $product->id);
        $counts['pos_transaction_lines'] = $this->tableCount('pos_transaction_lines', 'product_id', $product->id);
        $counts['received_note_details'] = $this->tableCount('received_note_details', 'product_id', $product->id);
        
        // Return lines check both the returned product and the replacement product
        $counts['pos_return_lines (as returned)'] = $this->tableCount('pos_return_lines', 'product_id', $product->id);
        $counts['pos_return_lines (as replacement)'] = $this->tableCount('pos_return_lines', 'replacement_product_id', $product->id);
        
        return $counts;
    }

    private function tableCount(string $table, string $column, $id): int
    {
        try {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                return DB::table($table)->where($column, $id)->count();
            }
        } catch (\Exception $e) {
            // Table might not exist in testing
        }
        return 0;
    }
}
