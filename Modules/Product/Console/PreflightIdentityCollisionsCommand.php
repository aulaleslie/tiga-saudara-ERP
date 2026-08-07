<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Modules\Product\Entities\Product;
use Modules\Product\Services\ProductCanonicalizer;
use Modules\Product\Services\DTOs\ProductReferenceInventory;

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
                    $inventory = ProductReferenceInventory::forProduct($product);

                    // Extract supported counts
                    $supportedCounts = [];
                    foreach (ProductReferenceInventory::supportedRelations() as $table => $column) {
                        if (!empty($inventory[$table])) {
                            $supportedCounts[$table] = $inventory[$table];
                        }
                    }

                    // Extract unsupported counts
                    $unsupportedCounts = [];
                    foreach (ProductReferenceInventory::unsupportedRelations() as $table => $column) {
                        if (!empty($inventory[$table])) {
                            $unsupportedCounts[$table] = $inventory[$table];
                        }
                    }

                    $supportedStr = implode("\n", array_map(
                        fn($k, $v) => "$k: $v",
                        array_keys($supportedCounts),
                        array_values($supportedCounts)
                    ));

                    $unsupportedStr = implode("\n", array_map(
                        fn($k, $v) => "$k: $v",
                        array_keys($unsupportedCounts),
                        array_values($unsupportedCounts)
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
}
