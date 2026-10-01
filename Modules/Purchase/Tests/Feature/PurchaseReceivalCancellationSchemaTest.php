<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteCancellation;
use Modules\Purchase\Entities\ReceivedNoteCancellationDetail;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseReceivalCancellationSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected User $user;
    protected Location $location;
    protected Unit $pcsUnit;
    protected Supplier $supplier;

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

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Setting Test',
            'company_email' => 'test@setting.com',
            'company_phone' => '12345',
            'notification_email' => 'test@setting.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Test Address 123',
        ]);

        $this->user = User::create([
            'name' => 'Test Operator',
            'email' => 'op@example.com',
            'password' => bcrypt('secret'),
            'setting_id' => $this->setting->id,
            'is_active' => 1,
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Warehouse',
            'is_active' => true,
        ]);

        $this->pcsUnit = Unit::create([
            'name' => 'Pieces',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->supplier = Supplier::create([
            'id' => 1,
            'supplier_name' => 'Main Supplier',
            'supplier_email' => 'supp@example.com',
            'supplier_phone' => '112233',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Supplier Address',
            'setting_id' => $this->setting->id,
        ]);
    }

    public function test_schema_has_cancellation_tables_and_columns(): void
    {
        // 1. received_notes columns
        $this->assertTrue(Schema::hasColumn('received_notes', 'cancelled_at'));
        $this->assertTrue(Schema::hasColumn('received_notes', 'cancelled_by'));
        $this->assertTrue(Schema::hasColumn('received_notes', 'cancellation_reason'));
        $this->assertTrue(Schema::hasColumn('received_notes', 'cancellation_origin'));

        // 2. received_note_details snapshot columns
        $this->assertTrue(Schema::hasColumn('received_note_details', 'product_id'));
        $this->assertTrue(Schema::hasColumn('received_note_details', 'product_code'));
        $this->assertTrue(Schema::hasColumn('received_note_details', 'product_name'));
        $this->assertTrue(Schema::hasColumn('received_note_details', 'purchase_unit_id'));
        $this->assertTrue(Schema::hasColumn('received_note_details', 'unit_name'));
        $this->assertTrue(Schema::hasColumn('received_note_details', 'base_unit_name'));
        $this->assertTrue(Schema::hasColumn('received_note_details', 'conversion_factor'));
        $this->assertTrue(Schema::hasColumn('received_note_details', 'entered_quantity'));
        $this->assertTrue(Schema::hasColumn('received_note_details', 'tax_id'));
        $this->assertTrue(Schema::hasColumn('received_note_details', 'location_id'));

        // 3. received_note_cancellations table
        $this->assertTrue(Schema::hasTable('received_note_cancellations'));
        $this->assertTrue(Schema::hasColumns('received_note_cancellations', [
            'id', 'received_note_id', 'purchase_id', 'setting_id',
            'previous_status', 'cancellation_origin', 'cancelled_by', 'reason', 'cancelled_at'
        ]));

        // 4. received_note_cancellation_details table
        $this->assertTrue(Schema::hasTable('received_note_cancellation_details'));
        $this->assertTrue(Schema::hasColumns('received_note_cancellation_details', [
            'id', 'cancellation_id', 'received_note_detail_id', 'product_id',
            'location_id', 'tax_id', 'quantity', 'quantity_tax', 'quantity_non_tax',
            'original_transaction_id', 'reversal_transaction_id'
        ]));

        // 5. transactions table reversal link
        $this->assertTrue(Schema::hasColumn('transactions', 'received_note_cancellation_detail_id'));
    }

    public function test_cancellation_idempotent_unique_constraint(): void
    {
        $purchase = Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'external_delivery_number' => 'DEL-001',
            'date' => now()->toDateString(),
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);

        // First cancellation record
        ReceivedNoteCancellation::create([
            'received_note_id' => $receivedNote->id,
            'purchase_id' => $purchase->id,
            'setting_id' => $this->setting->id,
            'previous_status' => ReceivedNote::STATUS_APPROVED,
            'cancellation_origin' => ReceivedNoteCancellation::ORIGIN_MANUAL_APPROVED,
            'cancelled_by' => $this->user->id,
            'reason' => 'Damaged shipment received in error',
            'cancelled_at' => now(),
        ]);

        // Attempt second cancellation record for same received_note_id must violate unique constraint
        $this->expectException(\Illuminate\Database\QueryException::class);

        ReceivedNoteCancellation::create([
            'received_note_id' => $receivedNote->id,
            'purchase_id' => $purchase->id,
            'setting_id' => $this->setting->id,
            'previous_status' => ReceivedNote::STATUS_APPROVED,
            'cancellation_origin' => ReceivedNoteCancellation::ORIGIN_MANUAL_APPROVED,
            'cancelled_by' => $this->user->id,
            'reason' => 'Duplicate cancellation',
            'cancelled_at' => now(),
        ]);
    }

    public function test_purchase_detail_deletion_preserves_received_note_detail_with_null_po_detail_id(): void
    {
        $category = Category::create([
            'category_code' => 'CAT-1',
            'category_name' => 'Category 1',
            'created_by' => $this->user->id,
            'setting_id' => $this->setting->id,
        ]);

        $product = Product::create([
            'product_name' => 'Product Widget',
            'product_code' => 'WDG-001',
            'product_cost' => 100,
            'product_price' => 150,
            'product_unit' => $this->pcsUnit->id,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
        ]);

        $purchase = Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $pd = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_price' => 100,
            'price' => 100,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'product_name' => 'Product Widget',
            'product_code' => 'WDG-001',
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'external_delivery_number' => 'DEL-PRESERVE-01',
            'date' => now()->toDateString(),
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);

        $rnd = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $pd->id,
            'product_id' => $product->id,
            'product_code' => 'WDG-001',
            'product_name' => 'Product Widget',
            'quantity_received' => 5,
            'location_id' => $this->location->id,
        ]);

        $this->assertEquals($pd->id, $rnd->po_detail_id);

        // Delete purchase detail row directly (as happens during full purchase edit)
        $pd->delete();

        // Reload received note detail
        $rnd->refresh();

        // The receiving detail must survive and its po_detail_id is set to NULL
        $this->assertNotNull($rnd->id);
        $this->assertNull($rnd->po_detail_id);
        $this->assertEquals('PRODUCT WIDGET', $rnd->display_product_name);
        $this->assertEquals('WDG-001', $rnd->display_product_code);
    }
}
