<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;
use AddInvalidationToPurchasePayments; // We will need to include the migration manually or reference it

class PurchasePaymentBackfillSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Disable foreign keys for test setup ease
        DB::statement('PRAGMA foreign_keys = OFF');
        
        // Setup basic dependencies
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
        
         Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => 1,
        ]);

        Purchase::create([
            'id' => 1,
            'date' => now(),
            'due_date' => now(),
            'reference' => 'PUR-001',
            'supplier_id' => 1,
            'payment_method' => 'Cash',
            'status' => 'Received',
            'payment_status' => 'Unpaid',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'setting_id' => 1,
        ]);
    }

    /** @test */
    public function it_applies_active_status_to_pre_existing_payments_during_migration()
    {
        // 1. Simulate Pre-Migration State: Drop the table and recreate it with OLD schema
        Schema::dropIfExists('purchase_payments');
        
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id');
            // Using integer or bigInteger based on original migration but let's stick to what we saw
            $table->integer('amount');
            $table->date('date');
            $table->string('reference');
            $table->string('payment_method');
            $table->text('note')->nullable();
            // Don't add foreign key constraint if it complicates sqlite recreation, or do if needed. 
            // Since we turned off FK checks, simple integer is enough for test.
            // $table->foreign('purchase_id')->references('id')->on('purchases')->cascadeOnDelete();
            $table->timestamps();
        });

        // Verify columns are gone (sanity check)
        $this->assertFalse(Schema::hasColumn('purchase_payments', 'status'));

        // 2. Insert "Legacy" Data
        DB::table('purchase_payments')->insert([
            'id' => 1,
            'purchase_id' => 1,
            'amount' => 50000,
            'date' => now(),
            'reference' => 'PAY-LEGACY-001',
            'payment_method' => 'Cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Run the Migration
        // We need to require the file because it's not autoloaded by PSR-4 usually
        $migrationPath = 'Modules/Purchase/Database/Migrations/2026_02_08_022937_add_invalidation_to_purchase_payments.php';
        if (file_exists(base_path($migrationPath))) {
            require_once base_path($migrationPath);
        } else {
             $this->fail('Migration file not found at: ' . $migrationPath);
        }
        
        // Dynamic class name instantiation if needed, or direct
        $migration = new \AddInvalidationToPurchasePayments();
        $migration->up();

        // 4. Assert Post-Migration State
        $this->assertTrue(Schema::hasColumn('purchase_payments', 'status'));
        
        $payment = DB::table('purchase_payments')->find(1);
        
        $this->assertEquals('ACTIVE', $payment->status, 'Existing payment should have STATUS=ACTIVE after migration backfill');
        $this->assertNull($payment->invalidated_at, 'Existing payment should NOT be invalidated');
        $this->assertNull($payment->invalidated_by);
    }
}
