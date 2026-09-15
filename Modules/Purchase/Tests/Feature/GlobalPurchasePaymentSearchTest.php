<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class GlobalPurchasePaymentSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected Supplier $supplier;
    protected Supplier $supplier2;
    protected Unit $baseUnit;
    protected Unit $boxUnit;
    protected Category $category;
    protected Location $location;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting1 = Setting::factory()->create(['company_name' => 'Setting 1']);
        $this->setting2 = Setting::factory()->create(['company_name' => 'Setting 2']);
        session(['setting_id' => $this->setting1->id]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Mega Supplier Corp',
            'supplier_email' => 'mega@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Jakarta Barat',
            'setting_id' => $this->setting1->id,
        ]);

        $this->supplier2 = Supplier::create([
            'supplier_name' => 'Second Supplier Corp',
            'supplier_email' => 'second@example.com',
            'supplier_phone' => '87654321',
            'city' => 'Surabaya',
            'country' => 'Indonesia',
            'address' => 'Surabaya Timur',
            'setting_id' => $this->setting2->id,
        ]);

        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        \Spatie\Permission\Models\Permission::findOrCreate('purchases.received.correct', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.reporting-date.override', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.due-date.override', 'web');

        \Illuminate\Support\Facades\Gate::define('purchasePayments.global.access', fn() => true);

        $this->baseUnit = Unit::create([
            'name' => 'PIECE',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $this->setting1->id,
        ]);

        $this->boxUnit = Unit::create([
            'name' => 'BOX',
            'short_name' => 'BOX',
            'operator' => '*',
            'operation_value' => 10,
            'setting_id' => $this->setting1->id,
        ]);

        $this->category = Category::create([
            'category_name' => 'General Category',
            'category_code' => 'GEN',
            'setting_id' => $this->setting1->id,
            'created_by' => $this->user->id,
        ]);

        $this->location = Location::create([
            'name' => 'Central Warehouse',
            'setting_id' => $this->setting1->id,
        ]);
    }

    private function createPurchase(array $overrides = []): Purchase
    {
        return Purchase::create(array_merge([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'setting_id' => $this->setting1->id,
        ], $overrides));
    }

    private function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'product_name' => 'Alpha Keyboard Pro',
            'product_code' => 'AKP-001',
            'barcode' => 'BAR-AKP-12345',
            'setting_id' => $this->setting1->id,
            'product_quantity' => 50,
            'product_cost' => 50000,
            'product_price' => 75000,
            'category_id' => $this->category->id,
            'product_unit' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => true,
            'is_active' => true,
        ], $overrides));
    }

    private function createPurchaseDetail(Purchase $purchase, Product $product, array $overrides = []): PurchaseDetail
    {
        return PurchaseDetail::create(array_merge([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 100000,
            'price' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'sub_total' => 100000,
        ], $overrides));
    }

    public function test_cross_field_and_tokens_match_and_missing_token_excludes()
    {
        $product = $this->createProduct(['product_name' => 'Mechanical Wireless Keyboard']);
        
        $matchingPurchase = $this->createPurchase([
            'reference' => 'PO-TOKEN-MATCH-01',
            'note' => 'Fragile Delivery Batch',
        ]);
        $this->createPurchaseDetail($matchingPurchase, $product, [
            'product_name' => 'Snapshot Name',
        ]);

        $nonMatchingPurchase = $this->createPurchase([
            'reference' => 'PO-TOKEN-FAIL-02',
            'note' => 'Standard Delivery',
        ]);
        $this->createPurchaseDetail($nonMatchingPurchase, $product, [
            'product_name' => 'Snapshot Name',
        ]);

        // "Mega Wireless Fragile" -> token1 matches supplier (Mega), token2 matches product name (Wireless), token3 matches note (Fragile)
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'Mega Wireless Fragile')
            ->assertSee('PO-TOKEN-MATCH-01')
            ->assertDontSee('PO-TOKEN-FAIL-02');

        // Missing token "AbsentToken" should exclude all
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'Mega Wireless AbsentToken')
            ->assertDontSee('PO-TOKEN-MATCH-01')
            ->assertDontSee('PO-TOKEN-FAIL-02');
    }

    public function test_current_product_authority_and_snapshot_only_exclusion()
    {
        $product = $this->createProduct([
            'product_name' => 'Current Catalog Monitor 4K',
            'product_code' => 'MON-4K-CURR',
        ]);

        $purchase = $this->createPurchase(['reference' => 'PO-SNAP-TEST']);
        $this->createPurchaseDetail($purchase, $product, [
            'product_name' => 'Old Legacy Snapshot Name',
            'product_code' => 'OLD-SNAP-CODE',
        ]);

        // Search by current product name succeeds
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'Monitor 4K')
            ->assertSee('PO-SNAP-TEST');

        // Search by current product code succeeds
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'MON-4K-CURR')
            ->assertSee('PO-SNAP-TEST');

        // Search by snapshot name only fails
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'Old Legacy Snapshot')
            ->assertDontSee('PO-SNAP-TEST');

        // Search by snapshot code only fails
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'OLD-SNAP-CODE')
            ->assertDontSee('PO-SNAP-TEST');
    }

    public function test_inactive_linked_product_remains_discoverable()
    {
        $inactiveProduct = $this->createProduct([
            'product_name' => 'Discontinued Headset X',
            'is_active' => false,
        ]);

        $purchase = $this->createPurchase(['reference' => 'PO-INACTIVE-PROD']);
        $this->createPurchaseDetail($purchase, $inactiveProduct, [
            'product_name' => 'Snapshot Name',
        ]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'Discontinued Headset')
            ->assertSee('PO-INACTIVE-PROD');
    }

    public function test_exact_case_insensitive_primary_and_conversion_barcode_lookup()
    {
        $product = $this->createProduct([
            'barcode' => 'BarcodePrimary123',
        ]);

        ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 10,
            'barcode' => 'BarcodeBox999',
        ]);

        $purchase = $this->createPurchase(['reference' => 'PO-BARCODE-01']);
        $this->createPurchaseDetail($purchase, $product, [
            'product_name' => 'Product Name',
            'product_code' => 'CODE',
        ]);

        // Primary barcode exact match with mixed / lower casing
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'barcodeprimary123')
            ->assertSee('PO-BARCODE-01');

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'BARCODEPRIMARY123')
            ->assertSee('PO-BARCODE-01');

        // Conversion barcode exact match with mixed / lower casing
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'barcodebox999')
            ->assertSee('PO-BARCODE-01');

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'BARCODEBOX999')
            ->assertSee('PO-BARCODE-01');

        // Substring / partial barcode search must NOT match
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'BarcodePrimary')
            ->assertDontSee('PO-BARCODE-01');

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'BarcodeBox')
            ->assertDontSee('PO-BARCODE-01');
    }

    public function test_exact_serial_lineage_and_isolation_across_purchases()
    {
        $product = $this->createProduct();

        $purchaseWithSerial = $this->createPurchase(['reference' => 'PO-HAS-SERIAL']);
        $detail1 = $this->createPurchaseDetail($purchaseWithSerial, $product);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchaseWithSerial->id,
            'date' => now()->toDateString(),
            'status' => 'APPROVED',
        ]);

        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail1->id,
            'quantity_received' => 1,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-EXACT-PURCHASE-001',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        $receivedNoteDetail->productSerialNumbers()->attach($serial->id);

        // Another purchase with same product but without serial
        $purchaseWithoutSerial = $this->createPurchase(['reference' => 'PO-NO-SERIAL']);
        $this->createPurchaseDetail($purchaseWithoutSerial, $product);

        // Exact serial search matches only PO-HAS-SERIAL
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'sn-exact-purchase-001')
            ->assertSee('PO-HAS-SERIAL')
            ->assertDontSee('PO-NO-SERIAL');

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'SN-EXACT-PURCHASE-001')
            ->assertSee('PO-HAS-SERIAL')
            ->assertDontSee('PO-NO-SERIAL');

        // Partial serial search must NOT match
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'SN-EXACT-PURCHASE')
            ->assertDontSee('PO-HAS-SERIAL')
            ->assertDontSee('PO-NO-SERIAL');
    }

    public function test_legacy_receiving_detail_serial_fk_lineage()
    {
        $product = $this->createProduct();

        $legacyPurchase = $this->createPurchase(['reference' => 'PO-LEGACY-SERIAL']);
        $detail = $this->createPurchaseDetail($legacyPurchase, $product);

        $receivedNote = ReceivedNote::create([
            'po_id' => $legacyPurchase->id,
            'date' => now()->toDateString(),
            'status' => 'APPROVED',
        ]);

        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail->id,
            'quantity_received' => 1,
        ]);

        // Legacy FK directly on product_serial_numbers
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'received_note_detail_id' => $receivedNoteDetail->id,
            'serial_number' => 'SN-LEGACY-FK-999',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'sn-legacy-fk-999')
            ->assertSee('PO-LEGACY-SERIAL');
    }

    public function test_search_or_predicates_cannot_bypass_party_business_or_eligibility_constraints()
    {
        $product = $this->createProduct(['product_name' => 'Encrypted Switch 55']);

        // Purchase in Setting 1 (Supplier 1)
        $poSetting1 = $this->createPurchase([
            'reference' => 'PO-SETTING-1',
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
        ]);
        $this->createPurchaseDetail($poSetting1, $product);

        // Purchase in Setting 2 (Supplier 2)
        $poSetting2 = $this->createPurchase([
            'reference' => 'PO-SETTING-2',
            'setting_id' => $this->setting2->id,
            'supplier_id' => $this->supplier2->id,
            'status' => Purchase::STATUS_RECEIVED,
        ]);
        $this->createPurchaseDetail($poSetting2, $product);

        // Ineligible Draft purchase in Setting 1
        $ineligibleDraftPo = $this->createPurchase([
            'reference' => 'PO-INELIGIBLE-DRAFT',
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
        ]);
        $this->createPurchaseDetail($ineligibleDraftPo, $product);

        // 1. Search while filtering by Setting 1 only -> Setting 2 and Draft must NOT appear despite matching product
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, [
            'globalMode' => true,
            'globalBusinessFilters' => [$this->setting1->id],
        ])
            ->set('search', 'Encrypted Switch')
            ->assertSee('PO-SETTING-1')
            ->assertDontSee('PO-SETTING-2')
            ->assertDontSee('PO-INELIGIBLE-DRAFT');

        // 2. Search while embedded under Supplier 2 -> Setting 1 must NOT appear
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, [
            'globalMode' => true,
            'supplierId' => $this->supplier2->id,
        ])
            ->set('search', 'Encrypted Switch')
            ->assertSee('PO-SETTING-2')
            ->assertDontSee('PO-SETTING-1');
    }

    public function test_ordinary_non_global_purchase_list_search_remains_snapshot_based()
    {
        $product = $this->createProduct([
            'product_name' => 'Current Catalog Name',
        ]);

        $purchase = $this->createPurchase([
            'reference' => 'PO-ORDINARY-LIST',
            'setting_id' => $this->setting1->id,
        ]);
        $this->createPurchaseDetail($purchase, $product, [
            'product_name' => 'Persisted Snapshot Name',
            'product_code' => 'SNAP-001',
        ]);

        // In non-global mode ($globalMode = false), ordinary search matches the detail snapshot name
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, [
            'globalMode' => false,
            'settingId' => $this->setting1->id,
        ])
            ->set('search', 'Persisted Snapshot')
            ->assertSee('PO-ORDINARY-LIST');
    }
}
