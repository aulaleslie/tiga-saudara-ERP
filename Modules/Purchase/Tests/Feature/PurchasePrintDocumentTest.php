<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchasePrintDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $settingA;
    protected Setting $settingB;
    protected User $user;
    protected Supplier $supplier;
    protected Location $location;
    protected Unit $pcsUnit;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPermissions([
            'purchases.show',
            'purchases.access',
            'purchasePayments.global.access',
        ]);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
        ]);

        $this->settingA = Setting::create([
            'company_name' => 'Company A',
            'company_email' => 'compA@example.com',
            'company_phone' => '0811111111',
            'company_address' => 'Address A',
            'notification_email' => 'notifyA@example.com',
            'footer_text' => 'Footer A',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Company B',
            'company_email' => 'compB@example.com',
            'company_phone' => '0822222222',
            'company_address' => 'Address B',
            'notification_email' => 'notifyB@example.com',
            'footer_text' => 'Footer B',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier One',
            'supplier_email' => 'supplier1@example.com',
            'supplier_phone' => '0833333333',
            'city' => 'City',
            'country' => 'ID',
            'address' => 'Supplier Address',
            'setting_id' => $this->settingA->id,
        ]);

        $this->location = Location::create([
            'name' => 'Main Loc',
            'setting_id' => $this->settingA->id,
        ]);

        $this->pcsUnit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'pcs',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->user = User::factory()->create();

        Category::create([
            'id' => 1,
            'category_code' => 'CAT001',
            'category_name' => 'General',
            'setting_id' => $this->settingA->id,
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Purchase Test Product',
            'product_code' => 'PTP-01',
            'product_unit' => 'pc',
            'product_cost' => 50000,
            'product_price' => 100000,
            'product_quantity' => 10,
            'setting_id' => $this->settingA->id,
            'category_id' => 1,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
        ]);
    }

    protected function seedPermissions(array $permissions): void
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    protected function createPurchase(array $overrides = []): Purchase
    {
        $settingId = $overrides['setting_id'] ?? $this->settingA->id;

        return Purchase::create(array_merge([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'tax_percentage' => 11,
            'tax_amount' => 11000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 5000,
            'total_amount' => 116000,
            'paid_amount' => 50000,
            'due_amount' => 66000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'setting_id' => $settingId,
            'is_tax_included' => false,
        ], $overrides));
    }

    public function test_default_pdf_route_streams_and_unknown_variant_rejected(): void
    {
        $this->user->givePermissionTo('purchases.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        session(['setting_id' => $this->settingA->id]);

        $purchase = $this->createPurchase();

        // 1. Default streaming
        $response = $this->actingAs($this->user)->get(route('purchases.pdf', $purchase->id));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        // 2. Unknown variant is rejected with 400
        $badResponse = $this->actingAs($this->user)->get(route('purchases.pdf', [
            'id' => $purchase->id,
            'type' => 'unknown_variant_xyz',
        ]));
        $badResponse->assertStatus(400);
    }

    public function test_user_without_show_permission_cannot_print(): void
    {
        // User assigned to business but lacks purchases.show
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        session(['setting_id' => $this->settingA->id]);

        $purchase = $this->createPurchase();

        $response = $this->actingAs($this->user)->get(route('purchases.pdf', $purchase->id));
        $response->assertStatus(403);
    }

    public function test_user_cannot_print_inaccessible_business_document(): void
    {
        $this->user->givePermissionTo('purchases.show');
        // User assigned only to setting A
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        session(['setting_id' => $this->settingA->id]);

        // Purchase belongs to setting B
        $purchaseB = $this->createPurchase(['setting_id' => $this->settingB->id]);

        $response = $this->actingAs($this->user)->get(route('purchases.pdf', $purchaseB->id));
        $response->assertStatus(403);
    }

    public function test_authorized_cross_business_user_can_print_without_session_switch(): void
    {
        $this->user->givePermissionTo(['purchases.show', 'purchasePayments.global.access']);
        // User is assigned to both A and B
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        $this->user->settings()->attach($this->settingB->id, ['role_id' => 1]);

        // Active session is A
        session(['setting_id' => $this->settingA->id]);

        // Purchase belongs to B
        $purchaseB = $this->createPurchase(['setting_id' => $this->settingB->id]);

        $response = $this->actingAs($this->user)->get(route('purchases.pdf', $purchaseB->id));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        // Session setting must remain unchanged
        $this->assertEquals($this->settingA->id, session('setting_id'));
    }

    public function test_presentation_uses_owning_business_identity_and_pkp_rules(): void
    {
        $this->user->givePermissionTo('purchases.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        $this->user->settings()->attach($this->settingB->id, ['role_id' => 1]);

        $service = app(\Modules\Purchase\Services\PurchasePrintDocumentService::class);

        // Document for PKP business A
        $purchaseA = $this->createPurchase(['setting_id' => $this->settingA->id]);
        $payloadA = $service->buildPresentationPayload($purchaseA, 'purchase');
        $this->assertSame('COMPANY A', $payloadA['company']['name']);
        $this->assertTrue($payloadA['isPkp']);
        $this->assertTrue($payloadA['showSettlement']);
        $this->assertSame('PEMBELIAN', $payloadA['documentTitle']);

        // Document for non-PKP business B
        $purchaseB = $this->createPurchase(['setting_id' => $this->settingB->id]);
        $payloadB = $service->buildPresentationPayload($purchaseB, 'order');
        $this->assertSame('COMPANY B', $payloadB['company']['name']);
        $this->assertFalse($payloadB['isPkp']);
        $this->assertFalse($payloadB['showSettlement']);
        $this->assertSame('PESANAN PEMBELIAN', $payloadB['documentTitle']);
        // Non-PKP hides DPP and tax
        $this->assertEquals(0.0, $payloadB['totals']['dpp']);
        $this->assertEquals(0.0, $payloadB['totals']['tax_amount']);
    }

    public function test_archived_purchase_remains_printable_with_archived_flag(): void
    {
        $this->user->givePermissionTo('purchases.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        session(['setting_id' => $this->settingA->id]);

        $purchase = $this->createPurchase(['archived_at' => now()]);

        $response = $this->actingAs($this->user)->get(route('purchases.pdf', $purchase->id));
        $response->assertOk();

        $service = app(\Modules\Purchase\Services\PurchasePrintDocumentService::class);
        $payload = $service->buildPresentationPayload($purchase, 'purchase');
        $this->assertTrue($payload['isArchived']);
    }

    public function test_commercial_print_endpoints_work_across_all_statuses(): void
    {
        $this->user->givePermissionTo('purchases.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        session(['setting_id' => $this->settingA->id]);

        $statuses = [
            Purchase::STATUS_DRAFTED,
            Purchase::STATUS_WAITING_APPROVAL,
            Purchase::STATUS_APPROVED,
            Purchase::STATUS_REJECTED,
        ];

        foreach ($statuses as $status) {
            $purchase = $this->createPurchase(['status' => $status]);

            // Test purchase variant
            $resPurchase = $this->actingAs($this->user)->get(route('purchases.pdf', $purchase->id));
            $resPurchase->assertOk();
            $this->assertSame('application/pdf', $resPurchase->headers->get('Content-Type'));

            // Test order variant
            $resOrder = $this->actingAs($this->user)->get(route('purchases.orderPdf', $purchase->id));
            $resOrder->assertOk();
            $this->assertSame('application/pdf', $resOrder->headers->get('Content-Type'));
        }

        // Test archived
        $archivedPurchase = $this->createPurchase(['archived_at' => now(), 'status' => Purchase::STATUS_APPROVED]);
        $this->actingAs($this->user)->get(route('purchases.pdf', $archivedPurchase->id))->assertOk();
        $this->actingAs($this->user)->get(route('purchases.orderPdf', $archivedPurchase->id))->assertOk();
    }

    public function test_tax_exclusive_entry_calculates_dpp_and_no_false_reconciliation_difference(): void
    {
        $this->user->givePermissionTo('purchases.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);

        $purchase = $this->createPurchase([
            'setting_id' => $this->settingA->id,
            'total_amount' => 111000,
            'shipping_amount' => 0,
            'is_tax_included' => false,
            'tax_amount' => 11000,
        ]);

        $purchase->purchaseDetails()->delete();
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Purchase Taxed Item',
            'product_code' => 'PTI-01',
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 111000,
            'product_tax_amount' => 11000,
            'product_discount_amount' => 0,
        ]);

        $service = app(\Modules\Purchase\Services\PurchasePrintDocumentService::class);
        $payload = $service->buildPresentationPayload($purchase, 'purchase');

        $this->assertEquals(100000.0, $payload['totals']['dpp']);
        $this->assertEquals(11000.0, $payload['totals']['tax_amount']);
        $this->assertEquals(0.0, $payload['totals']['reconciliation_diff']);
    }

    public function test_single_rate_document_prints_applicable_rate(): void
    {
        $this->user->givePermissionTo('purchases.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);

        $purchase = $this->createPurchase([
            'setting_id' => $this->settingA->id,
            'total_amount' => 111000,
            'shipping_amount' => 0,
            'tax_percentage' => 0,
            'tax_amount' => 11000,
        ]);

        $purchase->purchaseDetails()->delete();
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Purchase Rate Test Item',
            'product_code' => 'PRTI-01',
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 111000,
            'product_tax_amount' => 11000,
            'product_discount_amount' => 0,
        ]);

        $service = app(\Modules\Purchase\Services\PurchasePrintDocumentService::class);
        $payload = $service->buildPresentationPayload($purchase, 'purchase');

        $this->assertCount(1, $payload['totals']['tax_by_rate']);
        $this->assertEquals(11.0, $payload['totals']['tax_by_rate'][0]['rate']);

        $rendered = view('purchase::print', $payload)->render();
        $this->assertStringContainsString('PPN (11%)', $rendered);
        $this->assertStringContainsString('11.000', $rendered);
    }

    public function test_editing_tax_master_does_not_alter_historical_print_rate(): void
    {
        $this->user->givePermissionTo('purchases.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);

        $tax = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
            'is_default' => true,
        ]);

        $purchase = $this->createPurchase([
            'setting_id' => $this->settingA->id,
            'total_amount' => 111000,
            'shipping_amount' => 0,
            'tax_amount' => 11000,
            'tax_percentage' => 0,
        ]);

        $purchase->purchaseDetails()->delete();
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Purchase Master Edit Test',
            'product_code' => 'PMET-01',
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 111000,
            'product_tax_amount' => 11000,
            'tax_id' => $tax->id,
            'product_discount_amount' => 0,
        ]);

        // Edit tax master to 11.2% in the database (reproducing the mutable master test case)
        DB::table('taxes')->where('id', $tax->id)->update(['value' => 11.2]);

        $service = app(\Modules\Purchase\Services\PurchasePrintDocumentService::class);
        $payload = $service->buildPresentationPayload($purchase, 'purchase');

        // Rate must remain 11% derived from historical recorded amounts
        $this->assertEquals(11.0, $payload['totals']['tax_by_rate'][0]['rate']);

        $rendered = view('purchase::print', $payload)->render();
        $this->assertStringContainsString('PPN (11%)', $rendered);
        $this->assertStringNotContainsString('PPN (11.2%)', $rendered);
        $this->assertStringNotContainsString('11.2', $rendered);
    }

    public function test_recorded_tax_reference_prints_on_pkp_and_hidden_on_non_pkp(): void
    {
        $this->user->givePermissionTo('purchases.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        $this->user->settings()->attach($this->settingB->id, ['role_id' => 1]);

        $service = app(\Modules\Purchase\Services\PurchasePrintDocumentService::class);

        // 1. PKP purchase with tax_ref_no
        $purchasePkp = $this->createPurchase([
            'setting_id' => $this->settingA->id,
            'tax_ref_no' => '010.000-26.77776666',
        ]);
        $payloadPkp = $service->buildPresentationPayload($purchasePkp, 'purchase');
        $renderedPkp = view('purchase::print', $payloadPkp)->render();

        $this->assertStringContainsString('No. Faktur Pajak', $renderedPkp);
        $this->assertStringContainsString('010.000-26.77776666', $renderedPkp);

        // 2. Non-PKP purchase with tax_ref_no (must be hidden)
        $purchaseNonPkp = $this->createPurchase([
            'setting_id' => $this->settingB->id,
            'tax_ref_no' => '010.000-26.77776666',
        ]);
        $payloadNonPkp = $service->buildPresentationPayload($purchaseNonPkp, 'purchase');
        $renderedNonPkp = view('purchase::print', $payloadNonPkp)->render();

        $this->assertStringNotContainsString('No. Faktur Pajak', $renderedNonPkp);
        $this->assertStringNotContainsString('010.000-26.77776666', $renderedNonPkp);
    }

    public function test_non_pkp_copies_display_tax_inclusive_unit_prices_for_tax_exclusive_rows(): void
    {
        $this->user->givePermissionTo('purchases.show');
        $this->user->settings()->attach($this->settingB->id, ['role_id' => 1]);

        $purchase = $this->createPurchase([
            'setting_id' => $this->settingB->id, // Non-PKP business B
            'total_amount' => 111000,
            'shipping_amount' => 0,
            'is_tax_included' => false,
            'tax_amount' => 11000,
        ]);

        $purchase->purchaseDetails()->delete();
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Purchase Non PKP Taxed Item',
            'product_code' => 'PNPTI-01',
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 111000,
            'product_tax_amount' => 11000,
            'product_discount_amount' => 0,
        ]);

        $service = app(\Modules\Purchase\Services\PurchasePrintDocumentService::class);
        $payload = $service->buildPresentationPayload($purchase, 'order');

        $this->assertFalse($payload['isPkp']);
        // Display unit price must be grossed up to 111,000 for Non-PKP presentation
        $this->assertEquals(111000.0, $payload['details'][0]['entered_unit_price']);
        $this->assertEquals(111000.0, $payload['details'][0]['sub_total']);

        // Saved DB records must remain untouched
        $this->assertEquals(100000.0, (float) $detail->fresh()->unit_price);
        $this->assertEquals(111000.0, (float) $detail->fresh()->sub_total);

        // Rendered view must show 111.000 under unit price
        $rendered = view('purchase::print', $payload)->render();
        $this->assertStringContainsString('111.000', $rendered);
    }

    public function test_non_pkp_copies_display_grossed_up_discounts_to_reconcile_with_grossed_up_prices(): void
    {
        $this->user->givePermissionTo('purchases.show');
        $this->user->settings()->attach($this->settingB->id, ['role_id' => 1]);

        $purchase = $this->createPurchase([
            'setting_id' => $this->settingB->id, // Non-PKP business B
            'total_amount' => 99900,
            'shipping_amount' => 0,
            'is_tax_included' => false,
            'tax_amount' => 9900,
        ]);

        $purchase->purchaseDetails()->delete();
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Discounted Taxed Item',
            'product_code' => 'DTI-01',
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 99900,
            'product_tax_amount' => 9900,
            'product_discount_amount' => 10000,
            'product_discount_type' => 'fixed',
        ]);

        $service = app(\Modules\Purchase\Services\PurchasePrintDocumentService::class);

        // 1. Verify Pembelian (purchase variant)
        $payloadPurchase = $service->buildPresentationPayload($purchase, 'purchase');
        $this->assertFalse($payloadPurchase['isPkp']);
        $this->assertEquals(111000.0, $payloadPurchase['details'][0]['entered_unit_price']);
        $this->assertEquals(111000.0, $payloadPurchase['details'][0]['display_unit_price'] ?? $payloadPurchase['details'][0]['entered_unit_price']);
        $this->assertEquals(11100.0, $payloadPurchase['details'][0]['discount_amount']);
        $this->assertEquals(99900.0, $payloadPurchase['details'][0]['sub_total']);

        $renderedPurchase = view('purchase::print', $payloadPurchase)->render();
        $this->assertStringContainsString('111.000', $renderedPurchase);
        $this->assertStringContainsString('11.100', $renderedPurchase);
        $this->assertStringContainsString('99.900', $renderedPurchase);

        // 2. Verify Pesanan Pembelian (order variant)
        $payloadOrder = $service->buildPresentationPayload($purchase, 'order');
        $this->assertFalse($payloadOrder['isPkp']);
        $this->assertEquals(111000.0, $payloadOrder['details'][0]['entered_unit_price']);
        $this->assertEquals(11100.0, $payloadOrder['details'][0]['discount_amount']);
        $this->assertEquals(99900.0, $payloadOrder['details'][0]['sub_total']);

        $renderedOrder = view('purchase::print', $payloadOrder)->render();
        $this->assertStringContainsString('111.000', $renderedOrder);
        $this->assertStringContainsString('11.100', $renderedOrder);
        $this->assertStringContainsString('99.900', $renderedOrder);

        // 3. Saved DB values must be completely untouched
        $this->assertEquals(100000.0, (float) $detail->fresh()->unit_price);
        $this->assertEquals(10000.0, (float) $detail->fresh()->product_discount_amount);
        $this->assertEquals(99900.0, (float) $detail->fresh()->sub_total);
    }
}
