<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Product\Jobs\ProcessProductImportBatch;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Tests\TestCase;

class ImportTransactionNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $currencyId = DB::table('currencies')->insertGetId([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->userId = DB::table('users')->insertGetId([
            'name' => 'Test User',
            'email' => 'user@test.com',
            'password' => 'secret',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settingId = DB::table('settings')->insertGetId([
            'company_name' => 'CV TIGA NUSA COMPUTER',
            'company_email' => 'test@company.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => $currencyId,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@test.com',
            'footer_text' => 'Footer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->setting = Setting::find($settingId);

        $this->customerId = DB::table('customers')->insertGetId([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@test.com',
            'customer_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locationId = DB::table('locations')->insertGetId([
            'setting_id' => $settingId,
            'name' => 'Test Location',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->location = Location::find($locationId);

        $productId = DB::table('products')->insertGetId([
            'product_name' => 'Test Product',
            'setting_id' => $settingId,
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'is_purchased' => 1,
            'is_sold' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->product = Product::find($productId);

        $this->product = Product::find($productId);
    }

    /** @test */
    public function sales_and_purchase_imports_do_not_mutate_stock_or_create_transactions_at_runtime()
    {
        DB::table('product_stocks')->insert([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->stock = ProductStock::where('product_id', $this->product->id)->first();

        $purchaseId = DB::table('purchases')->insertGetId([
            'setting_id' => $this->setting->id,
            'supplier_purchase_number' => 'IMP-PO-001',
            'date' => now()->subDays(2),
            'reference' => 'PO-1',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'due_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_details')->insert([
            'purchase_id' => $purchaseId,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'setting_id' => $this->setting->id,
            'imported_sales_reference_number' => 'IMP-SO-001',
            'date' => now(),
            'reference' => 'SO-1',
            'customer_id' => $this->customerId,
            'customer_name' => 'Test Customer',
            'total_amount' => 750,
            'paid_amount' => 0,
            'due_amount' => 750,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sale_details')->insert([
            'sale_id' => $saleId,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 5,
            'price' => 150,
            'unit_price' => 150,
            'sub_total' => 750,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Assert no transactions created
        $this->assertDatabaseCount('transactions', 0);
        
        // Assert stock is untouched
        $this->assertEquals(0, $this->stock->fresh()->quantity);
        $this->assertEquals(0, $this->product->fresh()->product_quantity);
    }

    /** @test */
    public function initialization_command_truncates_transactions_only_with_explicit_flags()
    {
        DB::table('transactions')->insert([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'type' => 'INIT',
            'quantity' => 100,
            'current_quantity' => 100,
            'previous_quantity' => 0,
            'after_quantity' => 100,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 100,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('transactions', 1);

        // Run without flags
        Artisan::call('inventory:normalize-import-transactions');
        $this->assertDatabaseCount('transactions', 1); // Not truncated

        // Run with flags
        Artisan::call('inventory:normalize-import-transactions', [
            '--initialize' => true,
            '--write' => true,
        ]);
        
        $this->assertDatabaseCount('transactions', 0); // Truncated
    }

    /** @test */
    public function command_creates_deterministic_buy_and_sell_rows_with_correct_balances_and_decimals()
    {
        $purchase1Id = DB::table('purchases')->insertGetId([
            'setting_id' => $this->setting->id,
            'supplier_purchase_number' => 'IMP-PO-1',
            'date' => '2023-01-01 10:00:00',
            'reference' => 'PO-1',
            'total_amount' => 1050,
            'paid_amount' => 0,
            'due_amount' => 1050,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'due_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_details')->insert([
            'purchase_id' => $purchase1Id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10.5,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1050,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'setting_id' => $this->setting->id,
            'imported_sales_reference_number' => 'IMP-SO-1',
            'date' => '2023-01-01 15:00:00',
            'reference' => 'SO-1',
            'customer_id' => $this->customerId,
            'customer_name' => 'Test Customer',
            'total_amount' => 480,
            'paid_amount' => 0,
            'due_amount' => 480,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sale_details')->insert([
            'sale_id' => $saleId,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 3.2,
            'price' => 150,
            'unit_price' => 150,
            'sub_total' => 480,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('inventory:normalize-import-transactions', [
            '--initialize' => true,
            '--write' => true,
        ]);

        $transactions = Transaction::orderBy('id')->get();
        
        $this->assertCount(2, $transactions);
        
        // Assert BUY
        $this->assertEquals('BUY', $transactions[0]->type);
        $this->assertEquals(10.5, $transactions[0]->quantity);
        $this->assertEquals(0, $transactions[0]->previous_quantity);
        $this->assertEquals(10.5, $transactions[0]->after_quantity);
        
        // Assert SELL
        $this->assertEquals('SELL', $transactions[1]->type);
        $this->assertEquals(-3.2, $transactions[1]->quantity);
        $this->assertEquals(10.5, $transactions[1]->previous_quantity);
        $this->assertEquals(7.3, $transactions[1]->after_quantity);

        // Assert product stock was untouched
        $stock = \Modules\Product\Entities\ProductStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(0, $stock ? $stock->quantity : 0);
    }

    /** @test */
    public function stock_snapshot_import_creates_adj_from_latest_normalized_ledger_balance()
    {
        DB::table('transactions')->insert([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'type' => 'BUY',
            'quantity' => 15,
            'current_quantity' => 15,
            'previous_quantity' => 0,
            'after_quantity' => 15,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 15,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Setup batch
        $batch = ProductImportBatch::forceCreate([
            'user_id' => $this->userId,
            'status' => 'queued',
            'import_type' => 'stock_snapshot',
            'source_csv_path' => 'dummy.csv',
            'location_id' => $this->location->id,
        ]);

        // 3. Setup row
        $row = ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'product_name' => '* ' . $this->product->product_name,
                'total_quantity' => 20, // New snapshot quantity
            ],
        ]);

        // 4. Run processor (only the row, not the full job to avoid CSV loading)
        // Need to use reflection to call processRow or just run the full job if we mock CSV.
        // Let's use reflection to call processStockSnapshotRow
        $job = new ProcessProductImportBatch($batch->id);
        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('defaultSettingId');
        $property->setAccessible(true);
        $property->setValue($job, $this->setting->id);

        $propertyBatch = $reflection->getProperty('batch');
        $propertyBatch->setAccessible(true);
        $propertyBatch->setValue($job, $batch);
        
        $method = $reflection->getMethod('processStockSnapshotRow');
        $method->setAccessible(true);
        $row = ProductImportRow::find($row->id);
        
        $owner = \Modules\Setting\Entities\Setting::where('company_name', 'like', '%CV TIGA NUSA COMPUTER%')->first();
        $location = \Modules\Setting\Entities\Location::where('setting_id', $owner?->id)->first();
        
        $method->invoke($job, $row);

        $row->refresh();
        if ($row->status === 'error') {
            dump($row->error_message);
        }

        // 5. Assert ADJ transaction
        $adjTxn = Transaction::where('type', 'ADJ')->first();
        $this->assertNotNull($adjTxn);
        $this->assertEquals(5, (float)$adjTxn->quantity); // 20 - 15 = 5
        $this->assertEquals(15, (float)$adjTxn->previous_quantity);
        $this->assertEquals(20, (float)$adjTxn->after_quantity);
        $this->assertEquals(15, (float)$adjTxn->previous_quantity_at_location);
        $this->assertEquals(20, (float)$adjTxn->after_quantity_at_location);
        
        // 6. Assert product stock hardened
        $stock = \Modules\Product\Entities\ProductStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(20, $stock->quantity);
        $this->assertEquals(20, $this->product->fresh()->product_quantity);
    }
}
