<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductPriceFeedEvent;
use Modules\Product\Services\CrossBusinessPriceService;

use Modules\Product\Services\ProductCreator;
use Modules\Product\Services\ProductLastPurchasePriceSynchronizer;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductPriceFeedIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingA = Setting::create([
            'company_name' => 'Business Alpha',
            'company_email' => 'alpha@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'alpha@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Alpha',
            'company_address' => 'Alpha St',
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Business Beta',
            'company_email' => 'beta@example.com',
            'company_phone' => '08123456788',
            'notification_email' => 'beta@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Beta',
            'company_address' => 'Beta St',
        ]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_product_creation_records_feed_event(): void
    {
        $creator = app(ProductCreator::class);

        $product = $creator->create([
            'product_name' => 'Integration Test Product',
            'product_code' => 'ITP-001',
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 10000,
            'sale_price' => 15000,
            'tier_1_price' => 14000,
            'tier_2_price' => 13000,
            'source' => ProductPriceFeedEvent::SOURCE_QUICK_ADD,
        ]);

        $this->assertDatabaseHas('product_price_feed_events', [
            'subject_id' => $product->id,
            'event_type' => ProductPriceFeedEvent::TYPE_PRODUCT_CREATED,
            'source' => ProductPriceFeedEvent::SOURCE_QUICK_ADD,
        ]);

        $event = ProductPriceFeedEvent::where('subject_id', $product->id)->first();
        $this->assertCount(2, $event->snapshots);
    }

    public function test_cross_business_price_update_records_grouped_feed_event(): void
    {
        $product = Product::create([
            'product_name' => 'Cross Biz Item',
            'product_code' => 'CBI-001',
            'setting_id' => $this->settingA->id,
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'profit_percentage' => 0,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 10000,
            'tier_1_price' => 10000,
            'tier_2_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->settingB->id,
            'sale_price' => 10000,
            'tier_1_price' => 10000,
            'tier_2_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        $service = app(CrossBusinessPriceService::class);
        $pricesData = $service->loadPricesForProduct($product);

        // Update sale_price for setting A and setting B
        foreach ($pricesData as &$data) {
            $data['sale_price'] = 12000;
        }

        $service->savePricesForProduct($product, $pricesData);

        $event = ProductPriceFeedEvent::where('subject_id', $product->id)
            ->where('event_type', ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED)
            ->first();

        $this->assertNotNull($event);
        $this->assertCount(2, $event->snapshots);

        foreach ($event->snapshots as $snap) {
            $this->assertEquals(['sale_price' => 10000.0], $snap->before_snapshot);
            $this->assertEquals(['sale_price' => 12000.0], $snap->after_snapshot);
        }
    }

    public function test_last_purchase_price_sync_records_feed_event(): void
    {
        $product = Product::create([
            'product_name' => 'Sync Test Item',
            'product_code' => 'STI-001',
            'setting_id' => $this->settingA->id,
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'profit_percentage' => 0,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 10000,
            'last_purchase_price' => 5000,
        ]);

        $sync = app(ProductLastPurchasePriceSynchronizer::class);
        $sync->syncLastPurchasePrice($product->id, 7500);

        $event = ProductPriceFeedEvent::where('subject_id', $product->id)
            ->where('event_type', ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED)
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals(ProductPriceFeedEvent::SOURCE_PURCHASE_SYNC, $event->source);
    }

    public function test_product_import_batch_existing_and_new_products_records_feed_events(): void
    {
        // First setting created in test is $this->settingA
        $existingProduct = Product::create([
            'product_name' => 'Existing Import Product',
            'product_code' => 'IMP-001',
            'canonical_name' => app(\Modules\Product\Services\ProductCanonicalizer::class)->canonicalize('Existing Import Product')['canonical_key'],
            'setting_id' => $this->settingA->id,
            'is_active' => true,
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'profit_percentage' => 0,
        ]);

        ProductPrice::create([
            'product_id' => $existingProduct->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 10000,
            'tier_1_price' => 10000,
            'tier_2_price' => 10000,
            'last_purchase_price' => 5000,
        ]);

        ProductPrice::create([
            'product_id' => $existingProduct->id,
            'setting_id' => $this->settingB->id,
            'sale_price' => 10000,
            'tier_1_price' => 10000,
            'tier_2_price' => 10000,
            'last_purchase_price' => 5000,
        ]);

        $location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->settingA->id,
            'name' => 'Main Warehouse',
        ]);

        $batch = \Modules\Product\Entities\ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $location->id,
            'setting_id' => $this->settingA->id,
            'status' => 'queued',
            'original_filename' => 'test.xlsx',
            'source_csv_path' => 'imports/test.xlsx',
            'total_rows' => 2,
        ]);

        // Staged row for existing product (update)
        \Modules\Product\Entities\ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'status' => null,
            'raw_json' => [
                'product_name' => 'Existing Import Product',
                'product_code' => 'IMP-001',
                'sale_price' => 20000,
                'purchase_price' => 8000,
                'unit_name' => 'Pcs',
            ],
            'raw_data' => [
                'product_name' => 'Existing Import Product',
                'product_code' => 'IMP-001',
                'sale_price' => 20000,
                'purchase_price' => 8000,
                'unit_name' => 'Pcs',
            ],
        ]);

        // Staged row for new product (create)
        \Modules\Product\Entities\ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 3,
            'status' => null,
            'raw_json' => [
                'product_name' => 'New Import Product',
                'product_code' => 'IMP-002',
                'sale_price' => 30000,
                'purchase_price' => 15000,
                'unit_name' => 'Pcs',
            ],
            'raw_data' => [
                'product_name' => 'New Import Product',
                'product_code' => 'IMP-002',
                'sale_price' => 30000,
                'purchase_price' => 15000,
                'unit_name' => 'Pcs',
            ],
        ]);

        $job = new \Modules\Product\Jobs\ProcessProductImportBatch($batch->id);
        $job->handle();

        // 1. Existing product price updated feed event
        $this->assertDatabaseHas('product_price_feed_events', [
            'subject_id' => $existingProduct->id,
            'event_type' => ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED,
            'source' => ProductPriceFeedEvent::SOURCE_IMPORT,
        ]);

        // 2. New product created feed event
        $newProduct = Product::where('product_code', 'IMP-002')->first();
        $this->assertNotNull($newProduct);
        $this->assertDatabaseHas('product_price_feed_events', [
            'subject_id' => $newProduct->id,
            'event_type' => ProductPriceFeedEvent::TYPE_PRODUCT_CREATED,
            'source' => ProductPriceFeedEvent::SOURCE_IMPORT,
        ]);
    }
}
