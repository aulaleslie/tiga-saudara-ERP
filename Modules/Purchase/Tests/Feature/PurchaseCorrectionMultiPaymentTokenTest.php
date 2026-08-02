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
use Modules\Purchase\Services\PaymentCorrectionPreviewService;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseCorrectionMultiPaymentTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $financeUser;
    private User $otherUser;
    private Setting $setting;
    private Purchase $purchase;
    private Supplier $supplier;
    private Product $product;
    private PaymentCorrectionPreviewService $previewService;

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

        $this->otherUser = User::factory()->create(['is_active' => 1]);
        $this->otherUser->assignRole($role);

        $this->setting = Setting::factory()->create();
        $this->financeUser->settings()->attach($this->setting->id, ['role_id' => $role->id]);
        $this->otherUser->settings()->attach($this->setting->id, ['role_id' => $role->id]);

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

        $this->previewService = app(PaymentCorrectionPreviewService::class);

        session(['setting_id' => $this->setting->id]);
    }

    private function createPurchaseWithTwoPayments(): Purchase
    {
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-MULTI-' . uniqid(),
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
            'purchase_id' => $purchase->id,
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

        // Two active payments totaling 10000
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 6000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash',
            'date' => now()->toDateString(),
            'reference' => 'PAY-001',
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 4000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Bank',
            'date' => now()->toDateString(),
            'reference' => 'PAY-002',
        ]);

        return $purchase;
    }

    private function obtainPreviewToken(
        Purchase $purchase,
        ?int $selectedPaymentId = null,
        ?int $detailId = null,
        ?float $lineUnitPrice = null,
        ?float $globalDiscount = null,
        ?float $shipping = null,
    ): string {
        $detailId ??= $purchase->purchaseDetails->first()->id;
        $selectedPaymentId ??= $purchase->purchasePayments->first()->id;
        $lineUnitPrice ??= 1100;

        $payload = [
            'line_corrections' => [
                ['detail_id' => $detailId, 'unit_price' => $lineUnitPrice],
            ],
        ];

        if ($globalDiscount !== null) {
            $payload['global_discount_amount'] = $globalDiscount;
        }

        if ($shipping !== null) {
            $payload['shipping_amount'] = $shipping;
        }

        if ($selectedPaymentId !== null) {
            $payload['selected_payment_id'] = $selectedPaymentId;
        }

        $response = $this->post(
            route('purchases.correction.payment-preview', $purchase),
            $payload
        );

        $this->assertTrue($response->status() === 200, 'Preview should succeed. Response: ' . $response->json('error'));

        $data = $response->json();
        $this->assertArrayHasKey('confirmation_token', $data, 'Preview response should contain confirmation_token');

        return $data['confirmation_token'];
    }

    private function createCorrectionToken(
        Purchase $purchase,
        ?int $detailId = null,
        ?int $selectedPaymentId = null,
        ?float $lineUnitPrice = null,
        ?float $globalDiscount = null,
        ?float $shipping = null,
        ?string $sessionId = null,
    ): PurchaseCorrectionToken {
        $detailId ??= $purchase->purchaseDetails->first()->id;
        $selectedPaymentId ??= $purchase->purchasePayments->first()->id;
        $lineUnitPrice ??= 1100;
        $sessionId ??= 'test-session-' . uniqid();

        $lineCorrections = [
            ['detail_id' => $detailId, 'unit_price' => $lineUnitPrice],
        ];

        return PurchaseCorrectionToken::create([
            'token' => 'test-' . uniqid(),
            'purchase_id' => $purchase->id,
            'setting_id' => $this->setting->id,
            'user_id' => $this->financeUser->id,
            'session_id' => $sessionId,
            'selected_payment_id' => $selectedPaymentId,
            'correction_payload_hash' => hash('sha256', json_encode([
                'line_corrections' => $lineCorrections,
                'global_discount' => $globalDiscount,
                'shipping' => $shipping,
            ])),
            'source_document_total' => $purchase->total_amount,
            'expires_at' => now()->addHours(1),
        ]);
    }

    // ==================== HAPPY PATH AND CONSUMPTION ====================

    public function test_valid_token_allows_correction_and_consumption(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $detail = $purchase->purchaseDetails->first();
        $selectedPayment = $purchase->purchasePayments->first();

        $this->actingAs($this->financeUser);

        // Obtain real preview-issued token
        $tokenString = $this->obtainPreviewToken($purchase, $selectedPayment->id);

        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        // Store correction with preview-issued token
        $result = $correctionService->correct(
            purchase: $purchase,
            actor: $this->financeUser,
            reason: 'Price correction',
            lineCorrections: [[
                'detail_id' => $detail->id,
                'unit_price' => 1100,
            ]],
            selectedPaymentId: $selectedPayment->id,
            confirmationToken: $tokenString,
        );

        $this->assertTrue($result['success']);

        $correction = PurchaseCorrection::where('purchase_id', $purchase->id)->latest()->first();
        $this->assertNotNull($correction);

        $token = PurchaseCorrectionToken::where('token', $tokenString)->first();
        $this->assertNotNull($token);
        $this->assertNotNull($token->consumed_at);
    }

    public function test_consumed_token_reuse_fails(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $detail = $purchase->purchaseDetails->first();
        $selectedPayment = $purchase->purchasePayments->first();

        $this->actingAs($this->financeUser);

        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        // Obtain real preview-issued token
        $tokenString = $this->obtainPreviewToken($purchase, $selectedPayment->id);

        // First correction succeeds
        $result1 = $correctionService->correct(
            purchase: $purchase,
            actor: $this->financeUser,
            reason: 'First correction',
            lineCorrections: [[
                'detail_id' => $detail->id,
                'unit_price' => 1100,
            ]],
            selectedPaymentId: $selectedPayment->id,
            confirmationToken: $tokenString,
        );

        $this->assertTrue($result1['success']);

        // Refresh detail and purchase state
        $detail->refresh();
        $purchase->refresh();

        // Second correction with same token should fail (token already consumed)
        // Note: we cannot reuse the same token even with different payload
        $result2 = $correctionService->correct(
            purchase: $purchase,
            actor: $this->financeUser,
            reason: 'Second correction',
            lineCorrections: [[
                'detail_id' => $detail->id,
                'unit_price' => 1200,
            ]],
            selectedPaymentId: $selectedPayment->id,
            confirmationToken: $tokenString,
        );

        $this->assertFalse($result2['success']);
        $this->assertStringContainsString('consumed', strtolower($result2['error']));

        // Should still have only one correction record
        $corrections = PurchaseCorrection::where('purchase_id', $purchase->id)->get();
        $this->assertEquals(1, $corrections->count());

        // Payment should only be adjusted by first correction, not second
        // Original payment: 6000, first correction changes unit_price 1000→1100 (+100*10=+1000)
        // So payment should be 7000, not affected by failed second correction
        $selectedPayment->refresh();
        $this->assertEquals(7000, $selectedPayment->amount);

        $token = PurchaseCorrectionToken::where('token', $tokenString)->first();
        $this->assertNotNull($token->consumed_at);
    }

    public function test_correction_adjusts_selected_payment_correctly(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $detail = $purchase->purchaseDetails->first();
        $selectedPayment = $purchase->purchasePayments->first();

        $originalAmount = $selectedPayment->amount;

        $this->actingAs($this->financeUser);

        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        // Create token for price increase scenario with correct session
        $token = PurchaseCorrectionToken::create([
            'token' => 'test-' . uniqid(),
            'purchase_id' => $purchase->id,
            'setting_id' => $this->setting->id,
            'user_id' => $this->financeUser->id,
            'session_id' => session()->getId(),
            'selected_payment_id' => $selectedPayment->id,
            'correction_payload_hash' => hash('sha256', json_encode([
                'line_corrections' => [['detail_id' => $detail->id, 'unit_price' => 1500]],
                'global_discount' => null,
                'shipping' => null,
            ])),
            'source_document_total' => $purchase->total_amount,
            'expires_at' => now()->addHours(1),
        ]);

        $result = $correctionService->correct(
            purchase: $purchase,
            actor: $this->financeUser,
            reason: 'Price increase',
            lineCorrections: [[
                'detail_id' => $detail->id,
                'unit_price' => 1500,  // 10 * 1500 = 15000, delta = 5000
            ]],
            selectedPaymentId: $selectedPayment->id,
            confirmationToken: $token->token,
        );

        $this->assertTrue($result['success']);
        $selectedPayment->refresh();
        $this->assertEquals($originalAmount + 5000, $selectedPayment->amount);
    }

    // ==================== EXPIRY TESTS ====================

    public function test_expired_token_is_rejected(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $detail = $purchase->purchaseDetails->first();
        $selectedPayment = $purchase->purchasePayments->first();

        $this->actingAs($this->financeUser);

        // Manually create an expired token (cannot obtain via preview since it would expire before we could use it)
        $token = PurchaseCorrectionToken::create([
            'token' => 'expired-' . uniqid(),
            'purchase_id' => $purchase->id,
            'setting_id' => $this->setting->id,
            'user_id' => $this->financeUser->id,
            'session_id' => session()->getId(),
            'selected_payment_id' => $selectedPayment->id,
            'correction_payload_hash' => hash('sha256', json_encode([
                'line_corrections' => [['detail_id' => $detail->id, 'unit_price' => 1100]],
                'global_discount' => null,
                'shipping' => null,
            ])),
            'source_document_total' => $purchase->total_amount,
            'expires_at' => now()->subMinutes(1),
        ]);

        $response = $this->post(route('purchases.correction.store', $purchase), [
            'reason' => 'Price correction',
            'line_corrections' => [
                [
                    'detail_id' => $detail->id,
                    'unit_price' => 1100,
                ],
            ],
            'selected_payment_id' => $selectedPayment->id,
            'confirmation_token' => $token->token,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('expired', strtolower($response->json('error')));

        // Token should not be consumed
        $token->refresh();
        $this->assertNull($token->consumed_at);

        // No correction should be created
        $this->assertNull(PurchaseCorrection::where('purchase_id', $purchase->id)->first());

        // Payment should not change
        $selectedPayment->refresh();
        $this->assertEquals(6000, $selectedPayment->amount);
    }

    // ==================== USER AND SESSION BINDING ====================

    public function test_token_from_different_user_rejected(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $detail = $purchase->purchaseDetails->first();
        $selectedPayment = $purchase->purchasePayments->first();

        // Authenticate as user A
        $this->actingAs($this->financeUser);

        // Obtain token as user A through preview endpoint
        $tokenString = $this->obtainPreviewToken($purchase, $selectedPayment->id);

        // Switch to user B
        $this->actingAs($this->otherUser);

        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        // Try to use with different user
        $result = $correctionService->correct(
            purchase: $purchase,
            actor: $this->otherUser,
            reason: 'Price correction',
            lineCorrections: [[
                'detail_id' => $detail->id,
                'unit_price' => 1100,
            ]],
            selectedPaymentId: $selectedPayment->id,
            confirmationToken: $tokenString,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('user', strtolower($result['error']));

        $token = PurchaseCorrectionToken::where('token', $tokenString)->first();
        $this->assertNotNull($token);
        $this->assertNull($token->consumed_at);
        $this->assertNull(PurchaseCorrection::where('purchase_id', $purchase->id)->first());

        // Verify payment unchanged
        $selectedPayment->refresh();
        $this->assertEquals(6000, $selectedPayment->amount);
    }

    public function test_token_from_different_session_rejected(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $detail = $purchase->purchaseDetails->first();
        $selectedPayment = $purchase->purchasePayments->first();

        // Authenticate as financeUser
        $this->actingAs($this->financeUser);

        // Obtain token through preview endpoint (captures current session)
        $tokenString = $this->obtainPreviewToken($purchase, $selectedPayment->id);

        // Store original session ID from token
        $token = PurchaseCorrectionToken::where('token', $tokenString)->first();
        $originalSessionId = $token->session_id;

        // Simulate a different session by changing the session ID
        session()->invalidate();
        session()->regenerate();
        $this->actingAs($this->financeUser);

        // Verify we have a different session ID
        $this->assertNotEquals($originalSessionId, session()->getId());

        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        // Try to use token from different session
        $result = $correctionService->correct(
            purchase: $purchase,
            actor: $this->financeUser,
            reason: 'Price correction',
            lineCorrections: [[
                'detail_id' => $detail->id,
                'unit_price' => 1100,
            ]],
            selectedPaymentId: $selectedPayment->id,
            confirmationToken: $tokenString,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('session', strtolower($result['error']));

        $token->refresh();
        $this->assertNull($token->consumed_at);
        $this->assertNull(PurchaseCorrection::where('purchase_id', $purchase->id)->first());

        // Verify payment unchanged
        $selectedPayment->refresh();
        $this->assertEquals(6000, $selectedPayment->amount);
    }

    // ==================== CONTEXT AND PAYLOAD BINDING ====================

    public function test_token_from_different_purchase_rejected(): void
    {
        $purchase1 = $this->createPurchaseWithTwoPayments();
        $purchase2 = $this->createPurchaseWithTwoPayments();
        $detail2 = $purchase2->purchaseDetails->first();
        $payment2 = $purchase2->purchasePayments->first();

        $this->actingAs($this->financeUser);

        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        $token = $this->createCorrectionToken($purchase1, sessionId: session()->getId());

        // Try to use token from purchase1 on purchase2
        $result = $correctionService->correct(
            purchase: $purchase2,
            actor: $this->financeUser,
            reason: 'Price correction',
            lineCorrections: [[
                'detail_id' => $detail2->id,
                'unit_price' => 1100,
            ]],
            selectedPaymentId: $payment2->id,
            confirmationToken: $token->token,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('purchase', strtolower($result['error']));

        $token->refresh();
        $this->assertNull($token->consumed_at);
        $this->assertNull(PurchaseCorrection::where('purchase_id', $purchase2->id)->first());
    }

    public function test_token_for_different_payment_rejected(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $detail = $purchase->purchaseDetails->first();
        $payment1 = $purchase->purchasePayments->first();
        $payment2 = $purchase->purchasePayments->last();

        $this->actingAs($this->financeUser);

        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        $token = $this->createCorrectionToken($purchase, selectedPaymentId: $payment1->id, sessionId: session()->getId());

        // Try to use with different payment
        $result = $correctionService->correct(
            purchase: $purchase,
            actor: $this->financeUser,
            reason: 'Price correction',
            lineCorrections: [[
                'detail_id' => $detail->id,
                'unit_price' => 1100,
            ]],
            selectedPaymentId: $payment2->id,
            confirmationToken: $token->token,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('payment', strtolower($result['error']));

        $token->refresh();
        $this->assertNull($token->consumed_at);
    }

    public function test_token_with_changed_line_price_rejected(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $detail = $purchase->purchaseDetails->first();
        $selectedPayment = $purchase->purchasePayments->first();

        $this->actingAs($this->financeUser);

        // Test at service level to avoid session mismatch
        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        $token = PurchaseCorrectionToken::create([
            'token' => 'test-' . uniqid(),
            'purchase_id' => $purchase->id,
            'setting_id' => $this->setting->id,
            'user_id' => $this->financeUser->id,
            'session_id' => session()->getId(),
            'selected_payment_id' => $selectedPayment->id,
            'correction_payload_hash' => hash('sha256', json_encode([
                'line_corrections' => [['detail_id' => $detail->id, 'unit_price' => 1100]],
                'global_discount' => null,
                'shipping' => null,
            ])),
            'source_document_total' => $purchase->total_amount,
            'expires_at' => now()->addHours(1),
        ]);

        // Call service with different unit price (payload mismatch)
        $result = $correctionService->correct(
            purchase: $purchase,
            actor: $this->financeUser,
            reason: 'Price correction',
            lineCorrections: [[
                'detail_id' => $detail->id,
                'unit_price' => 1200,  // Different from token hash
            ]],
            selectedPaymentId: $selectedPayment->id,
            confirmationToken: $token->token,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('payload', strtolower($result['error']));

        $token->refresh();
        $this->assertNull($token->consumed_at);
    }

    public function test_token_with_changed_global_discount_rejected(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $detail = $purchase->purchaseDetails->first();
        $selectedPayment = $purchase->purchasePayments->first();

        $this->actingAs($this->financeUser);

        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        $token = PurchaseCorrectionToken::create([
            'token' => 'test-' . uniqid(),
            'purchase_id' => $purchase->id,
            'setting_id' => $this->setting->id,
            'user_id' => $this->financeUser->id,
            'session_id' => session()->getId(),
            'selected_payment_id' => $selectedPayment->id,
            'correction_payload_hash' => hash('sha256', json_encode([
                'line_corrections' => [['detail_id' => $detail->id, 'unit_price' => 1100]],
                'global_discount' => 500,
                'shipping' => null,
            ])),
            'source_document_total' => $purchase->total_amount,
            'expires_at' => now()->addHours(1),
        ]);

        $result = $correctionService->correct(
            purchase: $purchase,
            actor: $this->financeUser,
            reason: 'Price correction',
            lineCorrections: [[
                'detail_id' => $detail->id,
                'unit_price' => 1100,
            ]],
            globalDiscount: 300,  // Different from token hash (was 500)
            selectedPaymentId: $selectedPayment->id,
            confirmationToken: $token->token,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('payload', strtolower($result['error']));

        $token->refresh();
        $this->assertNull($token->consumed_at);
    }

    public function test_token_with_changed_shipping_rejected(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $detail = $purchase->purchaseDetails->first();
        $selectedPayment = $purchase->purchasePayments->first();

        $this->actingAs($this->financeUser);

        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        $token = PurchaseCorrectionToken::create([
            'token' => 'test-' . uniqid(),
            'purchase_id' => $purchase->id,
            'setting_id' => $this->setting->id,
            'user_id' => $this->financeUser->id,
            'session_id' => session()->getId(),
            'selected_payment_id' => $selectedPayment->id,
            'correction_payload_hash' => hash('sha256', json_encode([
                'line_corrections' => [['detail_id' => $detail->id, 'unit_price' => 1100]],
                'global_discount' => null,
                'shipping' => 1000,
            ])),
            'source_document_total' => $purchase->total_amount,
            'expires_at' => now()->addHours(1),
        ]);

        $result = $correctionService->correct(
            purchase: $purchase,
            actor: $this->financeUser,
            reason: 'Price correction',
            lineCorrections: [[
                'detail_id' => $detail->id,
                'unit_price' => 1100,
            ]],
            shipping: 500,  // Different from token hash (was 1000)
            selectedPaymentId: $selectedPayment->id,
            confirmationToken: $token->token,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('payload', strtolower($result['error']));

        $token->refresh();
        $this->assertNull($token->consumed_at);
    }

    public function test_token_from_modified_purchase_total_rejected(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $detail = $purchase->purchaseDetails->first();
        $selectedPayment = $purchase->purchasePayments->first();

        $this->actingAs($this->financeUser);

        // Obtain token through preview endpoint with original purchase total (10000)
        $tokenString = $this->obtainPreviewToken($purchase, $selectedPayment->id);

        // Verify token captured correct source total
        $token = PurchaseCorrectionToken::where('token', $tokenString)->first();
        $this->assertEquals(10000, $token->source_document_total);

        // Simulate purchase modification between preview and store
        $purchase->update(['total_amount' => 12000]);

        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        $result = $correctionService->correct(
            purchase: $purchase,
            actor: $this->financeUser,
            reason: 'Price correction',
            lineCorrections: [[
                'detail_id' => $detail->id,
                'unit_price' => 1100,
            ]],
            selectedPaymentId: $selectedPayment->id,
            confirmationToken: $tokenString,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('total', strtolower($result['error']));

        $token->refresh();
        $this->assertNull($token->consumed_at);
        $this->assertNull(PurchaseCorrection::where('purchase_id', $purchase->id)->first());

        // Verify payment unchanged
        $selectedPayment->refresh();
        $this->assertEquals(6000, $selectedPayment->amount);
    }

    // ==================== FIELD CHANGE BETWEEN PREVIEW AND SAVE ====================

    public function test_changing_field_between_preview_and_save_rejects_token(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $detail = $purchase->purchaseDetails->first();
        $selectedPayment = $purchase->purchasePayments->first();

        $this->actingAs($this->financeUser);

        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        // Obtain token for unit_price 1100
        $tokenString = $this->obtainPreviewToken(
            $purchase,
            $selectedPayment->id,
            lineUnitPrice: 1100
        );

        // Try to save with different unit_price (1200)
        $result = $correctionService->correct(
            purchase: $purchase,
            actor: $this->financeUser,
            reason: 'Price correction',
            lineCorrections: [[
                'detail_id' => $detail->id,
                'unit_price' => 1200,  // Different from preview!
            ]],
            selectedPaymentId: $selectedPayment->id,
            confirmationToken: $tokenString,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('payload', strtolower($result['error']));

        $token = PurchaseCorrectionToken::where('token', $tokenString)->first();
        $this->assertNull($token->consumed_at);
        $this->assertNull(PurchaseCorrection::where('purchase_id', $purchase->id)->first());
        $selectedPayment->refresh();
        $this->assertEquals(6000, $selectedPayment->amount);
    }

    // ==================== TRANSACTION FAILURE BEHAVIOR ====================

    public function test_transaction_rollback_does_not_consume_token(): void
    {
        $purchase = $this->createPurchaseWithTwoPayments();
        $selectedPayment = $purchase->purchasePayments->first();

        $this->actingAs($this->financeUser);

        $token = $this->createCorrectionToken($purchase, selectedPaymentId: $selectedPayment->id);

        // Submit with invalid detail ID (doesn't exist in purchase)
        $response = $this->post(route('purchases.correction.store', $purchase), [
            'reason' => 'Price correction',
            'line_corrections' => [
                [
                    'detail_id' => 99999,  // Non-existent
                    'unit_price' => 1100,
                ],
            ],
            'selected_payment_id' => $selectedPayment->id,
            'confirmation_token' => $token->token,
        ]);

        $response->assertStatus(422);

        $token->refresh();
        $this->assertNull($token->consumed_at);

        // No correction should be created
        $this->assertNull(PurchaseCorrection::where('purchase_id', $purchase->id)->first());

        // Payment should not change
        $selectedPayment->refresh();
        $this->assertEquals(6000, $selectedPayment->amount);
    }

}
