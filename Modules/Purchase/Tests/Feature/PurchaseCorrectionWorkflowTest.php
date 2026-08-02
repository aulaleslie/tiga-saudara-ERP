<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseCorrection;
use Modules\Purchase\Entities\PurchaseCorrectionToken;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseCorrectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $financeUser;
    private Setting $setting;
    private Purchase $purchase;
    private Supplier $supplier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('purchases.received.correct', 'web');

        $role = Role::firstOrCreate(['name' => 'Finance', 'guard_name' => 'web']);
        $role->givePermissionTo('purchases.received.correct');

        $this->financeUser = User::factory()->create(['is_active' => 1]);
        $this->financeUser->assignRole($role);

        $this->setting = Setting::factory()->create();
        $this->financeUser->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_cost' => 1000,
            'product_price' => 1000,
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

        $this->purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $this->purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 0,
        ]);

        PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 10000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash',
            'date' => now()->toDateString(),
            'reference' => 'PAY-001',
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    public function test_correct_line_price_updates_detail_in_place(): void
    {
        $detail = $this->purchase->purchaseDetails->first();
        $oldDetailId = $detail->id;

        $this->actingAs($this->financeUser)->post(route('purchases.correction.store', $this->purchase), [
            'reason' => 'Price correction per supplier invoice',
            'line_corrections' => [
                [
                    'detail_id' => $detail->id,
                    'unit_price' => 1100,
                ],
            ],
        ]);

        $updatedDetail = PurchaseDetail::find($oldDetailId);
        $this->assertEquals(1100, $updatedDetail->unit_price);
        $this->assertEquals($oldDetailId, $updatedDetail->id);
    }

    public function test_correction_creates_immutable_audit_record(): void
    {
        $detail = $this->purchase->purchaseDetails->first();

        $this->actingAs($this->financeUser)->post(route('purchases.correction.store', $this->purchase), [
            'reason' => 'Price adjustment',
            'line_corrections' => [
                [
                    'detail_id' => $detail->id,
                    'unit_price' => 1100,
                ],
            ],
        ]);

        $correction = PurchaseCorrection::where('purchase_id', $this->purchase->id)->latest()->first();

        $this->assertNotNull($correction);
        $this->assertEquals('Price adjustment', $correction->reason);
        $this->assertEquals($this->financeUser->id, $correction->actor_user_id);
        $this->assertIsArray($correction->field_corrections);
    }

    public function test_single_payment_is_updated_to_corrected_total(): void
    {
        $detail = $this->purchase->purchaseDetails->first();

        $this->actingAs($this->financeUser)->post(route('purchases.correction.store', $this->purchase), [
            'reason' => 'Price adjustment',
            'line_corrections' => [
                [
                    'detail_id' => $detail->id,
                    'unit_price' => 1200,  // Increases total by 2000
                ],
            ],
        ]);

        // Refresh to check updated values
        $detail->refresh();
        $this->purchase->refresh();

        $payment = PurchasePayment::where('purchase_id', $this->purchase->id)
            ->where('status', PurchasePayment::STATUS_ACTIVE)
            ->first();

        $this->assertEquals(1200, $detail->unit_price, 'Detail unit_price should be updated');
        $this->assertEquals(12000, $this->purchase->total_amount, 'Purchase total should be 12000');
        $this->assertEquals(12000, $payment->amount, 'Payment should be 12000');
    }

    public function test_protected_fields_cannot_be_changed(): void
    {
        $detail = $this->purchase->purchaseDetails->first();
        $oldQty = $detail->quantity;

        // Attempt to change through the form - note that we don't have quantity in the form
        $this->actingAs($this->financeUser)->post(route('purchases.correction.store', $this->purchase), [
            'reason' => 'Test',
            'line_corrections' => [
                [
                    'detail_id' => $detail->id,
                    'unit_price' => 1100,
                ],
            ],
        ]);

        $detail->refresh();
        $this->assertEquals($oldQty, $detail->quantity);
    }

    public function test_unauthorized_user_cannot_correct(): void
    {
        $basicRole = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $unauthorized = User::factory()->create(['is_active' => 1]);
        $unauthorized->assignRole($basicRole);
        $unauthorized->settings()->attach($this->setting->id, ['role_id' => $basicRole->id]);

        $response = $this->actingAs($unauthorized)
            ->post(route('purchases.correction.store', $this->purchase), [
                'reason' => 'Attempted correction',
            ]);

        $response->assertStatus(403);
    }

    public function test_non_received_purchase_cannot_be_corrected(): void
    {
        $drafted = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-002',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        $response = $this->actingAs($this->financeUser)
            ->post(route('purchases.correction.store', $drafted), [
                'reason' => 'Cannot correct',
            ]);

        $response->assertStatus(403);
    }

    public function test_reason_is_mandatory(): void
    {
        $response = $this->actingAs($this->financeUser)
            ->post(route('purchases.correction.store', $this->purchase), [
                'line_corrections' => [],
            ]);

        $response->assertSessionHasErrors('reason');
    }

    public function test_correction_updates_purchase_totals(): void
    {
        $detail = $this->purchase->purchaseDetails->first();
        $oldTotal = $this->purchase->total_amount;

        $this->actingAs($this->financeUser)->post(route('purchases.correction.store', $this->purchase), [
            'reason' => 'Price update',
            'line_corrections' => [
                [
                    'detail_id' => $detail->id,
                    'unit_price' => 1100,  // 10 × 1100 = 11000
                ],
            ],
        ]);

        $this->purchase->refresh();
        $this->assertEquals(11000, $this->purchase->total_amount);
    }

    public function test_multiple_payments_require_selection(): void
    {
        PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Bank',
            'date' => now()->toDateString(),
            'reference' => 'PAY-002',
        ]);

        // First payment to 5000, second to 5000 = total 10000
        $this->purchase->update([
            'paid_amount' => 10000,
        ]);

        $detail = $this->purchase->purchaseDetails->first();

        // Without selected payment, should fail
        $response = $this->actingAs($this->financeUser)
            ->post(route('purchases.correction.store', $this->purchase), [
                'reason' => 'Price update with multiple payments',
                'line_corrections' => [
                    [
                        'detail_id' => $detail->id,
                        'unit_price' => 1200,  // Would make total 12000
                    ],
                ],
            ]);

        // Should return error response (422 with JSON error)
        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }

    public function test_payment_status_uses_uppercase_constants(): void
    {
        $detail = $this->purchase->purchaseDetails->first();

        // Test PAID status
        $this->actingAs($this->financeUser)->post(route('purchases.correction.store', $this->purchase), [
            'reason' => 'Test payment status',
            'line_corrections' => [
                [
                    'detail_id' => $detail->id,
                    'unit_price' => 1000,  // No change
                ],
            ],
        ]);

        $this->purchase->refresh();
        $this->assertEquals('PAID', $this->purchase->payment_status);
    }

    public function test_zero_active_payments_status(): void
    {
        // Test purchase with no active payments - correction should set due amount
        $noPay = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-NOPAY',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $noPay->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 0,
        ]);

        session(['setting_id' => $this->setting->id]);

        $detail = $noPay->purchaseDetails->first();

        // Reduce price - should stay UNPAID since there are no active payments
        $this->actingAs($this->financeUser)->post(route('purchases.correction.store', $noPay), [
            'reason' => 'Test no payments',
            'line_corrections' => [
                [
                    'detail_id' => $detail->id,
                    'unit_price' => 500,  // Total becomes 5000
                ],
            ],
        ]);

        $noPay->refresh();
        $this->assertEquals('UNPAID', $noPay->payment_status);
        $this->assertEquals(5000, $noPay->total_amount);
        $this->assertEquals(5000, $noPay->due_amount);
    }

    public function test_unpaid_purchase_uses_uppercase_status(): void
    {
        // Create unpaid purchase
        $unpaid = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-UNPAID',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $unpaid->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 0,
        ]);

        session(['setting_id' => $this->setting->id]);

        $detail = $unpaid->purchaseDetails->first();

        // Reduce price
        $this->actingAs($this->financeUser)->post(route('purchases.correction.store', $unpaid), [
            'reason' => 'Test unpaid status',
            'line_corrections' => [
                [
                    'detail_id' => $detail->id,
                    'unit_price' => 500,  // Total becomes 5000
                ],
            ],
        ]);

        $unpaid->refresh();
        $this->assertEquals('UNPAID', $unpaid->payment_status);
    }

    public function test_super_admin_cannot_correct_drafted_purchase(): void
    {
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['is_active' => 1]);
        $superAdmin->assignRole($superAdminRole);
        $superAdmin->settings()->attach($this->setting->id, ['role_id' => $superAdminRole->id]);

        $drafted = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-DRAFT',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        session(['setting_id' => $this->setting->id]);

        // Super Admin should still be denied due to explicit status gate
        $response = $this->actingAs($superAdmin)
            ->get(route('purchases.correction.edit', $drafted));

        $response->assertStatus(403);
    }

    public function test_token_consumption_mechanics(): void
    {
        // Verify that a PurchaseCorrectionToken can be created, checked for validity, and consumed
        $token = PurchaseCorrectionToken::create([
            'token' => 'test-consumption-' . uniqid(),
            'purchase_id' => $this->purchase->id,
            'setting_id' => $this->setting->id,
            'user_id' => $this->financeUser->id,
            'session_id' => session()->getId(),
            'selected_payment_id' => null,
            'correction_payload_hash' => hash('sha256', '{}'),
            'source_document_total' => $this->purchase->total_amount,
            'expires_at' => now()->addHours(1),
        ]);

        // Token should start unconsumed
        $this->assertNull($token->consumed_at);
        $this->assertTrue($token->isValid(session()->getId(), $this->financeUser->id));

        // Consume the token
        $token->consume();

        // Token should now be consumed
        $this->assertNotNull($token->consumed_at);
        $this->assertFalse($token->isValid(session()->getId(), $this->financeUser->id));
    }

    public function test_token_lifecycle_validation_entity(): void
    {
        // Test the PurchaseCorrectionToken entity validation methods
        $token = PurchaseCorrectionToken::create([
            'token' => 'test-lifecycle-' . uniqid(),
            'purchase_id' => $this->purchase->id,
            'setting_id' => $this->setting->id,
            'user_id' => $this->financeUser->id,
            'session_id' => 'test-session-id',
            'selected_payment_id' => null,
            'correction_payload_hash' => hash('sha256', '{}'),
            'source_document_total' => $this->purchase->total_amount,
            'expires_at' => now()->addHours(1),
        ]);

        // Valid token should pass validation
        $this->assertTrue($token->isValid('test-session-id', $this->financeUser->id));

        // Consumed token should fail validation
        $token->consume();
        $this->assertFalse($token->isValid('test-session-id', $this->financeUser->id));

        // Expired token should fail validation
        $expiredToken = PurchaseCorrectionToken::create([
            'token' => 'test-expired-' . uniqid(),
            'purchase_id' => $this->purchase->id,
            'setting_id' => $this->setting->id,
            'user_id' => $this->financeUser->id,
            'session_id' => 'test-session-id',
            'selected_payment_id' => null,
            'correction_payload_hash' => hash('sha256', '{}'),
            'source_document_total' => $this->purchase->total_amount,
            'expires_at' => now()->subMinutes(1),
        ]);
        $this->assertFalse($expiredToken->isValid('test-session-id', $this->financeUser->id));

        // Create another user to test different user scenario
        $otherUser = User::factory()->create(['is_active' => 1]);

        // Token from different user should fail
        $diffUserToken = PurchaseCorrectionToken::create([
            'token' => 'test-diff-user-' . uniqid(),
            'purchase_id' => $this->purchase->id,
            'setting_id' => $this->setting->id,
            'user_id' => $otherUser->id,
            'session_id' => 'test-session-id',
            'selected_payment_id' => null,
            'correction_payload_hash' => hash('sha256', '{}'),
            'source_document_total' => $this->purchase->total_amount,
            'expires_at' => now()->addHours(1),
        ]);
        $this->assertFalse($diffUserToken->isValid('test-session-id', $this->financeUser->id));

        // Token from different session should fail
        $diffSessionToken = PurchaseCorrectionToken::create([
            'token' => 'test-diff-session-' . uniqid(),
            'purchase_id' => $this->purchase->id,
            'setting_id' => $this->setting->id,
            'user_id' => $this->financeUser->id,
            'session_id' => 'different-session-id',
            'selected_payment_id' => null,
            'correction_payload_hash' => hash('sha256', '{}'),
            'source_document_total' => $this->purchase->total_amount,
            'expires_at' => now()->addHours(1),
        ]);
        $this->assertFalse($diffSessionToken->isValid('test-session-id', $this->financeUser->id));
    }

    public function test_failed_correction_does_not_consume_token(): void
    {
        $detail = $this->purchase->purchaseDetails->first();

        // Create a token
        $token = PurchaseCorrectionToken::create([
            'token' => 'fail-token-' . uniqid(),
            'purchase_id' => $this->purchase->id,
            'setting_id' => $this->setting->id,
            'user_id' => $this->financeUser->id,
            'session_id' => session()->getId(),
            'selected_payment_id' => null,
            'correction_payload_hash' => hash('sha256', '{}'),
            'source_document_total' => $this->purchase->total_amount,
            'expires_at' => now()->addHours(1),
        ]);

        $this->assertNull($token->consumed_at);

        // Attempt correction that should fail (invalid reason, etc.)
        $response = $this->actingAs($this->financeUser)->post(route('purchases.correction.store', $this->purchase), [
            // Missing required 'reason'
            'line_corrections' => [
                [
                    'detail_id' => $detail->id,
                    'unit_price' => 1100,
                ],
            ],
            'confirmation_token' => $token->token,
        ]);

        // Should fail validation
        $response->assertSessionHasErrors('reason');

        // Token should NOT be consumed
        $token->refresh();
        $this->assertNull($token->consumed_at);
    }

    public function test_zero_active_payments_correction_no_token(): void
    {
        // Purchase with no active payments should not require tokens
        $noPay = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-NOPAY-TOKEN',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $noPay->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 0,
        ]);

        session(['setting_id' => $this->setting->id]);

        $detail = $noPay->purchaseDetails->first();

        // Correction without token should succeed for zero-payment case
        $this->actingAs($this->financeUser)->post(route('purchases.correction.store', $noPay), [
            'reason' => 'No payment correction',
            'line_corrections' => [
                [
                    'detail_id' => $detail->id,
                    'unit_price' => 500,
                ],
            ],
            // No token provided
        ])->assertStatus(200);
    }

    public function test_single_active_payment_correction_no_token(): void
    {
        // Correction with single active payment should not require tokens
        $detail = $this->purchase->purchaseDetails->first();

        // Single payment should not require token
        $this->actingAs($this->financeUser)->post(route('purchases.correction.store', $this->purchase), [
            'reason' => 'Single payment - no token needed',
            'line_corrections' => [
                [
                    'detail_id' => $detail->id,
                    'unit_price' => 1100,
                ],
            ],
            // No token provided
        ])->assertStatus(200);
    }
}
