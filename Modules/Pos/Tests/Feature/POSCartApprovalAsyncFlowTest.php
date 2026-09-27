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

class POSCartApprovalAsyncFlowTest extends TestCase
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
            'pos.cart.clear',
            'pos.cart.line.remove',
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

    public function test_async_approval_flow_success(): void
    {
        $setting = $this->createSetting('BIZ POS ASYNC 1', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'POS ASYNC 1', []);
        
        $supervisor = $this->createUserForSetting(
            $setting,
            'POS ASYNC 1 SUP',
            ['pos.access', 'pos.supervisor.approval', 'pos.cart.clear', 'pos.cart.line.remove', 'pos.cart.line.reduce']
        );

        $product = $this->createStockedProduct($setting, $location, 'SKU-A1', 'Product A1', 10000, $cashier->id);

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1]);

        // 1. Cashier Requests Approval
        $res = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.approval-requests.store'), [
                 'action_type' => 'CART_CLEAR',
                 'target_type' => 'pos_session',
                 'target_id' => $session->id,
             ]);
        
        $res->assertStatus(201);
        $requestId = $res->json('request_id');

        // 2. Supervisor Approves
        $this->actingAs($supervisor)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.supervisor.approval-requests.approve', ['id' => $requestId]))
             ->assertStatus(200);

        // 3. Cashier Checks Status & Gets Token
        $statusRes = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->getJson(route('pos.sell.approval-requests.show', ['id' => $requestId]));
        
        $statusRes->assertStatus(200);
        $this->assertEquals('APPROVED', $statusRes->json('status'));
        $token = $statusRes->json('approval_token');
        $this->assertNotEmpty($token);

        // 4. Cashier Uses Token to Clear Cart
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->deleteJson(route('pos.sell.cart.clear', ['approval_token' => $token]))
             ->assertStatus(200);

        // 5. Token is Consumed, Re-use Fails
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->deleteJson(route('pos.sell.cart.clear', ['approval_token' => $token]))
             ->assertStatus(422)
             ->assertJsonPath('message', 'TOKEN_ALREADY_USED');
    }

    public function test_async_approval_flow_rejected(): void
    {
        $setting = $this->createSetting('BIZ POS ASYNC 2', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'POS ASYNC 2', []);
        
        $supervisor = $this->createUserForSetting(
            $setting,
            'POS ASYNC 2 SUP',
            ['pos.access', 'pos.supervisor.approval', 'pos.cart.clear']
        );

        $product = $this->createStockedProduct($setting, $location, 'SKU-A2', 'Product A2', 10000, $cashier->id);

        // 1. Cashier Requests Approval
        $res = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.approval-requests.store'), [
                 'action_type' => 'CART_CLEAR',
                 'target_type' => 'pos_session',
                 'target_id' => $session->id,
             ]);
        
        $res->assertStatus(201);
        $requestId = $res->json('request_id');

        // 2. Supervisor Rejects
        $this->actingAs($supervisor)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.supervisor.approval-requests.reject', ['id' => $requestId]), [
                 'reason' => 'Not allowed'
             ])
             ->assertStatus(200);

        // 3. Cashier Checks Status
        $statusRes = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->getJson(route('pos.sell.approval-requests.show', ['id' => $requestId]));
        
        $statusRes->assertStatus(200);
        $this->assertEquals('REJECTED', $statusRes->json('status'));
        $this->assertNull($statusRes->json('approval_token'));
    }

    public function test_cancel_after_approval_invalidates_token_and_keeps_cart_unchanged(): void
    {
        $setting = $this->createSetting('BIZ POS ASYNC 3', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'POS ASYNC 3', []);

        $supervisor = $this->createUserForSetting(
            $setting,
            'POS ASYNC 3 SUP',
            ['pos.access', 'pos.supervisor.approval', 'pos.cart.clear']
        );

        $product = $this->createStockedProduct($setting, $location, 'SKU-A3', 'Product A3', 10000, $cashier->id);

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $requestId = (int) $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.approval-requests.store'), [
                'action_type' => 'CART_CLEAR',
                'target_type' => 'pos_session',
                'target_id' => $session->id,
            ])
            ->assertStatus(201)
            ->json('request_id');

        $this->actingAs($supervisor)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.supervisor.approval-requests.approve', ['id' => $requestId]))
            ->assertOk();

        $token = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.approval-requests.show', ['id' => $requestId]))
            ->assertOk()
            ->json('approval_token');

        $this->assertNotEmpty($token);

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.approval-requests.cancel', ['id' => $requestId]))
            ->assertOk();

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->deleteJson(route('pos.sell.cart.clear', ['approval_token' => $token]))
            ->assertStatus(422);

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->assertJsonPath('cart_snapshot.meta.line_count', 1);
    }
}
