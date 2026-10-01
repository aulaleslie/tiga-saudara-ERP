<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseReceivalCancellationEditGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected Setting $setting;
    protected Location $location;
    protected Supplier $supplier;
    protected Unit $unit;
    protected Tax $tax;
    protected Category $category;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Modules\Currency\Entities\Currency::firstOrCreate(
            ['id' => 1],
            [
                'currency_name' => 'Rupiah',
                'code' => 'IDR',
                'symbol' => 'Rp',
                'thousand_separator' => '.',
                'decimal_separator' => ',',
                'exchange_rate' => 1,
            ]
        );

        $this->setting = Setting::firstOrCreate(
            ['id' => 1],
            [
                'company_name' => 'Test Company',
                'company_email' => 'test@test.com',
                'company_phone' => '123456789',
                'company_address' => 'Test Address',
                'notification_email' => 'notify@test.com',
                'footer_text' => 'Footer',
                'default_currency_id' => 1,
                'default_currency_position' => 'prefix',
                'is_pkp' => true,
            ]
        );

        $this->location = Location::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Gudang Utama',
                'setting_id' => $this->setting->id,
            ]
        );

        $this->user = User::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Test User',
                'email' => 'test@test.com',
                'password' => bcrypt('secret'),
                'is_active' => 1,
            ]
        );

        $this->category = Category::firstOrCreate(
            ['category_code' => 'CAT01'],
            [
                'category_name' => 'Standard Category',
                'setting_id' => $this->setting->id,
                'created_by' => $this->user->id,
            ]
        );

        $this->unit = Unit::firstOrCreate(
            ['short_name' => 'PCS'],
            [
                'name' => 'Pieces',
                'operator' => '*',
                'operation_value' => 1,
                'setting_id' => $this->setting->id,
            ]
        );

        $this->tax = Tax::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'PPN 11%',
                'value' => 11,
            ]
        );

        $this->supplier = Supplier::create([
            'supplier_name' => 'PT Supplier Utama',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '0812345678',
            'address' => 'Jakarta',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'setting_id' => $this->setting->id,
        ]);

        Permission::findOrCreate('purchases.receive.cancel', 'web');
        Permission::findOrCreate('edit_purchases', 'web');
        Permission::findOrCreate('create_purchase_payments', 'web');

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
    }

    protected function createProduct(string $code, string $name): Product
    {
        $product = Product::create([
            'product_name' => $name,
            'product_code' => $code,
            'product_cost' => 100000,
            'product_price' => 120000,
            'product_unit' => $this->unit->short_name,
            'unit_id' => $this->unit->id,
            'product_quantity' => 0,
            'category_id' => $this->category->id,
            'setting_id' => $this->setting->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'price' => 120000,
            'cost' => 100000,
        ]);

        return $product;
    }

    protected function createProductStock(Product $product, float $qty, float $taxQty, float $nonTaxQty): ProductStock
    {
        return ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'setting_id' => $this->setting->id,
            'quantity' => $qty,
            'quantity_tax' => $taxQty,
            'quantity_non_tax' => $nonTaxQty,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
    }

    protected function createBuyTransaction(
        Product $product,
        float $qty,
        float $taxQty,
        float $nonTaxQty,
        string $reference,
        int $taxId
    ): Transaction {
        return Transaction::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'setting_id' => $this->setting->id,
            'type' => 'BUY',
            'quantity' => $qty,
            'quantity_tax' => $taxQty,
            'quantity_non_tax' => $nonTaxQty,
            'current_quantity' => $qty,
            'previous_quantity' => 0,
            'after_quantity' => $qty,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => $qty,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'tax_id' => $taxId,
            'reason' => "Purchase #{$reference}",
            'user_id' => $this->user->id,
            'unit_cost' => 100000,
        ]);
    }

    /**
     * Test 4.1: Locked full-edit guard rejects Purchases with pending receivals,
     * but allows Purchases with only CANCELLED history.
     */
    public function test_purchase_edit_and_update_guarded_against_pending_receivals(): void
    {
        Permission::findOrCreate('purchases.update', 'web');
        Permission::findOrCreate('purchases.approved.edit', 'web');
        $this->user->givePermissionTo(['purchases.update', 'purchases.approved.edit']);

        $product = $this->createProduct('PRD-EDIT-01', 'Edit Guard Product');
        $purchase = Purchase::create([
            'reference' => 'PO/2026/EDIT/001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_id' => $this->tax->id,
            'tax_percentage' => 11,
            'tax_amount' => 11000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 111000,
            'paid_amount' => 0,
            'due_amount' => 111000,
            'setting_id' => $this->setting->id,
        ]);

        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 11000,
            'tax_id' => $this->tax->id,
        ]);

        // Case A: Create a PENDING received note
        $pendingNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'status' => ReceivedNote::STATUS_PENDING,
            'setting_id' => $this->setting->id,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $pendingNote->id,
            'po_detail_id' => $poDetail->id,
            'product_id' => $product->id,
            'quantity_received' => 1,
            'location_id' => $this->location->id,
            'tax_id' => $this->tax->id,
        ]);

        // Edit route must abort 422 when pending receivals exist
        $response = $this->get(route('purchases.edit', $purchase->id));
        $response->assertStatus(422);

        // Update route must also abort 422
        $response = $this->patch(route('purchases.update', $purchase->id), [
            'date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'tax_id' => $this->tax->id,
        ]);
        $response->assertStatus(422);

        // Case B: Cancel the pending note (or set to CANCELLED)
        $pendingNote->update([
            'status' => ReceivedNote::STATUS_CANCELLED,
            'cancellation_reason' => 'Testing cancellation',
            'cancelled_at' => now(),
            'cancelled_by' => $this->user->id,
        ]);

        // Now edit route is allowed (status 200)
        $response = $this->get(route('purchases.edit', $purchase->id));
        $response->assertStatus(200);
    }

    /**
     * Test 4.2 & 4.3: HTTP preview and cancel endpoint tests with permissions,
     * and historical fallback display preservation.
     */
    public function test_cancellation_http_endpoints_and_history_snapshot_fallback(): void
    {
        $product = $this->createProduct('PRD-PREVIEW-01', 'Preview Snapshot Product');
        $this->createProductStock($product, 5, 5, 0);
        $product->update(['product_quantity' => 5]);

        $purchase = Purchase::create([
            'reference' => 'PO/2026/HTTP/001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_id' => $this->tax->id,
            'tax_percentage' => 11,
            'tax_amount' => 55000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 555000,
            'paid_amount' => 0,
            'due_amount' => 555000,
            'setting_id' => $this->setting->id,
        ]);

        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 500000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 55000,
            'tax_id' => $this->tax->id,
        ]);

        $approvedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
        ]);

        $rnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $approvedNote->id,
            'po_detail_id' => $poDetail->id,
            'product_id' => null, // simulate unlinked product to test fallback to snapshot
            'quantity_received' => 5,
            'location_id' => $this->location->id,
            'tax_id' => $this->tax->id,
            'product_name' => 'Historical Snapshot Name',
            'product_code' => 'HIST-01',
            'unit_name' => 'PCS',
        ]);

        $buyTrx = $this->createBuyTransaction($product, 5, 5, 0, $purchase->reference, $this->tax->id);
        $buyTrx->update(['received_note_detail_id' => $rnDetail->id]);
        $rnDetail->update(['buy_transaction_id' => $buyTrx->id]);

        // 1. Without permission, preview & cancel return 403
        $response = $this->get(route('purchases.receivings.cancel.preview', $purchase->id));
        $response->assertStatus(403);

        $response = $this->post(route('purchases.receivings.cancel', $purchase->id), [
            'reason' => 'Salah terima barang',
        ]);
        $response->assertStatus(403);

        // 2. Grant permission
        $this->user->givePermissionTo('purchases.receive.cancel');

        // 3. Preview returns JSON success with eligible = true
        $response = $this->getJson(route('purchases.receivings.cancel.preview', $purchase->id));
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'preview' => [
                'eligible' => true,
                'purchase' => [
                    'reference' => $purchase->reference,
                ],
            ],
        ]);

        // 4. Cancel without reason fails validation
        $response = $this->postJson(route('purchases.receivings.cancel', $purchase->id), [
            'reason' => 'a', // min:3
        ]);
        $response->assertStatus(422);

        // 5. Successful cancellation
        $response = $this->postJson(route('purchases.receivings.cancel', $purchase->id), [
            'reason' => 'Salah terima barang PO',
        ]);
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('received_notes', [
            'id' => $approvedNote->id,
            'status' => ReceivedNote::STATUS_CANCELLED,
            'cancellation_reason' => 'Salah terima barang PO',
        ]);

        // 6. Test historical snapshot fallback when PO detail is removed
        $poDetail->delete();
        $rnDetail->refresh();
        $this->assertNull($rnDetail->po_detail_id);
        $this->assertEquals('HISTORICAL SNAPSHOT NAME', $rnDetail->display_product_name);
        $this->assertEquals('HIST-01', $rnDetail->display_product_code);
    }

    /**
     * Test 4.4: End-to-end lifecycle: approve, cancel, edit purchase lines, receive again,
     * and verify cancelled note cannot be approved/rejected.
     */
    public function test_end_to_end_cancel_edit_and_receive_again(): void
    {
        $this->user->givePermissionTo(['purchases.receive.cancel', 'edit_purchases']);

        $productA = $this->createProduct('PRD-E2E-A', 'Product E2E A');
        $this->createProductStock($productA, 10, 10, 0);
        $productA->update(['product_quantity' => 10]);

        $purchase = Purchase::create([
            'reference' => 'PO/2026/E2E/001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_id' => $this->tax->id,
            'tax_percentage' => 11,
            'tax_amount' => 110000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1110000,
            'paid_amount' => 0,
            'due_amount' => 1110000,
            'setting_id' => $this->setting->id,
        ]);

        $poDetailA = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $productA->id,
            'product_name' => $productA->product_name,
            'product_code' => $productA->product_code,
            'quantity' => 10,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 1000000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 110000,
            'tax_id' => $this->tax->id,
        ]);

        $approvedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
        ]);

        $rnDetailA = ReceivedNoteDetail::create([
            'received_note_id' => $approvedNote->id,
            'po_detail_id' => $poDetailA->id,
            'product_id' => $productA->id,
            'quantity_received' => 10,
            'location_id' => $this->location->id,
            'tax_id' => $this->tax->id,
        ]);

        $buyTrx = $this->createBuyTransaction($productA, 10, 10, 0, $purchase->reference, $this->tax->id);
        $buyTrx->update(['received_note_detail_id' => $rnDetailA->id]);
        $rnDetailA->update(['buy_transaction_id' => $buyTrx->id]);

        // 1. Cancel the receival
        $response = $this->postJson(route('purchases.receivings.cancel', $purchase->id), [
            'reason' => 'Vendor salah kirim spesifikasi barang',
        ]);
        $response->assertStatus(200);

        $purchase->refresh();
        $this->assertEquals(Purchase::STATUS_APPROVED, $purchase->status);

        // Replay finds nothing left to cancel and has no effect
        $response = $this->postJson(route('purchases.receivings.cancel', $purchase->id), [
            'reason' => 'Try to cancel again',
        ]);
        $response->assertStatus(422);
        $this->assertEquals(1, \Modules\Purchase\Entities\ReceivedNoteCancellation::where('purchase_id', $purchase->id)->count());

        // 2. Edit purchase lines: replace Product A with Product B
        $productB = $this->createProduct('PRD-E2E-B', 'Product E2E B');
        $this->createProductStock($productB, 0, 0, 0);

        // Simulate deleting old details and adding new ones
        $poDetailA->delete();
        $poDetailB = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $productB->id,
            'product_name' => $productB->product_name,
            'product_code' => $productB->product_code,
            'quantity' => 5,
            'price' => 200000,
            'unit_price' => 200000,
            'sub_total' => 1000000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 110000,
            'tax_id' => $this->tax->id,
        ]);

        // 3. Verify cancelled note detail still exists with null po_detail_id
        $this->assertDatabaseHas('received_note_details', [
            'received_note_id' => $approvedNote->id,
            'po_detail_id' => null,
            'product_id' => $productA->id,
        ]);

        // 4. Create new receiving for Product B
        $newNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'status' => ReceivedNote::STATUS_PENDING,
            'setting_id' => $this->setting->id,
        ]);
        $newRnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $newNote->id,
            'po_detail_id' => $poDetailB->id,
            'product_id' => $productB->id,
            'quantity_received' => 5,
            'location_id' => $this->location->id,
            'tax_id' => $this->tax->id,
        ]);

        $this->assertEquals($poDetailB->id, $newRnDetail->po_detail_id);

        // 5. The cancelled receival stays cancelled; only the new receival is effective-to-be
        $this->assertEquals(ReceivedNote::STATUS_CANCELLED, $approvedNote->fresh()->status);
    }

    /**
     * Revision 6.3: the purchase-level action and the CANCELLED badge live on the Purchase detail page.
     */
    public function test_purchase_detail_page_offers_purchase_level_cancellation_and_renders_cancelled_badge(): void
    {
        // Every permission the detail view and its policies check must exist for Gate lookups
        foreach (require base_path('app/Config/Permissions.php') as $group) {
            foreach (array_keys($group) as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
        }
        $this->user->givePermissionTo(['purchases.show']);

        $product = $this->createProduct('PRD-SHOW-01', 'Show Page Product');
        $this->createProductStock($product, 3, 3, 0);
        $product->update(['product_quantity' => 3]);

        $purchase = Purchase::create([
            'reference' => 'PO/2026/SHOW/001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_id' => $this->tax->id,
            'tax_percentage' => 11,
            'tax_amount' => 33000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 333000,
            'paid_amount' => 0,
            'due_amount' => 333000,
            'setting_id' => $this->setting->id,
        ]);
        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 3,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 300000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 33000,
            'tax_id' => $this->tax->id,
        ]);
        $approvedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
        ]);
        $rnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $approvedNote->id,
            'po_detail_id' => $poDetail->id,
            'product_id' => $product->id,
            'quantity_received' => 3,
            'location_id' => $this->location->id,
            'tax_id' => $this->tax->id,
        ]);
        $buyTrx = $this->createBuyTransaction($product, 3, 3, 0, $purchase->reference, $this->tax->id);
        $buyTrx->update(['received_note_detail_id' => $rnDetail->id]);
        $rnDetail->update(['buy_transaction_id' => $buyTrx->id]);

        // Without the cancel permission the action is hidden
        $this->get(route('purchases.show', $purchase->id))
            ->assertOk()
            ->assertDontSee('cancelReceivalsModal', false);

        $this->user->givePermissionTo('purchases.receive.cancel');

        $this->get(route('purchases.show', $purchase->id))
            ->assertOk()
            ->assertSee('Batalkan Penerimaan')
            ->assertSee(route('purchases.receivings.cancel.preview', $purchase->id), false)
            ->assertSee('show.coreui.modal', false);

        $this->postJson(route('purchases.receivings.cancel', $purchase->id), ['reason' => 'Salah kirim barang'])
            ->assertOk();

        // After cancellation: badge rendered, nothing left to cancel so the action is gone
        $this->get(route('purchases.show', $purchase->id))
            ->assertOk()
            ->assertSee('Dibatalkan')
            ->assertSee('Salah kirim barang')
            ->assertDontSee('cancelReceivalsModal', false);
    }

    public function test_cancellation_endpoints_hide_database_errors(): void
    {
        $this->user->givePermissionTo('purchases.receive.cancel');
        $purchase = Purchase::create([
            'reference' => 'PO/2026/SQL/001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_id' => $this->tax->id,
            'tax_percentage' => 11,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);

        $queryException = new \Illuminate\Database\QueryException(
            'mysql',
            'select * from purchase_returns where purchase_id = ?',
            [$purchase->id],
            new \Exception("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'purchase_id'")
        );
        $eligibility = \Modules\Purchase\Services\PurchaseReceivalCancellationEligibilityService::class;
        $this->app->instance($eligibility, \Mockery::mock($eligibility, function ($mock) use ($queryException) {
            $mock->shouldReceive('preview')->andThrow($queryException);
        }));

        $reported = [];
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->reportable(function (\Throwable $e) use (&$reported) {
                $reported[] = $e;
            });

        $preview = $this->getJson(route('purchases.receivings.cancel.preview', $purchase->id));
        $preview->assertStatus(500)->assertJson(['success' => false]);
        $this->assertStringNotContainsString('purchase_id', $preview->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $preview->getContent());

        $cancel = $this->postJson(route('purchases.receivings.cancel', $purchase->id), ['reason' => 'Salah terima']);
        $cancel->assertStatus(500)->assertJson(['success' => false]);
        $this->assertStringNotContainsString('SQLSTATE', $cancel->getContent());
        $this->assertStringNotContainsString('purchase_returns', $cancel->getContent());

        // The underlying exception is still reported server-side
        $this->assertCount(2, $reported);
        $this->assertSame($queryException, $reported[0]);
    }

    private function registerAllConfiguredPermissions(): void
    {
        foreach (require base_path('app/Config/Permissions.php') as $group) {
            foreach (array_keys($group) as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
        }
    }

    /**
     * A Purchase with one fully reversible approved receival (3 units, durable BUY link).
     */
    private function createPurchaseWithApprovedReceival(string $reference): Purchase
    {
        $product = $this->createProduct('PRD-' . uniqid(), 'Header Edit Product');
        $this->createProductStock($product, 3, 3, 0);
        $product->update(['product_quantity' => 3]);

        $purchase = Purchase::create([
            'reference' => $reference,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_id' => $this->tax->id,
            'tax_percentage' => 11,
            'tax_amount' => 33000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 333000,
            'paid_amount' => 0,
            'due_amount' => 333000,
            'setting_id' => $this->setting->id,
        ]);
        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 3,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 300000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 33000,
            'tax_id' => $this->tax->id,
        ]);
        $note = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
        ]);
        $rnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $note->id,
            'po_detail_id' => $poDetail->id,
            'product_id' => $product->id,
            'quantity_received' => 3,
            'location_id' => $this->location->id,
            'tax_id' => $this->tax->id,
        ]);
        $buyTrx = $this->createBuyTransaction($product, 3, 3, 0, $purchase->reference, $this->tax->id);
        $buyTrx->update(['received_note_detail_id' => $rnDetail->id]);
        $rnDetail->update(['buy_transaction_id' => $buyTrx->id]);

        return $purchase;
    }

    public function test_header_edit_button_appears_after_purchase_level_cancellation(): void
    {
        $this->registerAllConfiguredPermissions();
        $this->user->givePermissionTo(['purchases.show', 'purchases.update', 'purchases.approved.edit', 'purchases.receive.cancel']);
        $purchase = $this->createPurchaseWithApprovedReceival('PO/2026/HDR/001');

        // RECEIVED without purchases.received.monetary.edit: no edit mode, no button
        $this->get(route('purchases.show', $purchase->id))
            ->assertOk()
            ->assertDontSee('purchase-header-edit', false);

        $this->postJson(route('purchases.receivings.cancel', $purchase->id), ['reason' => 'Salah kirim'])->assertOk();
        $this->assertEquals(Purchase::STATUS_APPROVED, $purchase->fresh()->status);

        // Reopened APPROVED purchase: full edit is available from the header
        $this->get(route('purchases.show', $purchase->id))
            ->assertOk()
            ->assertSee('id="purchase-header-edit"', false)
            ->assertSee(route('purchases.edit', $purchase->id), false)
            ->assertDontSee('Ubah Nilai (Moneter)');
    }

    public function test_header_edit_button_hidden_without_purchases_update(): void
    {
        $this->registerAllConfiguredPermissions();
        $this->user->givePermissionTo(['purchases.show', 'purchases.approved.edit']);
        $purchase = $this->createPurchaseWithApprovedReceival('PO/2026/HDR/002');
        $purchase->receivedNotes()->update(['status' => ReceivedNote::STATUS_CANCELLED]);
        $purchase->update(['status' => Purchase::STATUS_APPROVED]);

        $this->get(route('purchases.show', $purchase->id))
            ->assertOk()
            ->assertDontSee('purchase-header-edit', false);
    }

    public function test_header_edit_button_hidden_for_approved_purchase_without_approved_edit(): void
    {
        $this->registerAllConfiguredPermissions();
        $this->user->givePermissionTo(['purchases.show', 'purchases.update']);
        $purchase = $this->createPurchaseWithApprovedReceival('PO/2026/HDR/003');
        $purchase->receivedNotes()->update(['status' => ReceivedNote::STATUS_CANCELLED]);
        $purchase->update(['status' => Purchase::STATUS_APPROVED]);

        $this->get(route('purchases.show', $purchase->id))
            ->assertOk()
            ->assertDontSee('purchase-header-edit', false);
    }

    public function test_header_full_edit_button_hidden_while_pending_receival_exists(): void
    {
        $this->registerAllConfiguredPermissions();
        $this->user->givePermissionTo(['purchases.show', 'purchases.update', 'purchases.approved.edit']);
        $purchase = $this->createPurchaseWithApprovedReceival('PO/2026/HDR/004');
        $purchase->receivedNotes()->update(['status' => ReceivedNote::STATUS_PENDING]);
        $purchase->update(['status' => Purchase::STATUS_APPROVED]);

        $this->get(route('purchases.show', $purchase->id))
            ->assertOk()
            ->assertDontSee('purchase-header-edit', false);

        // Once the pending receival is gone the same user gets the button
        $purchase->receivedNotes()->update(['status' => ReceivedNote::STATUS_CANCELLED]);
        $this->get(route('purchases.show', $purchase->id))
            ->assertOk()
            ->assertSee('id="purchase-header-edit"', false);
    }

    public function test_header_edit_button_shows_monetary_label_and_global_mode_keeps_its_own_action(): void
    {
        $this->registerAllConfiguredPermissions();
        $this->user->givePermissionTo([
            'purchases.show', 'purchases.update', 'purchases.received.monetary.edit', 'purchasePayments.global.access',
        ]);
        $purchase = $this->createPurchaseWithApprovedReceival('PO/2026/HDR/005');

        // RECEIVED with monetary-edit permission: header offers the monetary edit
        $this->get(route('purchases.show', $purchase->id))
            ->assertOk()
            ->assertSee('id="purchase-header-edit"', false)
            ->assertSee('Ubah Nilai (Moneter)');

        // Global-payment mode: no header edit button, the separate monetary-edit action remains
        $this->get(route('purchases.global-payments.show', $purchase->id))
            ->assertOk()
            ->assertDontSee('purchase-header-edit', false)
            ->assertSee(route('purchases.global-payments.edit-monetary', $purchase->id), false);
    }
}
