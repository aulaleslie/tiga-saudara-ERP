<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSupervisorApproval;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosLineTotalOverrideTest extends TestCase
{
    use RefreshDatabase;

    private int $terminalSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.overrides.price',
            'pos.supervisor.approval',
            'pos.cart.clear',
            'pos.cart.line.remove',
            'pos.cart.line.reduce',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_direct_line_total_override_succeeds_when_authorized(): void
    {
        $setting = $this->createSetting('BIZ LINE OVERRIDE DIRECT', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'LINE DIRECT', true);
        $tax = $this->createTax('PPN 11%', 11);
        $product = $this->createStockedProduct($setting, $location, 'SKU-LTO-01', 'Kopi Susu', 10000, $tax->id, $cashier->id);

        $lineId = (int) $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 2])
            ->assertOk()
            ->json('cart_snapshot.lines.0.line_id');

        // Override total of 2 items from 20.000 to 18.000
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.line-total-override', ['lineId' => $lineId]), [
                'line_total' => 18000,
                'reason' => 'Diskon member langsung',
            ]);

        $response->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.line_total', 18000)
            ->assertJsonPath('cart_snapshot.lines.0.unit_price', 9000)
            ->assertJsonPath('cart_snapshot.lines.0.price_source', 'LINE_TOTAL_OVERRIDE')
            ->assertJsonPath('cart_snapshot.totals.grand_total', 18000);

        $this->assertDatabaseHas('pos_supervisor_approvals', [
            'setting_id' => $setting->id,
            'action_type' => 'LINE_TOTAL_OVERRIDE',
            'requested_by' => $cashier->id,
            'approved_by' => $cashier->id,
            'approval_result' => 'APPROVED',
        ]);
    }

    public function test_line_total_override_requires_approval_and_rejected_keeps_price(): void
    {
        $setting = $this->createSetting('BIZ LINE OVERRIDE REJECT', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'LINE REJECT', false);
        $tax = $this->createTax('PPN 11%', 11);
        $product = $this->createStockedProduct($setting, $location, 'SKU-LTO-02', 'Teh Tarik', 10000, $tax->id, $cashier->id);

        $supervisor = $this->createUserForSetting(
            $setting,
            'SUPERVISOR REJECT',
            ['pos.access', 'pos.overrides.price', 'pos.supervisor.approval'],
            'sup.reject@example.com'
        );

        $lineId = (int) $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk()
            ->json('cart_snapshot.lines.0.line_id');

        // Cashier without permission attempts direct override
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.line-total-override', ['lineId' => $lineId]), [
                'line_total' => 8000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'APPROVAL_REQUIRED');

        // Create approval request
        $requestId = (int) $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.approval-requests.store'), [
                'action_type' => 'LINE_TOTAL_OVERRIDE',
                'target_type' => 'pos_cart_line',
                'target_id' => $lineId,
                'payload' => [
                    'requested_total' => 8000,
                ],
            ])
            ->assertStatus(201)
            ->json('request_id');

        // Supervisor rejects
        $this->actingAs($supervisor)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.supervisor.approval-requests.reject', ['id' => $requestId]), [
                'reason' => 'Total terlalu rendah',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'REJECTED');

        $this->assertDatabaseHas('pos_supervisor_approvals', [
            'setting_id' => $setting->id,
            'action_type' => 'LINE_TOTAL_OVERRIDE',
            'requested_by' => $cashier->id,
            'approved_by' => $supervisor->id,
            'approval_result' => 'REJECTED',
            'reason' => 'TOTAL TERLALU RENDAH',
        ]);
    }

    public function test_line_total_override_approved_flow_and_replay_prevention(): void
    {
        $setting = $this->createSetting('BIZ LINE OVERRIDE APPROVED', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'LINE APPROVED', false);
        $tax = $this->createTax('PPN 11%', 11);
        $product = $this->createStockedProduct($setting, $location, 'SKU-LTO-03', 'Roti Bakar', 10000, $tax->id, $cashier->id);

        $supervisor = $this->createUserForSetting(
            $setting,
            'SUPERVISOR OK',
            ['pos.access', 'pos.overrides.price', 'pos.supervisor.approval'],
            'sup.ok@example.com'
        );

        $line = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 3])
            ->assertOk()
            ->json('cart_snapshot.lines.0');
        $lineId = (int) $line['line_id'];

        $fpService = new \Modules\Pos\Services\PosCartLineFingerprintService();
        $fingerprint = $fpService->generateFingerprint($line, ['is_pkp' => true, 'customer_id' => null, 'customer_tier' => null]);

        // Cashier submits approval request with fingerprint
        $requestId = (int) $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.approval-requests.store'), [
                'action_type' => 'LINE_TOTAL_OVERRIDE',
                'target_type' => 'pos_cart_line',
                'target_id' => $lineId,
                // Only the requested value is client-supplied; source values and
                // the fingerprint are built server-side from the cart.
                'payload' => [
                    'requested_total' => 25000,
                ],
            ])
            ->assertStatus(201)
            ->json('request_id');

        // Supervisor approves
        $this->actingAs($supervisor)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.supervisor.approval-requests.approve', ['id' => $requestId]), [
                'note' => 'Disetujui diskon paket 3',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'APPROVED');

        // Cashier fetches token
        $token = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.approval-requests.show', ['id' => $requestId]))
            ->assertOk()
            ->assertJsonPath('state', 'approved')
            ->json('approval_token');

        $this->assertNotEmpty($token);

        // Execute override with token
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.line-total-override', ['lineId' => $lineId]), [
                'line_total' => 25000,
                'approval_token' => $token,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.line_total', 25000)
            ->assertJsonPath('cart_snapshot.lines.0.unit_price', 8333.33)
            ->assertJsonPath('cart_snapshot.totals.grand_total', 25000);

        // Second execution should fail with replay prevention
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.line-total-override', ['lineId' => $lineId]), [
                'line_total' => 25000,
                'approval_token' => $token,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'TOKEN_ALREADY_USED');
    }

    public function test_discounted_row_total_override_arithmetic(): void
    {
        // Finding 3: Requested row total Rp 10.000 with Rp 1.000 fixed row discount
        // Authoritative final row total MUST be Rp 10.000 (not Rp 9.000)
        $setting = $this->createSetting('BIZ LTO DISCOUNT', false);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'LTO DISCOUNT', true);

        $product = $this->createStockedProduct($setting, $location, 'SKU-LTO-DISC', 'Produk Diskon', 12000, null, $cashier->id);

        $lineRes = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertOk();

        $lineId = (int) $lineRes->json('cart_snapshot.lines.0.line_id');

        // Override row total to Rp 10.000
        $overrideRes = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.line-total-override', ['lineId' => $lineId]), [
                'line_total' => 10000,
            ])
            ->assertOk();

        $overrideRes->assertJsonPath('cart_snapshot.lines.0.line_total', 10000)
            ->assertJsonPath('cart_snapshot.lines.0.line_subtotal', 10000)
            ->assertJsonPath('cart_snapshot.totals.grand_total', 10000);

        // Directly test PosCartTotalsCalculator with discounted row having LINE_TOTAL_OVERRIDE
        $calculator = app(\Modules\Pos\Services\PosCartTotalsCalculator::class);
        $calcResult = $calculator->calculate([
            [
                'line_id' => 1,
                'qty' => 1,
                'unit_price' => 100.0,
                'line_total' => 1000000, // Rp 10.000 in minor units
                'price_source' => 'LINE_TOTAL_OVERRIDE',
                'line_discount_type' => 'fixed',
                'line_discount_value' => 1000.0, // Rp 1.000 discount
                'tax_id' => null,
                'tax_rate' => 0.0,
            ],
        ], ['type' => 'fixed', 'value' => 0], false);

        // Required invariants: authoritative final row total = 10000 (not 9000), grand_total = 10000
        $this->assertEquals(10000, $calcResult['lines'][0]['line_total']);
        $this->assertEquals(10000, $calcResult['totals']['grand_total']);
    }

    public function test_cart_mutations_invalidate_line_total_overrides(): void
    {
        // Finding 4: Cart mutations must invalidate line total overrides
        $setting = $this->createSetting('BIZ LTO INVALIDATE', false);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'LTO INVALIDATE', true);

        $prod1 = $this->createStockedProduct($setting, $location, 'SKU-INV-1', 'Produk Invalidate 1', 10000, null, $cashier->id);
        $prod2 = $this->createStockedProduct($setting, $location, 'SKU-INV-2', 'Produk Invalidate 2', 20000, null, $cashier->id);

        // Add line 1: Qty 2 @ 10.000 = 20.000
        $res1 = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $prod1->id, 'qty' => 2])
            ->assertOk();
        $lineId1 = (int) $res1->json('cart_snapshot.lines.0.line_id');

        // Apply override to line 1: 15.000
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.line-total-override', ['lineId' => $lineId1]), ['line_total' => 15000])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.line_total', 15000);

        // Mutation 1: Update Qty on line 1 -> overrides on line 1 must be invalidated (cleared back to base)
        $updateRes = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId1]), ['qty' => 3])
            ->assertOk();

        $updateRes->assertJsonPath('cart_snapshot.lines.0.price_source', 'BASE')
            ->assertJsonPath('cart_snapshot.lines.0.line_total', 30000)
            ->assertJsonPath('cart_snapshot.totals.grand_total', 30000);
    }

    public function test_approval_request_constructs_payload_server_side(): void
    {
        // Finding 2: Server authoritative line load, ignore client source_total/fingerprint
        $setting = $this->createSetting('BIZ LTO SERVER PAYLOAD', false);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'LTO SERVER', false);

        $product = $this->createStockedProduct($setting, $location, 'SKU-SRV-1', 'Server Product', 50000, null, $cashier->id);

        $lineRes = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 2])
            ->assertOk();
        $lineId = (int) $lineRes->json('cart_snapshot.lines.0.line_id');

        // Attempt client tampering with bogus source_total and forged fingerprint
        $reqRes = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.approval-requests.store'), [
                'action_type' => 'LINE_TOTAL_OVERRIDE',
                'target_type' => 'pos_cart_line',
                'target_id' => $lineId,
                'payload' => [
                    'requested_total' => 80000,
                    // Forged values below must be ignored by the server.
                    'source_total' => 99999999,
                    'fingerprint' => 'forged_fake_fingerprint',
                ],
            ])
            ->assertStatus(201);

        $requestId = (int) $reqRes->json('request_id');
        $request = PosActionApprovalRequest::find($requestId);

        // Server must have computed authoritative source_total_minor = 10000000 and valid fingerprint
        $this->assertEquals(10000000, $request->request_payload['source_total_minor']);
        $this->assertEquals(8000000, $request->request_payload['requested_total_minor']);
        $this->assertNotEquals('forged_fake_fingerprint', $request->request_payload['fingerprint']);
        $this->assertNotEmpty($request->request_payload['fingerprint']);
    }

    public function test_bundle_parent_line_total_override_preserves_component_subtotals(): void
    {
        $setting = $this->createSetting('BIZ BUNDLE LTO', false);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'BUNDLE LTO', true);

        $prodA = $this->createStockedProduct($setting, $location, 'COMP-A', 'Komponen A', 15000, null, $cashier->id);
        $prodB = $this->createStockedProduct($setting, $location, 'COMP-B', 'Komponen B', 25000, null, $cashier->id);

        $bundleProduct = $this->createStockedProduct($setting, $location, 'BUNDLE-MAIN', 'Paket Hemat', 35000, null, $cashier->id);

        $bundle = ProductBundle::create([
            'setting_id' => $setting->id,
            'parent_product_id' => $bundleProduct->id,
            'name' => 'Paket Hemat',
            'bundle_sale_price' => 35000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $prodA->id,
            'quantity' => 1,
            'informational_item_price' => 15000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $prodB->id,
            'quantity' => 1,
            'informational_item_price' => 25000,
        ]);

        $res = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $bundleProduct->id,
                'qty' => 1,
                'bundle_id' => $bundle->id,
            ])
            ->assertOk();

        $lineId = (int) $res->json('cart_snapshot.lines.0.line_id');

        // Override bundle parent total from 35.000 to 30.000
        $overrideRes = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.line-total-override', ['lineId' => $lineId]), [
                'line_total' => 30000,
            ])
            ->assertOk();

        $overrideRes->assertJsonPath('cart_snapshot.lines.0.line_total', 30000)
            ->assertJsonPath('cart_snapshot.lines.0.unit_price', 30000)
            ->assertJsonPath('cart_snapshot.totals.grand_total', 30000);
    }

    public function test_failed_override_does_not_consume_token_and_subsequent_valid_attempt_succeeds(): void
    {
        $setting = $this->createSetting('BIZ LTO ATOMIC', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'LTO ATOMIC', false);
        $tax = $this->createTax('PPN 11%', 11);
        $product = $this->createStockedProduct($setting, $location, 'SKU-LTO-ATM', 'Produk Atomic', 10000, $tax->id, $cashier->id);

        $supervisor = $this->createUserForSetting(
            $setting,
            'SUPERVISOR ATM',
            ['pos.access', 'pos.overrides.price', 'pos.supervisor.approval'],
            'sup.atm@example.com'
        );

        $lineRes = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 2])
            ->assertOk();
        $lineId = (int) $lineRes->json('cart_snapshot.lines.0.line_id');

        // Request approval for line total Rp 15.000
        $reqRes = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.approval-requests.store'), [
                'action_type' => 'LINE_TOTAL_OVERRIDE',
                'target_type' => 'pos_cart_line',
                'target_id' => $lineId,
                'payload' => [
                    'requested_total' => 15000,
                ],
            ])
            ->assertStatus(201);
        $requestId = (int) $reqRes->json('request_id');

        // Supervisor approves
        $this->actingAs($supervisor)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.supervisor.approval-requests.approve', ['id' => $requestId]))
            ->assertOk();

        // Cashier retrieves token
        $token = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.approval-requests.show', ['id' => $requestId]))
            ->assertOk()
            ->json('approval_token');

        // Attempt 1: Wrong line ID -> fails with 422, token must NOT be consumed
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.line-total-override', ['lineId' => 99999]), [
                'line_total' => 15000,
                'approval_token' => $token,
            ])
            ->assertStatus(422);

        $tokenRecord = \Modules\Pos\Entities\PosActionApprovalToken::where('approval_request_id', $requestId)->first();
        $this->assertNull($tokenRecord->consumed_at);
        $this->assertEquals(PosActionApprovalRequest::STATUS_APPROVED, PosActionApprovalRequest::find($requestId)->status);

        // Attempt 2: Valid execution with correct line -> succeeds and consumes token
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.line-total-override', ['lineId' => $lineId]), [
                'line_total' => 15000,
                'approval_token' => $token,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.line_total', 15000);

        $this->assertNotNull($tokenRecord->fresh()->consumed_at);
        $this->assertEquals(PosActionApprovalRequest::STATUS_CONSUMED, PosActionApprovalRequest::find($requestId)->status);
    }

    public function test_customer_tier_change_invalidates_fingerprint(): void
    {
        $setting = $this->createSetting('BIZ LTO TIER', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'LTO TIER', false);
        $product = $this->createStockedProduct($setting, $location, 'SKU-LTO-TR', 'Produk Tier', 20000, null, $cashier->id);

        $customer1 = \Modules\People\Entities\Customer::create([
            'setting_id' => $setting->id,
            'customer_name' => 'Pelanggan Regular',
        ]);

        $customer2 = \Modules\People\Entities\Customer::create([
            'setting_id' => $setting->id,
            'customer_name' => 'Pelanggan VIP',
        ]);

        $supervisor = $this->createUserForSetting(
            $setting,
            'SUPERVISOR TR',
            ['pos.access', 'pos.overrides.price', 'pos.supervisor.approval'],
            'sup.tr@example.com'
        );

        // Set customer 1 in cart
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $customer1->id])
            ->assertOk();

        $lineRes = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();
        $lineId = (int) $lineRes->json('cart_snapshot.lines.0.line_id');

        // Request approval
        $reqRes = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.approval-requests.store'), [
                'action_type' => 'LINE_TOTAL_OVERRIDE',
                'target_type' => 'pos_cart_line',
                'target_id' => $lineId,
                'payload' => [
                    'requested_total' => 16000,
                ],
            ])
            ->assertStatus(201);
        $requestId = (int) $reqRes->json('request_id');

        // Supervisor approves
        $this->actingAs($supervisor)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.supervisor.approval-requests.approve', ['id' => $requestId]))
            ->assertOk();

        $token = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.approval-requests.show', ['id' => $requestId]))
            ->assertOk()
            ->json('approval_token');

        // Change customer to Customer 2
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $customer2->id])
            ->assertOk();

        // Attempting to execute token should fail due to changed context
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.line-total-override', ['lineId' => $lineId]), [
                'line_total' => 16000,
                'approval_token' => $token,
            ])
            ->assertStatus(422);

        // Token must remain unconsumed
        $tokenRecord = \Modules\Pos\Entities\PosActionApprovalToken::where('approval_request_id', $requestId)->first();
        $this->assertNull($tokenRecord->consumed_at);
    }

    public function test_row_total_arithmetic_rejects_percentage_discount_greater_or_equal_to_100(): void
    {
        // Superseded PosLineTotalAllocator; PosRowOverrideArithmetic is the
        // single arithmetic authority. It rejects rather than clamping: at 100%
        // no gross reproduces a positive net.
        $arithmetic = new \Modules\Pos\Services\PosRowOverrideArithmetic();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Persentase diskon baris harus kurang dari 100%');

        $arithmetic->applyRowTotal(
            requestedNetMinor: 1_000_000,
            qty: 1,
            discountType: 'percentage',
            discountValue: 100.0,
            taxRate: 0.0,
            isPkp: false
        );
    }

    private function createSetting(string $name, bool $isPkp): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
            'is_pkp' => $isPkp,
        ]);
    }

    private function createUserForSetting(
        Setting $setting,
        string $roleName,
        array $permissions,
        ?string $email = null,
        ?string $password = null
    ): User {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'email' => $email ?? strtolower(str_replace(' ', '.', $roleName)) . '@example.com',
            'password' => $password ? Hash::make($password) : Hash::make('secret'),
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createCashierAndOpenSession(Setting $setting, string $roleSuffix, bool $withOverridePermission = false): array
    {
        $permissions = ['pos.access', 'pos.sell', 'pos.sessions.open'];

        if ($withOverridePermission) {
            $permissions[] = 'pos.overrides.price';
        }

        $cashier = $this->createUserForSetting(
            $setting,
            $roleSuffix . ' CASHIER',
            $permissions
        );

        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA LTO ' . $setting->id,
            'account_number' => 'ACC-LTO-' . $setting->id . '-' . rand(100, 999),
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $method = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        \Modules\Setting\Entities\SettingPosPaymentMethod::updateOrCreate(
            ['setting_id' => $setting->id, 'payment_method_id' => $method->id],
            ['is_enabled' => true]
        );

        $session = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);

        return [$cashier, $location, $session];
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'POS LTO LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-LTO-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS LTO Terminal ' . $sequence,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }

    private function createTax(string $name, float $rate): Tax
    {
        return Tax::create([
            'name' => $name,
            'value' => $rate,
            'is_default' => true,
        ]);
    }

    private function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        string $name,
        float $salePrice,
        ?int $saleTaxId,
        int $createdBy
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => 'POS-LTO-CAT-' . $setting->id],
            [
                'category_name' => 'POS LTO Kategori ' . $setting->id,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $code . '-BC',
            'product_quantity' => 20,
            'product_cost' => 5000,
            'product_price' => $salePrice,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 20,
            'quantity_non_tax' => 20,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        ProductPrice::updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
            'purchase_tax_id' => null,
            'sale_tax_id' => $saleTaxId,
        ]);

        return $product->fresh();
    }
}
