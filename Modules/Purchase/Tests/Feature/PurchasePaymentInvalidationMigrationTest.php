<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Tests\TestCase;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Illuminate\Support\Facades\DB;

class PurchasePaymentInvalidationMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        Currency::create([
             'id' => 1,
             'currency_name' => 'Rupiah',
             'code' => 'IDR',
             'symbol' => 'Rp',
             'thousand_separator' => '.',
             'decimal_separator' => ',',
             'exchange_rate' => 1,
        ]);
        
        Setting::create([
             'id' => 1,
             'company_name' => 'Test Company',
             'company_email' => 'test@company.com',
             'company_phone' => '1234567890',
             'company_address' => 'Test Address',
             'default_currency_id' => 1,
             'default_currency_position' => 'prefix',
             'notification_email' => 'notification@test.com',
             'footer_text' => 'Test Footer',
        ]);
    }

    /** @test */
    public function it_has_invalidation_columns_in_purchase_payments_table()
    {
        $this->assertTrue(Schema::hasColumn('purchase_payments', 'status'));
        $this->assertTrue(Schema::hasColumn('purchase_payments', 'invalidated_at'));
        $this->assertTrue(Schema::hasColumn('purchase_payments', 'invalidated_by'));
        $this->assertTrue(Schema::hasColumn('purchase_payments', 'invalidation_source'));
        $this->assertTrue(Schema::hasColumn('purchase_payments', 'invalidation_source_id'));
    }

    /** @test */
    public function it_defaults_status_to_active_for_new_payments()
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => 1,
        ]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now(),
            'reference' => 'PUR-001',
            'supplier_id' => $supplier->id,
            'payment_method' => 'Cash',
            'status' => 'Received',
            'payment_status' => 'Unpaid',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'setting_id' => 1,
        ]);

        $payment = PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 50000,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
        ]);

        $this->assertEquals(PurchasePayment::STATUS_ACTIVE, $payment->status);
    }

    /** @test */
    public function it_has_index_on_status_column()
    {
        $schemaManager = DB::connection()->getDoctrineSchemaManager();
        $indexes = $schemaManager->listTableIndexes('purchase_payments');
        
        $hasStatusIndex = false;
        foreach ($indexes as $index) {
            if (in_array('status', $index->getColumns())) {
                $hasStatusIndex = true;
                break;
            }
        }
        
        $this->assertTrue($hasStatusIndex, 'Index on status column is missing');
    }

    /** @test */
    public function it_correctly_filters_active_payments_via_scope()
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => 1,
        ]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now(),
            'reference' => 'PUR-002',
            'supplier_id' => $supplier->id,
            'payment_method' => 'Cash',
            'status' => 'Received',
            'payment_status' => 'Unpaid',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'setting_id' => 1,
        ]);

        $activePayment = PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 30000,
            'date' => now(),
            'reference' => 'PAY-ACTIVE',
            'payment_method' => 'Cash',
        ]);

        $invalidatedPayment = PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 20000,
            'date' => now(),
            'reference' => 'PAY-INVALID',
            'payment_method' => 'Cash',
        ]);
        
        // Manual update to invalidated since we haven't implemented invalidate action yet
        $invalidatedPayment->update(['status' => PurchasePayment::STATUS_INVALIDATED]);

        $activePayments = PurchasePayment::active()->get();

        $this->assertTrue($activePayments->contains($activePayment));
        $this->assertFalse($activePayments->contains($invalidatedPayment));
        $this->assertEquals(1, $activePayments->count());
    }
}
