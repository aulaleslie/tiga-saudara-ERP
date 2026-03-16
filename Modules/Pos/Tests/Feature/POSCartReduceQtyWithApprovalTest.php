<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Setting\Entities\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Location;
use App\Support\SalesLocationResolver;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Tests\TestCase;

class POSCartReduceQtyWithApprovalTest extends TestCase
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

        foreach ( [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.cart.line.reduce',
            'pos.supervisor.approval',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function createSetting(string $name, bool $isPkp): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id') ?? 1,
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

    private function createCashierAndOpenSession(Setting $setting, string $roleSuffix, array $extraPermissions = []): array
    {
        $permissions = array_merge(['pos.access', 'pos.sell', 'pos.sessions.open'], $extraPermissions);

        $cashier = $this->createUserForSetting(
            $setting,
            $roleSuffix . ' CASHIER',
            $permissions
        );

        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

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
            'name' => 'POS CART LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-CART-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Cart Terminal ' . $sequence,
            'is_active' => true,
        ]);

        $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA PM ' . $setting->id,
            'account_number' => 'ACC-PM-' . $setting->id . '-' . rand(100, 999),
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

    private function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        string $name,
        float $salePrice,
        int $createdBy
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => 'POS-CAT-' . $setting->id],
            [
                'category_name' => 'POS Kategori ' . $setting->id,
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

        \Modules\Product\Entities\ProductPrice::updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
            'purchase_tax_id' => null,
            'sale_tax_id' => null,
        ]);

        return $product;
    }

    public function test_qty_decrease_succeeds_with_approval_token(): void
    {
        $setting = $this->createSetting('BIZ POS QTY REDUCE', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'POS ASYNC 1', []);

        $supervisor = $this->createUserForSetting(
            $setting,
            'POS ASYNC 1 SUP',
            ['pos.access', 'pos.supervisor.approval', 'pos.cart.line.reduce']
        );

        $product = $this->createStockedProduct($setting, $location, 'SKU-A1', 'Product A1', 10000, $cashier->id);

        $resStore = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 5]);
        $lineId = $resStore->json('cart_snapshot.lines.0.line_id');

        // 1. Request approval for reducing
        $res = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.approval-requests.store'), [
                 'action_type' => 'QTY_REDUCE',
                 'target_type' => 'pos_cart_line',
                 'target_id' => $lineId,
                 'payload' => ['qty' => 2]
             ]);

        $res->assertStatus(201);
        $requestId = $res->json('request_id');
        $res->assertJsonStructure([
            'request_id',
            'status',
            'cart_snapshot'
        ]);
        $this->assertNotNull($res->json('cart_snapshot'), 'cart_snapshot should be present');
        $this->assertIsArray($res->json('cart_snapshot.lines'), 'cart_snapshot.lines should be an array');
        $this->assertGreaterThan(0, count($res->json('cart_snapshot.lines')), 'cart_snapshot should contain at least one line');
        $this->assertEquals($lineId, $res->json('cart_snapshot.lines.0.line_id'), 'cart_snapshot should have correct line_id');

        // 2. Supervisor Approves
        $this->actingAs($supervisor)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.supervisor.approval-requests.approve', ['id' => $requestId]))
             ->assertStatus(200);

        // 3. Cashier Gets Token
        $statusRes = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->getJson(route('pos.sell.approval-requests.show', ['id' => $requestId]));

        $statusRes->assertStatus(200);
        $token = $statusRes->json('approval_token');

        // 4. Cashier Uses Token to Reduce Qty
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
                 'qty' => 2,
                 'approval_token' => $token
             ])
             ->assertStatus(200)
             ->assertJsonPath('cart_snapshot.lines.0.qty', 2);
    }

    // Task 4.1: Test pending qty-approval visibility immediately after request submission
    public function test_pending_qty_approval_visible_immediately_after_submission(): void
    {
        $setting = $this->createSetting('BIZ POS QTY APPROVAL VIS', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'QTY VIS TEST', []);

        $product = $this->createStockedProduct($setting, $location, 'SKU-VIS-1', 'Visibility Test Product', 10000, $cashier->id);

        // Add product to cart
        $resStore = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 5]);
        $lineId = $resStore->json('cart_snapshot.lines.0.line_id');

        // Submit qty reduction approval request
        $resApprovalReq = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.approval-requests.store'), [
                 'action_type' => 'QTY_REDUCE',
                 'target_type' => 'pos_cart_line',
                 'target_id' => $lineId,
                 'payload' => ['qty' => 2]
             ]);

        $resApprovalReq->assertStatus(201);
        $requestId = $resApprovalReq->json('request_id');

        // Verify cart_snapshot is present and contains line data with approval metadata
        $resApprovalReq->assertJsonStructure([
            'request_id',
            'status',
            'cart_snapshot'
        ]);
        $this->assertNotNull($resApprovalReq->json('cart_snapshot'), 'cart_snapshot should be present in approval request response');
        $this->assertIsArray($resApprovalReq->json('cart_snapshot.lines'), 'cart_snapshot.lines should be an array');
        $this->assertGreaterThan(0, count($resApprovalReq->json('cart_snapshot.lines')), 'cart_snapshot should contain at least one line');
        $this->assertEquals($lineId, $resApprovalReq->json('cart_snapshot.lines.0.line_id'), 'cart_snapshot line should have correct line_id');

        // Verify pending_approvals is present in the immediate response
        $linePendingApprovals = $resApprovalReq->json('cart_snapshot.lines.0.pending_approvals');
        $this->assertIsArray($linePendingApprovals, 'pending_approvals should be an array in immediate response');
        $this->assertNotEmpty($linePendingApprovals, 'pending_approvals should contain the QTY_REDUCE request');

        $qtyReduceApproval = collect($linePendingApprovals)
            ->firstWhere('action_type', 'QTY_REDUCE');
        $this->assertNotNull($qtyReduceApproval, 'QTY_REDUCE approval should be in pending_approvals');
        $this->assertEquals('PENDING', $qtyReduceApproval['status']);
        $this->assertEquals($requestId, $qtyReduceApproval['request_id']);

        // Verify that cart snapshot includes pending_approvals with the request
        $resCartShow = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->getJson(route('pos.sell.cart.show'));

        $resCartShow->assertStatus(200);
        $pendingApprovals = $resCartShow->json('cart_snapshot.lines.0.pending_approvals');
        $this->assertNotEmpty($pendingApprovals, 'pending_approvals should not be empty');

        $qtyReduceApproval = collect($pendingApprovals)
            ->firstWhere('action_type', 'QTY_REDUCE');
        $this->assertNotNull($qtyReduceApproval, 'QTY_REDUCE approval should exist in pending_approvals');
        $this->assertEquals('PENDING', $qtyReduceApproval['status']);
        $this->assertEquals($requestId, $qtyReduceApproval['request_id']);
    }

    // Task 4.2: Test qty-approval visibility after cart snapshot reload/page refresh
    public function test_qty_approval_visible_after_cart_reload(): void
    {
        $setting = $this->createSetting('BIZ POS QTY RELOAD', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'QTY RELOAD TEST', []);

        $supervisor = $this->createUserForSetting(
            $setting,
            'QTY RELOAD SUPERVISOR',
            ['pos.access', 'pos.supervisor.approval', 'pos.cart.line.reduce']
        );

        $product = $this->createStockedProduct($setting, $location, 'SKU-RELOAD-1', 'Reload Test Product', 10000, $cashier->id);

        // Add product to cart
        $resStore = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 5]);
        $lineId = $resStore->json('cart_snapshot.lines.0.line_id');

        // Submit qty reduction approval request
        $resApprovalReq = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.approval-requests.store'), [
                 'action_type' => 'QTY_REDUCE',
                 'target_type' => 'pos_cart_line',
                 'target_id' => $lineId,
                 'payload' => ['qty' => 2]
             ]);

        $requestId = $resApprovalReq->json('request_id');

        // Reload cart (simulating page refresh)
        $resReload = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->getJson(route('pos.sell.cart.show'));

        $resReload->assertStatus(200);

        // Verify that pending approval is still visible in reloaded cart
        $pendingApprovals = $resReload->json('cart_snapshot.lines.0.pending_approvals');
        $this->assertNotEmpty($pendingApprovals, 'pending_approvals should still exist after reload');

        $qtyReduceApproval = collect($pendingApprovals)
            ->firstWhere('action_type', 'QTY_REDUCE');
        $this->assertNotNull($qtyReduceApproval, 'QTY_REDUCE approval should remain visible after reload');
        $this->assertEquals('PENDING', $qtyReduceApproval['status']);
        $this->assertEquals($requestId, $qtyReduceApproval['request_id']);
    }

    // Test: Submit second qty approval request on same line while first is approved (multi-request scenario)
    public function test_second_qty_reduction_request_cancels_previous_approved_request(): void
    {
        $setting = $this->createSetting('BIZ POS QTY MULTI-REQUEST', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'QTY MULTI TEST', []);

        $supervisor = $this->createUserForSetting(
            $setting,
            'QTY MULTI SUPERVISOR',
            ['pos.access', 'pos.supervisor.approval', 'pos.cart.line.reduce']
        );

        $product = $this->createStockedProduct($setting, $location, 'SKU-MULTI-1', 'Multi-Request Test Product', 10000, $cashier->id);

        // 1. Add product to cart
        $resStore = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 10]);
        $lineId = $resStore->json('cart_snapshot.lines.0.line_id');

        // 2. Submit first qty reduction approval request
        $res1stRequest = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.approval-requests.store'), [
                 'action_type' => 'QTY_REDUCE',
                 'target_type' => 'pos_cart_line',
                 'target_id' => $lineId,
                 'payload' => ['qty' => 5]
             ]);

        $res1stRequest->assertStatus(201);
        $requestId1 = $res1stRequest->json('request_id');

        // 3. Supervisor approves the first request
        $this->actingAs($supervisor)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.supervisor.approval-requests.approve', ['id' => $requestId1]))
             ->assertStatus(200);

        // 4. Verify first request is now APPROVED
        $status1 = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->getJson(route('pos.sell.approval-requests.show', ['id' => $requestId1]));
        $status1->assertStatus(200);
        $status1->assertJsonPath('status', PosActionApprovalRequest::STATUS_APPROVED);

        // 5. Cashier submits SECOND qty reduction request on same line (without using first token)
        $res2ndRequest = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.approval-requests.store'), [
                 'action_type' => 'QTY_REDUCE',
                 'target_type' => 'pos_cart_line',
                 'target_id' => $lineId,
                 'payload' => ['qty' => 3]
             ]);

        $res2ndRequest->assertStatus(201);
        $requestId2 = $res2ndRequest->json('request_id');

        // 6. Verify first request is NOW CANCELLED in the database
        $firstRequest = PosActionApprovalRequest::find($requestId1);
        $this->assertEquals(
            PosActionApprovalRequest::STATUS_CANCELLED,
            $firstRequest->status,
            'First APPROVED request should be CANCELLED when second request is created'
        );

        // 7. Verify second request is PENDING
        $secondRequest = PosActionApprovalRequest::find($requestId2);
        $this->assertEquals(
            PosActionApprovalRequest::STATUS_PENDING,
            $secondRequest->status,
            'Second request should be PENDING'
        );

        // 8. Verify cart_snapshot.pending_approvals contains ONLY the second request (no duplicates)
        $cartSnapshot = $res2ndRequest->json('cart_snapshot');
        $linePendingApprovals = $cartSnapshot['lines'][0]['pending_approvals'];
        $qtyReduceApprovals = collect($linePendingApprovals)->filter(fn ($a) => $a['action_type'] === 'QTY_REDUCE')->all();

        $this->assertCount(
            1,
            $qtyReduceApprovals,
            'Should have exactly ONE QTY_REDUCE in pending_approvals, not two'
        );

        // 9. Verify it's the second (newer) request
        $latestQtyApproval = reset($qtyReduceApprovals);
        $this->assertEquals(
            $requestId2,
            $latestQtyApproval['request_id'],
            'pending_approvals should contain the latest request_id (second request)'
        );
        $this->assertEquals('PENDING', $latestQtyApproval['status']);
    }
}
