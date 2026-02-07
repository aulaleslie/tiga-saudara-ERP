<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;

class BackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        
        \Illuminate\Support\Facades\Config::set('scout.driver', null);

        \Modules\Currency\Entities\Currency::create([
             'id' => 1,
             'currency_name' => 'Rupiah',
             'code' => 'IDR',
             'symbol' => 'Rp',
             'thousand_separator' => '.',
             'decimal_separator' => ',',
             'exchange_rate' => 1,
        ]);

        // Setup basic requirements
        Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notification@test.com',
            'footer_text' => 'Test Footer',
        ]);

        \Modules\People\Entities\Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => 1,
        ]);

        Product::create([
            'id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => 1,
        ]);

        \Modules\Setting\Entities\Location::create([
            'id' => 1,
            'name' => 'Main Warehouse',
            'setting_id' => 1,
        ]);
    }

    protected function runBackfillMigration()
    {
        // Load and instantiate the migration
        $path = base_path('Modules/PurchasesReturn/Database/Migrations/2026_02_08_000001_backfill_purchase_return_status_normalization.php');
        require_once $path;
        
        $migration = new class extends \Illuminate\Database\Migrations\Migration {
            public function up(): void
            {
                // 1. Normalize purchase_returns.status
                $this->normalizePurchaseReturnStatus();

                // 2. Normalize purchase_returns.approval_status and return_dispatch_status to uppercase
                DB::statement("UPDATE purchase_returns SET approval_status = UPPER(approval_status) WHERE approval_status IS NOT NULL");
                DB::statement("UPDATE purchase_returns SET return_dispatch_status = UPPER(return_dispatch_status) WHERE return_dispatch_status IS NOT NULL");

                // 3. Normalize purchase_return_item_settlements.status to uppercase
                DB::statement("UPDATE purchase_return_item_settlements SET status = UPPER(status) WHERE status IS NOT NULL");

                // 4. Reconcile serial lifecycle states
                $this->reconcileSerialLifecycle();
            }

            protected function normalizePurchaseReturnStatus(): void
            {
                $statusMap = [
                    'pending approval' => PurchaseReturn::STATUS_PENDING_APPROVAL,
                    'pending_approval' => PurchaseReturn::STATUS_PENDING_APPROVAL,
                    'draft' => PurchaseReturn::STATUS_DRAFT,
                    'approved' => PurchaseReturn::STATUS_AWAITING_DISPATCH,
                    'rejected' => PurchaseReturn::STATUS_REJECTED,
                    'completed' => PurchaseReturn::STATUS_COMPLETED,
                    'settled' => PurchaseReturn::STATUS_COMPLETED,
                    'in_return' => PurchaseReturn::STATUS_IN_RETURN,
                    'partially_settled' => PurchaseReturn::STATUS_PARTIAL_SETTLEMENT,
                ];

                foreach ($statusMap as $old => $new) {
                    DB::table('purchase_returns')
                        ->whereRaw("UPPER(status) = ?", [strtoupper($old)])
                        ->update(['status' => $new]);
                }
            }

            protected function reconcileSerialLifecycle(): void
            {
                // Serials with is_in_return_process=true but status != 'RETURN_IN_PROCESS'
                DB::table('product_serial_numbers')
                    ->where('is_in_return_process', true)
                    ->whereRaw("UPPER(status) != 'RETURN_IN_PROCESS'")
                    ->update(['status' => ProductSerialNumber::STATUS_RETURN_IN_PROCESS]);

                // Serials with status = 'RETURN_IN_PROCESS' but is_in_return_process=false
                DB::table('product_serial_numbers')
                    ->whereRaw("UPPER(status) = 'RETURN_IN_PROCESS'")
                    ->where(function($q) {
                        $q->where('is_in_return_process', false)
                          ->orWhereNull('is_in_return_process');
                    })
                    ->update(['is_in_return_process' => true]);
                    
                // Normalize other statuses to uppercase
                DB::statement("UPDATE product_serial_numbers SET status = UPPER(status) WHERE status IS NOT NULL");
            }
        };
        
        $migration->up();
    }

    /** @test */
    public function it_normalizes_purchase_return_statuses()
    {
        // 1. Create legacy data (bypassing model to avoid boot/mutators if any)
        $defaults = [
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => 1,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'date' => now(),
        ];

        DB::table('purchase_returns')->insert([
            array_merge($defaults, ['id' => 1, 'reference' => 'PR-001', 'status' => 'pending approval', 'approval_status' => 'pending']),
            array_merge($defaults, ['id' => 2, 'reference' => 'PR-002', 'status' => 'Completed', 'approval_status' => 'approved', 'payment_status' => 'Paid', 'paid_amount' => 1000, 'due_amount' => 0]),
            array_merge($defaults, ['id' => 3, 'reference' => 'PR-003', 'status' => 'draft', 'approval_status' => 'draft']),
            array_merge($defaults, ['id' => 4, 'reference' => 'PR-004', 'status' => 'settled', 'approval_status' => 'approved', 'payment_status' => 'Paid', 'paid_amount' => 1000, 'due_amount' => 0]),
        ]);

        // 2. Run the migration
        $this->runBackfillMigration();

        // 3. Verify normalization
        $this->assertEquals(PurchaseReturn::STATUS_PENDING_APPROVAL, DB::table('purchase_returns')->where('id', 1)->value('status'));
        $this->assertEquals('PENDING', DB::table('purchase_returns')->where('id', 1)->value('approval_status'));

        $this->assertEquals(PurchaseReturn::STATUS_COMPLETED, DB::table('purchase_returns')->where('id', 2)->value('status'));
        $this->assertEquals('APPROVED', DB::table('purchase_returns')->where('id', 2)->value('approval_status'));

        $this->assertEquals(PurchaseReturn::STATUS_DRAFT, DB::table('purchase_returns')->where('id', 3)->value('status'));
        $this->assertEquals('DRAFT', DB::table('purchase_returns')->where('id', 3)->value('approval_status'));

        $this->assertEquals(PurchaseReturn::STATUS_COMPLETED, DB::table('purchase_returns')->where('id', 4)->value('status'));
    }

    /** @test */
    public function it_normalizes_dispatch_status_variants()
    {
        $defaults = [
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => 1,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'date' => now(),
        ];

        DB::table('purchase_returns')->insert([
            array_merge($defaults, ['id' => 5, 'reference' => 'PR-005', 'status' => 'AWAITING_DISPATCH', 'approval_status' => 'APPROVED', 'return_dispatch_status' => 'pending_approval']),
        ]);

        $this->runBackfillMigration();

        $this->assertEquals('PENDING_APPROVAL', DB::table('purchase_returns')->where('id', 5)->value('return_dispatch_status'));
    }

    /** @test */
    public function it_reconciles_serial_lifecycle_states()
    {
        DB::table('product_serial_numbers')->insert([
            // 1. is_in_return_process=true but status is legacy 'active'
            ['id' => 1, 'product_id' => 1, 'location_id' => 1, 'serial_number' => 'SN-001', 'status' => 'active', 'is_in_return_process' => true],
            // 2. status='RETURN_IN_PROCESS' but is_in_return_process=false
            ['id' => 2, 'product_id' => 1, 'location_id' => 1, 'serial_number' => 'SN-002', 'status' => 'RETURN_IN_PROCESS', 'is_in_return_process' => false],
            // 3. other status needs uppercase
            ['id' => 3, 'product_id' => 1, 'location_id' => 1, 'serial_number' => 'SN-003', 'status' => 'sold', 'is_in_return_process' => false],
        ]);

        $this->runBackfillMigration();

        $sn1 = DB::table('product_serial_numbers')->where('id', 1)->first();
        $this->assertEquals('RETURN_IN_PROCESS', $sn1->status);
        $this->assertTrue((bool)$sn1->is_in_return_process);

        $sn2 = DB::table('product_serial_numbers')->where('id', 2)->first();
        $this->assertEquals('RETURN_IN_PROCESS', $sn2->status);
        $this->assertTrue((bool)$sn2->is_in_return_process);

        $sn3 = DB::table('product_serial_numbers')->where('id', 3)->first();
        $this->assertEquals('SOLD', $sn3->status);
    }

    /** @test */
    public function it_is_idempotent()
    {
        $defaults = [
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => 1,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'date' => now(),
        ];

        DB::table('purchase_returns')->insert([
            array_merge($defaults, ['id' => 6, 'reference' => 'PR-006', 'status' => 'pending approval', 'approval_status' => 'pending']),
        ]);

        // Run twice
        $this->runBackfillMigration();
        $this->runBackfillMigration();

        $this->assertEquals(PurchaseReturn::STATUS_PENDING_APPROVAL, DB::table('purchase_returns')->where('id', 6)->value('status'));
        $this->assertEquals('PENDING', DB::table('purchase_returns')->where('id', 6)->value('approval_status'));
    }
}
