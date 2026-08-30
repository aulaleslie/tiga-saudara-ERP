<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\DataTables\PurchaseReceivingsDataTable;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Entities\UomNormalizationBatch;
use Modules\Purchase\Entities\UomNormalizationLine;
use Modules\Purchase\Services\UomNormalizationExecutionService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseLazyLoadingPreventionTest extends TestCase
{
    use RefreshDatabase;

    private bool $originalPreventsLazyLoading;

    protected User $user;
    protected Setting $setting;
    protected Location $location;
    protected Supplier $supplier;
    protected Unit $unitDus;
    protected Unit $unitPcs;
    protected Unit $unitBtl;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPreventsLazyLoading = Model::preventsLazyLoading();
        Model::preventLazyLoading(true);

        $currency = Currency::firstOrCreate(
            ['code' => 'IDR'],
            [
                'currency_name' => 'Rupiah',
                'symbol' => 'Rp',
                'thousand_separator' => '.',
                'decimal_separator' => ',',
                'exchange_rate' => 1,
            ]
        );

        $this->setting = Setting::create([
            'company_name' => 'TIGA SAUDARA TEST',
            'company_email' => 'test@tigasaudara.com',
            'company_phone' => '08123456789',
            'company_address' => 'Jl. Test No. 1',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@tigasaudara.com',
            'footer_text' => 'Test Footer',
        ]);

        session(['setting_id' => $this->setting->id]);

        $this->location = Location::create([
            'name' => 'Gudang Utama Test',
            'setting_id' => $this->setting->id,
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier Test',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '08987654321',
            'address' => 'Jl. Supplier Test',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'setting_id' => $this->setting->id,
        ]);

        $this->unitDus = Unit::create([
            'name' => 'DUS',
            'short_name' => 'DUS',
            'operator' => '*',
            'operation_value' => 10,
            'setting_id' => $this->setting->id,
        ]);

        $this->unitPcs = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $this->unitBtl = Unit::create([
            'name' => 'BOTOL',
            'short_name' => 'BTL',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $category = Category::create([
            'category_code' => 'CAT-TEST',
            'category_name' => 'Category Test',
            'setting_id' => $this->setting->id,
            'created_by' => 1,
        ]);

        $this->product = Product::forceCreate([
            'product_name' => 'Product UOM Test',
            'product_code' => 'PROD-UOM-001',
            'product_quantity' => 0,
            'product_price' => 20000,
            'product_cost' => 10000,
            'product_unit' => 'PCS',
            'category_id' => $category->id,
            'unit_id' => $this->unitPcs->id,
            'base_unit_id' => $this->unitPcs->id,
            'setting_id' => $this->setting->id,
            'stock_managed' => true,
        ]);

        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 20000,
            'average_purchase_price' => 10000,
            'last_purchase_price' => 10000,
        ]);

        // Permissions
        Permission::findOrCreate('purchases.show', 'web');
        Permission::findOrCreate('purchases.access', 'web');
        Permission::findOrCreate('purchases.receive.access', 'web');
        Permission::findOrCreate('purchasePayments.global.access', 'web');

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $role->givePermissionTo([
            'purchases.show',
            'purchases.access',
            'purchases.receive.access',
            'purchasePayments.global.access',
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading($this->originalPreventsLazyLoading);

        parent::tearDown();
    }

    /**
     * Helper to create a purchase with receipt and normalize it using UomNormalizationExecutionService.
     */
    private function createPurchaseWithExecutedNormalization(bool $asLegacy = false): array
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => 'Test PO',
            'setting_id' => $this->setting->id,
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'unit_price' => 10000,
            'price' => 10000,
            'sub_total' => 100000,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->format('Y-m-d'),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now(),
            'external_delivery_number' => 'DEL-001',
            'internal_invoice_number' => 'INV-001',
        ]);

        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        $this->product->update(['product_quantity' => 10]);

        $tx = Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'quantity' => 10,
            'current_quantity' => 10,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
            'reason' => 'Diterima dari Pembelian',
            'type' => 'BUY',
            'previous_quantity' => 0,
            'after_quantity' => 10,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'received_note_detail_id' => $receivedNoteDetail->id,
        ]);

        $service = app(UomNormalizationExecutionService::class);
        $result = $service->execute(
            $this->product,
            $this->unitDus,
            10.0,
            collect([$purchaseDetail->id]),
            $this->user,
            $this->setting->id,
            'UOM Normalization execution'
        );

        $this->assertTrue($result['success']);
        /** @var UomNormalizationBatch $batch */
        $batch = $result['batch'];

        if ($asLegacy) {
            // Transform batch to legacy format (old_base_unit_id is null, legacyBaseUnit is set)
            $conversion = ProductUnitConversion::create([
                'product_id' => $this->product->id,
                'unit_id' => $this->unitDus->id,
                'base_unit_id' => $this->unitBtl->id,
                'conversion_factor' => 10,
            ]);

            \Illuminate\Support\Facades\DB::table('uom_normalization_batches')
                ->where('id', $batch->id)
                ->update([
                    'old_base_unit_id' => null,
                    'new_base_unit_id' => null,
                    'old_primary_unit_id' => null,
                    'new_primary_unit_id' => null,
                    'product_unit_conversion_id' => $conversion->id,
                    'source_unit_id' => $this->unitDus->id,
                    'base_unit_id' => $this->unitBtl->id,
                ]);
            $batch->refresh();
        }

        return [$purchase, $purchaseDetail, $receivedNote, $receivedNoteDetail, $batch];
    }

    /**
     * Test 1: Standard purchases.show with current-format normalization batch (lazy loading disabled).
     */
    public function test_standard_purchase_show_with_current_normalization_batch_does_not_lazy_load(): void
    {
        [$purchase] = $this->createPurchaseWithExecutedNormalization(asLegacy: false);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        $response->assertViewIs('purchase::show');
        $response->assertSee('Riwayat Normalisasi UOM');
        $response->assertSee('Base UOM corrected from');
    }

    /**
     * Test 2: Standard purchases.show with legacy-format normalization batch (lazy loading disabled).
     */
    public function test_standard_purchase_show_with_legacy_normalization_batch_does_not_lazy_load(): void
    {
        [$purchase] = $this->createPurchaseWithExecutedNormalization(asLegacy: true);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        $response->assertViewIs('purchase::show');
        $response->assertSee('Riwayat Normalisasi UOM');
        $response->assertSee('legacy record');
    }

    /**
     * Test 3: Global purchase-payment show with normalization history (lazy loading disabled).
     */
    public function test_global_purchase_payment_show_with_normalization_history_does_not_lazy_load(): void
    {
        [$purchase] = $this->createPurchaseWithExecutedNormalization(asLegacy: false);

        $otherSetting = Setting::create([
            'company_name' => 'SETTING GLOBAL VIEWER',
            'company_email' => 'viewer@test.com',
            'company_phone' => '0811111111',
            'company_address' => 'Jl. Viewer No. 2',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'viewer@test.com',
            'footer_text' => 'Footer Viewer',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $otherSetting->id])
            ->get(route('purchases.global-payments.show', $purchase->id));

        $response->assertStatus(200);
        $response->assertViewIs('purchase::show');
        $response->assertViewHas('globalMode', true);
        $response->assertSee('Riwayat Normalisasi UOM');
        $response->assertSee('Base UOM corrected from');
    }

    /**
     * Test 4: Purchase showReceivings receiving-details page with normalization lines (lazy loading disabled).
     */
    public function test_purchase_show_receivings_with_normalization_lines_does_not_lazy_load(): void
    {
        [$purchase] = $this->createPurchaseWithExecutedNormalization(asLegacy: false);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchases.receivings', $purchase->id));

        $response->assertStatus(200);
        $response->assertViewIs('purchase::receivings.index');
    }

    /**
     * Test 5: PurchaseReceivingsDataTable detail rendering with normalization lines (lazy loading disabled).
     */
    public function test_purchase_receivings_datatable_detail_rendering_does_not_lazy_load(): void
    {
        [$purchase] = $this->createPurchaseWithExecutedNormalization(asLegacy: false);

        // Bind route parameter so byPurchase scope resolves correctly
        $route = new \Illuminate\Routing\Route('GET', 'purchases/receivings/{purchase_id}', []);
        $route->bind(request());
        $route->setParameter('purchase_id', $purchase->id);
        request()->setRouteResolver(fn () => $route);

        $dataTable = new PurchaseReceivingsDataTable();

        // Simulate query execution like Yajra DataTable does
        $query = $dataTable->query(new ReceivedNote());
        $receivedNotes = $query->get();

        $this->assertNotEmpty($receivedNotes);

        // Render the receiving-details partial for each row in the DataTable
        foreach ($receivedNotes as $data) {
            $html = view('purchase::receivings.receiving-details', compact('data'))->render();
            $this->assertStringContainsString('Base UOM corrected from', $html);
            $this->assertStringContainsString('DUS', $html);
        }
    }

    /**
     * Test 6: Global purchase-payment show with consignment billing purchase (lazy loading disabled).
     */
    public function test_global_purchase_payment_show_with_consignment_billing_purchase_does_not_lazy_load(): void
    {
        [$purchase, $purchaseDetail] = $this->createPurchaseWithExecutedNormalization(asLegacy: false);

        $purchase->update([
            'source_type' => Purchase::SOURCE_CONSIGNMENT_BILLING,
        ]);

        $confirmation = \Modules\Consignment\Entities\ConsignmentBillingConfirmation::forceCreate([
            'purchase_id' => $purchase->id,
            'confirmation_number' => 'CBC-TEST-001',
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting->id,
            'status' => 'APPROVED',
            'billed_at' => now(),
            'date' => now(),
        ]);

        $customer = \Modules\People\Entities\Customer::forceCreate([
            'customer_name' => 'Customer Test',
            'customer_email' => 'cust@test.com',
            'customer_phone' => '0812345678',
            'setting_id' => $this->setting->id,
        ]);

        $sale = \Modules\Sale\Entities\Sale::forceCreate([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $saleDetail = \Modules\Sale\Entities\SaleDetails::forceCreate([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 100000,
            'product_discount_type' => 'Fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::forceCreate([
            'sale_id' => $sale->id,
            'location_id' => $this->location->id,
            'status' => 'APPROVED',
        ]);

        $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::forceCreate([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'product_id' => $this->product->id,
            'dispatched_quantity' => 10,
        ]);

        $soldSource = \Modules\Consignment\Entities\ConsignmentSoldSource::forceCreate([
            'setting_id' => $this->setting->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 10,
            'dispatched_at' => now(),
            'source_hash' => 'hash_test_123',
            'source_snapshot' => [],
        ]);

        $confirmationLine = \Modules\Consignment\Entities\ConsignmentBillingConfirmationLine::forceCreate([
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_sold_source_id' => $soldSource->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 10,
        ]);

        $consignmentReceival = \Modules\Consignment\Entities\ConsignmentReceival::forceCreate([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-' . uniqid(),
            'date' => now()->format('Y-m-d'),
            'status' => 'APPROVED',
        ]);

        $consignmentReceiving = \Modules\Consignment\Entities\ConsignmentReceiving::forceCreate([
            'consignment_receival_id' => $consignmentReceival->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'receiving_number' => 'REC-001',
            'status' => 'APPROVED',
            'date' => now()->format('Y-m-d'),
        ]);

        $receivalLine = \Modules\Consignment\Entities\ConsignmentReceivalLine::forceCreate([
            'consignment_receival_id' => $consignmentReceival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'unit_cost' => 10000,
            'unit_dpp' => 10000,
            'subtotal_cost' => 100000,
            'total_cost' => 100000,
        ]);

        $consignmentReceivingDetail = \Modules\Consignment\Entities\ConsignmentReceivingDetail::forceCreate([
            'consignment_receiving_id' => $consignmentReceiving->id,
            'consignment_receival_line_id' => $receivalLine->id,
            'product_id' => $this->product->id,
            'quantity_received' => 10,
            'unit_cost' => 10000,
            'unit_dpp' => 10000,
            'tax_rate' => 0,
            'tax_amount' => 0,
        ]);

        \Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage::forceCreate([
            'setting_id' => $this->setting->id,
            'purchase_id' => $purchase->id,
            'purchase_detail_id' => $purchaseDetail->id,
            'product_id' => $this->product->id,
            'unit_cost' => 10000,
            'unit_dpp' => 10000,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_billing_confirmation_line_id' => $confirmationLine->id,
            'consignment_receiving_detail_id' => $consignmentReceivingDetail->id,
            'billed_base_quantity' => 10,
        ]);

        $otherSetting = Setting::create([
            'company_name' => 'SETTING GLOBAL CONSIGNMENT VIEWER',
            'company_email' => 'consignviewer@test.com',
            'company_phone' => '0811111112',
            'company_address' => 'Jl. Consign Viewer No. 3',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'consignviewer@test.com',
            'footer_text' => 'Footer Consign Viewer',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $otherSetting->id])
            ->get(route('purchases.global-payments.show', $purchase->id));

        $response->assertStatus(200);
        $response->assertViewIs('purchase::show');
        $response->assertViewHas('globalMode', true);
        $response->assertSee('#CBC-TEST-001');
        $response->assertSee('Asal Konsinyasi');
    }
}
