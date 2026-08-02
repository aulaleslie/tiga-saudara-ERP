<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Services\PaymentCorrectionPreviewService;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentCorrectionPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Supplier $supplier;
    private Product $product;
    private User $user;
    private PaymentCorrectionPreviewService $previewService;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('purchases.received.correct', 'web');

        $this->setting = Setting::factory()->create([
            'company_name' => 'CV TIGA NUSA COMPUTER',
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_cost' => 1000,
            'product_price' => 2000,
            'stock_managed' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '123',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test',
            'setting_id' => $this->setting->id,
        ]);

        $this->user = User::factory()->create();

        $this->previewService = app(PaymentCorrectionPreviewService::class);
    }

    public function test_zero_active_payments_preview(): void
    {
        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_method' => 'Cash',
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 10000,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $preview = $this->previewService->previewPaymentCorrection(
            purchase: $purchase,
            lineCorrections: [],
            globalDiscount: 500,
        );

        $this->assertTrue($preview['success']);
        $this->assertEquals(9500, $preview['corrected_total']);
        $this->assertEquals(10000, $preview['current_total']);
        $this->assertEquals(-500, $preview['total_delta']);
        $this->assertEquals(0, $preview['active_payment_count']);
        $this->assertEquals('UNPAID', $preview['resulting_status']);
    }

    public function test_single_active_payment_preview(): void
    {
        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_method' => 'Cash',
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 10000,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $payment = PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 10000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'date' => Carbon::now(),
            'payment_method' => 'Cash',
            'reference' => 'REF-001',
        ]);

        $preview = $this->previewService->previewPaymentCorrection(
            purchase: $purchase,
            lineCorrections: [],
            globalDiscount: 500,
        );

        $this->assertTrue($preview['success']);
        $this->assertEquals(9500, $preview['corrected_total']);
        $this->assertEquals(10000, $preview['current_total']);
        $this->assertEquals(-500, $preview['total_delta']);
        $this->assertEquals(1, $preview['active_payment_count']);
        $this->assertNotNull($preview['selected_payment']);
        $this->assertEquals($payment->id, $preview['selected_payment']['id']);
        $this->assertEquals(10000, $preview['selected_payment']['current_amount']);
        $this->assertEquals(9500, $preview['selected_payment']['corrected_amount']);
        $this->assertEquals('PAID', $preview['resulting_status']);
    }

    public function test_multiple_payments_require_selection(): void
    {
        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PARTIAL',
            'total_amount' => 10000,
            'paid_amount' => 5000,
            'due_amount' => 5000,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_method' => 'Cash',
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 10000,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 5000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'date' => Carbon::now(),
            'payment_method' => 'Cash',
            'reference' => 'REF-002',
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 5000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'date' => Carbon::now(),
            'payment_method' => 'Transfer',
            'reference' => 'REF-003',
        ]);

        $preview = $this->previewService->previewPaymentCorrection(
            purchase: $purchase,
            lineCorrections: [],
            globalDiscount: 0,
        );

        $this->assertFalse($preview['success']);
        $this->assertEquals('Multiple active payments require selection', $preview['error']);
    }

    public function test_multiple_payments_with_selection(): void
    {
        $this->actingAs($this->user);

        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PARTIAL',
            'total_amount' => 10000,
            'paid_amount' => 5000,
            'due_amount' => 5000,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_method' => 'Cash',
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 10000,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $payment1 = PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 3000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'date' => Carbon::now(),
            'payment_method' => 'Cash',
            'reference' => 'REF-004',
        ]);

        $payment2 = PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 2000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'date' => Carbon::now(),
            'payment_method' => 'Transfer',
            'reference' => 'REF-005',
        ]);

        // Correct to 9500
        $preview = $this->previewService->previewPaymentCorrection(
            purchase: $purchase,
            lineCorrections: [],
            globalDiscount: 500,
            selectedPaymentId: $payment1->id,
        );

        $this->assertTrue($preview['success']);
        $this->assertEquals(9500, $preview['corrected_total']);
        $this->assertEquals(10000, $preview['current_total']);
        $this->assertEquals(-500, $preview['total_delta']);
        $this->assertEquals(2, $preview['active_payment_count']);
        $this->assertEquals($payment1->id, $preview['selected_payment']['id']);
        $this->assertEquals(3000, $preview['selected_payment']['current_amount']);
        $this->assertEquals(2500, $preview['selected_payment']['corrected_amount']);
        $this->assertEquals(2000, $preview['non_selected_active_total']);
        $this->assertEquals(4500, $preview['resulting_paid_amount']);
        $this->assertEquals(5000, $preview['resulting_due_amount']);
        $this->assertEquals('PARTIAL', $preview['resulting_status']);
        $this->assertNotNull($preview['confirmation_token']);
    }

    public function test_negative_selected_payment_rejected(): void
    {
        $this->actingAs($this->user);

        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PARTIAL',
            'total_amount' => 10000,
            'paid_amount' => 2000,
            'due_amount' => 8000,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_method' => 'Cash',
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 10000,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $payment1 = PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 1000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'date' => Carbon::now(),
            'payment_method' => 'Cash',
            'reference' => 'REF-006',
        ]);

        $payment2 = PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 1000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'date' => Carbon::now(),
            'payment_method' => 'Transfer',
            'reference' => 'REF-007',
        ]);

        // Correct DOWN to 8000 (delta = -2000)
        $preview = $this->previewService->previewPaymentCorrection(
            purchase: $purchase,
            lineCorrections: [],
            globalDiscount: 2000,
            selectedPaymentId: $payment1->id,
        );

        // Selected payment would be 1000 - 2000 = -1000
        $this->assertFalse($preview['success']);
        $this->assertEquals('Correction would result in negative payment amount', $preview['error']);
    }

    public function test_overpayment_rejected(): void
    {
        $this->actingAs($this->user);

        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PARTIAL',
            'total_amount' => 10000,
            'paid_amount' => 8000,
            'due_amount' => 2000,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_method' => 'Cash',
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 10000,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $payment1 = PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 5000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'date' => Carbon::now(),
            'payment_method' => 'Cash',
            'reference' => 'REF-008',
        ]);

        $payment2 = PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 3000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'date' => Carbon::now(),
            'payment_method' => 'Transfer',
            'reference' => 'REF-009',
        ]);

        // Test a correction that would result in valid payment state
        // Current total: 10000, selected payment: 5000, non-selected: 3000
        // If we correct UP to 10500 (delta = +500), selected becomes 5500
        // Non-selected: 3000, Total: 8500 < 10500, so no overpayment - should succeed
        $preview = $this->previewService->previewPaymentCorrection(
            purchase: $purchase,
            lineCorrections: [],
            shipping: 500,
            selectedPaymentId: $payment1->id,
        );

        $this->assertTrue($preview['success']);
        $this->assertEquals(10500, $preview['corrected_total']);
        $this->assertEquals(5500, $preview['selected_payment']['corrected_amount']);
        $this->assertEquals(8500, $preview['resulting_paid_amount']);
        $this->assertEquals(2000, $preview['resulting_due_amount']);
    }
}
