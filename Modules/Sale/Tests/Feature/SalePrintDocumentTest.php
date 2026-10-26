<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalePrintDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $settingA;
    protected Setting $settingB;
    protected User $user;
    protected Customer $customer;
    protected Location $location;
    protected Unit $pcsUnit;
    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPermissions([
            'sales.show',
            'sales.access',
            'salePayments.global.access',
            'globalSalesSearch.access',
        ]);

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
        ]);

        $this->settingA = Setting::create([
            'company_name' => 'Company Alpha',
            'company_email' => 'alpha@example.com',
            'company_phone' => '0812345678',
            'company_address' => 'Alpha Street No. 1',
            'notification_email' => 'notifyA@example.com',
            'footer_text' => 'Footer Alpha',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Company Beta',
            'company_email' => 'beta@example.com',
            'company_phone' => '0887654321',
            'company_address' => 'Beta Street No. 2',
            'notification_email' => 'notifyB@example.com',
            'footer_text' => 'Footer Beta',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Customer PT',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '0899999999',
            'city' => 'City',
            'country' => 'ID',
            'address' => 'Customer Address',
            'npwp' => '01.234.567.8-999.000',
        ]);

        $this->location = Location::create([
            'name' => 'Store Alpha',
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
    }

    protected function seedPermissions(array $permissions): void
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    protected function createSale(array $overrides = []): Sale
    {
        $settingId = $overrides['setting_id'] ?? $this->settingA->id;

        return Sale::create(array_merge([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 11,
            'tax_amount' => 11000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 10000,
            'total_amount' => 121000,
            'paid_amount' => 50000,
            'due_amount' => 71000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'setting_id' => $settingId,
            'is_tax_included' => false,
        ], $overrides));
    }

    public function test_default_invoice_and_offer_routes_stream_and_unknown_variant_rejected(): void
    {
        $this->user->givePermissionTo('sales.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        session(['setting_id' => $this->settingA->id]);

        $sale = $this->createSale();

        // 1. Default invoice streaming
        $response = $this->actingAs($this->user)->get(route('sales.invoicePdf', $sale->id));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        // 2. Offer route streaming
        $offerResponse = $this->actingAs($this->user)->get(route('sales.offerPdf', $sale->id));
        $offerResponse->assertOk();
        $this->assertSame('application/pdf', $offerResponse->headers->get('Content-Type'));

        // 3. Unknown variant rejected with 400
        $badResponse = $this->actingAs($this->user)->get(route('sales.invoicePdf', [
            'sale' => $sale->id,
            'type' => 'unknown_sales_doc_variant',
        ]));
        $badResponse->assertStatus(400);
    }

    public function test_user_without_show_permission_cannot_print_sale(): void
    {
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        session(['setting_id' => $this->settingA->id]);

        $sale = $this->createSale();

        $response = $this->actingAs($this->user)->get(route('sales.invoicePdf', $sale->id));
        $response->assertStatus(403);
    }

    public function test_user_cannot_print_inaccessible_business_sale(): void
    {
        $this->user->givePermissionTo('sales.show');
        // User assigned only to setting A
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        session(['setting_id' => $this->settingA->id]);

        // Sale belongs to setting B
        $saleB = $this->createSale(['setting_id' => $this->settingB->id]);

        $response = $this->actingAs($this->user)->get(route('sales.invoicePdf', $saleB->id));
        $response->assertStatus(403);
    }

    public function test_authorized_cross_business_user_can_print_sale_without_session_switch(): void
    {
        $this->user->givePermissionTo(['sales.show', 'salePayments.global.access']);
        // User assigned to both A and B
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        $this->user->settings()->attach($this->settingB->id, ['role_id' => 1]);

        // Active session is A
        session(['setting_id' => $this->settingA->id]);

        // Sale belongs to B
        $saleB = $this->createSale(['setting_id' => $this->settingB->id]);

        $response = $this->actingAs($this->user)->get(route('sales.invoicePdf', $saleB->id));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        // Session setting must remain A
        $this->assertEquals($this->settingA->id, session('setting_id'));
    }

    public function test_presentation_uses_owning_business_identity_and_tax_rules(): void
    {
        $this->user->givePermissionTo('sales.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        $this->user->settings()->attach($this->settingB->id, ['role_id' => 1]);

        $service = app(\Modules\Sale\Services\SalePrintDocumentService::class);

        // Document for PKP business A
        $saleA = $this->createSale(['setting_id' => $this->settingA->id]);
        $payloadA = $service->buildPresentationPayload($saleA, 'invoice');
        $this->assertSame('COMPANY ALPHA', $payloadA['company']['name']);
        $this->assertTrue($payloadA['isPkp']);
        $this->assertTrue($payloadA['showSettlement']);
        $this->assertSame('FAKTUR PENJUALAN', $payloadA['documentTitle']);

        // Document for non-PKP business B
        $saleB = $this->createSale(['setting_id' => $this->settingB->id]);
        $payloadB = $service->buildPresentationPayload($saleB, 'offer');
        $this->assertSame('COMPANY BETA', $payloadB['company']['name']);
        $this->assertFalse($payloadB['isPkp']);
        $this->assertFalse($payloadB['showSettlement']);
        $this->assertSame('NOTA PENAWARAN', $payloadB['documentTitle']);
        // Non-PKP hides DPP and tax
        $this->assertEquals(0.0, $payloadB['totals']['dpp']);
        $this->assertEquals(0.0, $payloadB['totals']['tax_amount']);
    }

    public function test_standalone_bundle_items_are_included_nonduplicated(): void
    {
        $this->user->givePermissionTo('sales.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);

        $product = Product::create([
            'product_name' => 'Normal Item',
            'product_code' => 'NI-001',
            'product_unit' => 'pc',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 10,
            'setting_id' => $this->settingA->id,
            'category_id' => 1,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
        ]);

        $bundleProduct = Product::create([
            'product_name' => 'Standalone Bundle Item',
            'product_code' => 'SBI-001',
            'product_unit' => 'pc',
            'product_cost' => 500,
            'product_price' => 1500,
            'product_quantity' => 10,
            'setting_id' => $this->settingA->id,
            'category_id' => 1,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
        ]);

        $sale = $this->createSale();

        // 1 normal detail
        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 2000,
            'unit_price' => 2000,
            'sub_total' => 4000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // 1 standalone bundle item (sale_detail_id is null)
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => null,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $bundleProduct->id,
            'name' => 'Standalone Bundle Item',
            'quantity' => 1,
            'price' => 1500,
            'sub_total' => 1500,
            'tax_amount' => 0,
        ]);

        // 1 child bundle item attached to detail (should NOT be added as duplicate commercial row)
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => 1,
            'bundle_item_id' => 2,
            'product_id' => $bundleProduct->id,
            'name' => 'Child Component',
            'quantity' => 2,
            'price' => 0,
            'sub_total' => 0,
            'tax_amount' => 0,
        ]);

        $service = app(\Modules\Sale\Services\SalePrintDocumentService::class);
        $payload = $service->buildPresentationPayload($sale, 'invoice');

        // Should have exactly 2 commercial rows: the normal detail and the standalone bundle
        $this->assertCount(2, $payload['details']);
        $names = array_column($payload['details'], 'product_name');
        $this->assertContains('Normal Item', $names);
        $this->assertContains('Standalone Bundle Item', $names);
    }

    public function test_settlement_separates_payments_and_credits_and_excludes_invalidations(): void
    {
        $this->user->givePermissionTo('sales.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);

        $sale = $this->createSale([
            'total_amount' => 100000,
            'paid_amount' => 60000,
            'due_amount' => 40000,
        ]);

        // Create a return and customer credit
        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'SR-001',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $creditId = DB::table('customer_credits')->insertGetId([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 50000,
            'remaining_amount' => 30000,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Active payment: 30,000
        $activePayment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 30000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-ACTIVE',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        // Active credit application attached to active payment: 20,000
        DB::table('sale_payment_credit_applications')->insert([
            'sale_payment_id' => $activePayment->id,
            'customer_credit_id' => $creditId,
            'amount' => 20000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Invalidated payment: 50,000 (must be excluded!)
        $invalidatedPayment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 50000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-INVALID',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_INVALIDATED,
        ]);

        // Credit attached to invalidated payment: 10,000 (must be excluded!)
        DB::table('sale_payment_credit_applications')->insert([
            'sale_payment_id' => $invalidatedPayment->id,
            'customer_credit_id' => $creditId,
            'amount' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(\Modules\Sale\Services\SalePrintDocumentService::class);
        $payload = $service->buildPresentationPayload($sale, 'invoice');

        // Monetary paid: 30,000
        $this->assertEquals(30000.0, $payload['totals']['monetary_paid']);
        // Credits applied: 20,000
        $this->assertEquals(20000.0, $payload['totals']['credits_applied']);
        // Total paid: 50,000
        $this->assertEquals(50000.0, $payload['totals']['total_paid']);
        // Live due: 100,000 - 50,000 = 50,000
        $this->assertEquals(50000.0, $payload['totals']['due_amount']);
    }

    public function test_archived_sale_remains_printable_with_archived_flag(): void
    {
        $this->user->givePermissionTo('sales.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        session(['setting_id' => $this->settingA->id]);

        $sale = $this->createSale(['archived_at' => now()]);

        $response = $this->actingAs($this->user)->get(route('sales.invoicePdf', $sale->id));
        $response->assertOk();

        $service = app(\Modules\Sale\Services\SalePrintDocumentService::class);
        $payload = $service->buildPresentationPayload($sale, 'invoice');
        $this->assertTrue($payload['isArchived']);
    }

    public function test_commercial_print_endpoints_work_across_all_statuses(): void
    {
        $this->user->givePermissionTo('sales.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        session(['setting_id' => $this->settingA->id]);

        $statuses = [
            Sale::STATUS_DRAFTED,
            Sale::STATUS_WAITING_APPROVAL,
            Sale::STATUS_APPROVED,
            Sale::STATUS_REJECTED,
            Sale::STATUS_DISPATCHED,
        ];

        foreach ($statuses as $status) {
            $sale = $this->createSale(['status' => $status]);

            // Test invoice variant
            $resInvoice = $this->actingAs($this->user)->get(route('sales.invoicePdf', $sale->id));
            $resInvoice->assertOk();
            $this->assertSame('application/pdf', $resInvoice->headers->get('Content-Type'));

            // Test offer variant
            $resOffer = $this->actingAs($this->user)->get(route('sales.offerPdf', $sale->id));
            $resOffer->assertOk();
            $this->assertSame('application/pdf', $resOffer->headers->get('Content-Type'));
        }

        // Test archived
        $archivedSale = $this->createSale(['archived_at' => now(), 'status' => Sale::STATUS_APPROVED]);
        $this->actingAs($this->user)->get(route('sales.invoicePdf', $archivedSale->id))->assertOk();
        $this->actingAs($this->user)->get(route('sales.offerPdf', $archivedSale->id))->assertOk();
    }

    public function test_tax_exclusive_entry_calculates_dpp_and_no_false_reconciliation_difference(): void
    {
        $this->user->givePermissionTo('sales.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);

        $sale = $this->createSale([
            'setting_id' => $this->settingA->id,
            'total_amount' => 111000,
            'shipping_amount' => 0,
            'is_tax_included' => false,
            'tax_amount' => 11000,
        ]);

        // Recreate detail with 111,000 gross and 11,000 recorded tax
        $sale->saleDetails()->delete();
        $product = Product::create([
            'product_name' => 'Taxed Product',
            'product_code' => 'TX-01',
            'product_unit' => 'pc',
            'product_cost' => 50000,
            'product_price' => 100000,
            'product_quantity' => 10,
            'setting_id' => $this->settingA->id,
            'category_id' => 1,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 111000,
            'product_tax_amount' => 11000,
            'product_discount_amount' => 0,
        ]);

        $service = app(\Modules\Sale\Services\SalePrintDocumentService::class);
        $payload = $service->buildPresentationPayload($sale, 'invoice');

        // DPP must be 100,000, tax 11,000, and reconciliation diff exactly 0
        $this->assertEquals(100000.0, $payload['totals']['dpp']);
        $this->assertEquals(11000.0, $payload['totals']['tax_amount']);
        $this->assertEquals(0.0, $payload['totals']['reconciliation_diff']);
    }

    public function test_single_rate_document_prints_applicable_rate(): void
    {
        $this->user->givePermissionTo('sales.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);

        $sale = $this->createSale([
            'setting_id' => $this->settingA->id,
            'total_amount' => 111000,
            'tax_percentage' => 0, // Header tax percentage is zero in standard item-tax creation
            'tax_amount' => 11000,
        ]);

        $sale->saleDetails()->delete();
        $product = Product::create([
            'product_name' => 'Item Rate Test',
            'product_code' => 'IRT-01',
            'product_unit' => 'pc',
            'product_cost' => 50000,
            'product_price' => 100000,
            'product_quantity' => 10,
            'setting_id' => $this->settingA->id,
            'category_id' => 1,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 111000,
            'product_tax_amount' => 11000,
            'product_discount_amount' => 0,
        ]);

        $service = app(\Modules\Sale\Services\SalePrintDocumentService::class);
        $payload = $service->buildPresentationPayload($sale, 'invoice');

        // Should have a single rate entry for 11%
        $this->assertCount(1, $payload['totals']['tax_by_rate']);
        $this->assertEquals(11.0, $payload['totals']['tax_by_rate'][0]['rate']);

        // Render template and verify PPN (11%) is rendered
        $rendered = view('sale::print.invoice', $payload)->render();
        $this->assertStringContainsString('PPN (11%)', $rendered);
        $this->assertStringContainsString('11.000', $rendered);
    }

    public function test_editing_tax_master_does_not_alter_historical_print_rate(): void
    {
        $this->user->givePermissionTo('sales.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);

        $tax = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
            'is_default' => true,
        ]);

        $sale = $this->createSale([
            'setting_id' => $this->settingA->id,
            'total_amount' => 111000,
            'tax_amount' => 11000,
        ]);

        $sale->saleDetails()->delete();
        $product = Product::create([
            'product_name' => 'Master Edit Test',
            'product_code' => 'MET-01',
            'product_unit' => 'pc',
            'product_cost' => 50000,
            'product_price' => 100000,
            'product_quantity' => 10,
            'setting_id' => $this->settingA->id,
            'category_id' => 1,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 111000,
            'product_tax_amount' => 11000,
            'tax_id' => $tax->id,
            'product_discount_amount' => 0,
        ]);

        // Now edit tax master to 11.2% in the database (reproducing the mutable master test case)
        DB::table('taxes')->where('id', $tax->id)->update(['value' => 11.2]);

        $service = app(\Modules\Sale\Services\SalePrintDocumentService::class);
        $payload = $service->buildPresentationPayload($sale, 'invoice');

        // Rate must remain 11% derived from historical recorded amounts
        $this->assertEquals(11.0, $payload['totals']['tax_by_rate'][0]['rate']);

        $rendered = view('sale::print.invoice', $payload)->render();
        $this->assertStringContainsString('PPN (11%)', $rendered);
        $this->assertStringNotContainsString('PPN (11.2%)', $rendered);
        $this->assertStringNotContainsString('11.2', $rendered);
    }

    public function test_recorded_tax_reference_prints_on_pkp_and_hidden_on_non_pkp(): void
    {
        $this->user->givePermissionTo('sales.show');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => 1]);
        $this->user->settings()->attach($this->settingB->id, ['role_id' => 1]);

        $service = app(\Modules\Sale\Services\SalePrintDocumentService::class);

        // 1. PKP sale with tax_ref_no
        $salePkp = $this->createSale([
            'setting_id' => $this->settingA->id,
            'tax_ref_no' => '010.000-26.99998888',
        ]);
        $payloadPkp = $service->buildPresentationPayload($salePkp, 'invoice');
        $renderedPkp = view('sale::print.invoice', $payloadPkp)->render();

        $this->assertStringContainsString('No. Faktur Pajak', $renderedPkp);
        $this->assertStringContainsString('010.000-26.99998888', $renderedPkp);

        // 2. Non-PKP sale with tax_ref_no (must be hidden)
        $saleNonPkp = $this->createSale([
            'setting_id' => $this->settingB->id,
            'tax_ref_no' => '010.000-26.99998888',
        ]);
        $payloadNonPkp = $service->buildPresentationPayload($saleNonPkp, 'invoice');
        $renderedNonPkp = view('sale::print.invoice', $payloadNonPkp)->render();

        $this->assertStringNotContainsString('No. Faktur Pajak', $renderedNonPkp);
        $this->assertStringNotContainsString('010.000-26.99998888', $renderedNonPkp);
    }

    public function test_non_pkp_copies_display_tax_inclusive_unit_prices_for_tax_exclusive_rows(): void
    {
        $this->user->givePermissionTo('sales.show');
        $this->user->settings()->attach($this->settingB->id, ['role_id' => 1]);

        $sale = $this->createSale([
            'setting_id' => $this->settingB->id, // Non-PKP business B
            'total_amount' => 111000,
            'shipping_amount' => 0,
            'is_tax_included' => false,
            'tax_amount' => 11000,
        ]);

        $sale->saleDetails()->delete();
        $product = Product::create([
            'product_name' => 'Non PKP Taxed Item',
            'product_code' => 'NPTI-01',
            'product_unit' => 'pc',
            'product_cost' => 50000,
            'product_price' => 100000,
            'product_quantity' => 10,
            'setting_id' => $this->settingB->id,
            'category_id' => 1,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 111000,
            'product_tax_amount' => 11000,
            'product_discount_amount' => 0,
        ]);

        $service = app(\Modules\Sale\Services\SalePrintDocumentService::class);
        $payload = $service->buildPresentationPayload($sale, 'offer');

        $this->assertFalse($payload['isPkp']);
        // Display unit price must be grossed up to 111,000 for Non-PKP presentation
        $this->assertEquals(111000.0, $payload['details'][0]['unit_price']);
        $this->assertEquals(111000.0, $payload['details'][0]['sub_total']);

        // Saved DB records must remain untouched
        $this->assertEquals(100000.0, (float) $detail->fresh()->unit_price);
        $this->assertEquals(111000.0, (float) $detail->fresh()->sub_total);

        // Rendered view must show 111.000 under unit price
        $rendered = view('sale::print.invoice', $payload)->render();
        $this->assertStringContainsString('111.000', $rendered);
    }
}
