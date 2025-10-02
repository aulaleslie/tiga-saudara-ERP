<?php

namespace App\Console\Commands;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;

class ReindexProductsForSetting extends Command
{
    protected $signature = 'search:reindex-products {settingId} {--chunk=800}';
    protected $description = 'Reindex products into an Algolia index for a specific Setting';

    public function handle(): int
    {
        $settingId = (int) $this->argument('settingId');
        $chunk = (int) $this->option('chunk');
        $indexName = "products_setting_{$settingId}";

        $client = SearchClient::create(
            config('scout.algolia.id'),
            config('scout.algolia.secret')
        );
        // Initial index settings (tweak anytime)
        $client->setSettings($indexName, [
            'searchableAttributes' => [
                'product_name', 'brand', 'category', 'product_code', 'barcode'
            ],
            'attributesForFaceting' => ['brand_id', 'category_id'],
            'customRanking' => ['desc(popularity)', 'asc(product_name)'], // if you add popularity later
            'ranking' => ['typo','words','filters','proximity','attribute','exact','custom'],
            'minWordSizefor1Typo' => 4,
            'minWordSizefor2Typos' => 8,
        ]);

        Product::query()
            ->with(['brand:id,name', 'category:id,category_name'])
            ->orderBy('id')
            ->chunk($chunk, function ($products) use ($client, $settingId, $indexName) {
                // preload prices for this setting to avoid N+1
                $prices = DB::table('product_prices')
                    ->select('product_id', 'sale_price')
                    ->where('setting_id', $settingId)
                    ->whereIn('product_id', $products->pluck('id'))
                    ->pluck('sale_price', 'product_id');

                $records = [];
                foreach ($products as $p) {
                    $record = $p->toSearchableArray();
                    $record['objectID']     = $p->id; // Algolia requires objectID
                    $record['price_active'] = (float) ($prices[$p->id] ?? 0);
                    // optionally: $record['in_stock'] = ...; $record['popularity'] = ...
                    $records[] = $record;
                }

                if ($records) {
                    $client->saveObjects($indexName, $records);
                }
            });

        $this->info("Reindexed into {$indexName}");
        return self::SUCCESS;
    }
}
