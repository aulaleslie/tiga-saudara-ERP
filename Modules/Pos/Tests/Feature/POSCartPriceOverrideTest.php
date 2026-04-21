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

class POSCartPriceOverrideTest extends TestCase
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

    // Task 6.1: Privileged user can override price to a positive value directly
    public function test_privileged_user_can_override_price_directly(): void
    {
        $setting = $this->createSetting('BIZ POS PRICE PRIV', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'PRIV', ['pos.overrides.price']);

        $product = $this->createStockedProduct($setting, $location, 'SKU-P1', 'Product P1', 10000, $cashier->id);

        $resStore = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1]);
        $lineId = $resStore->json('cart_snapshot.lines.0.line_id');

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.price-override', ['lineId' => $lineId]), [
                 'unit_price' => 15000
             ])
             ->assertStatus(200)
             ->assertJsonPath('cart_snapshot.lines.0.unit_price', 15000);
    }

    // Task 6.2: Privileged user can override price to zero directly
    public function test_privileged_user_can_override_price_to_zero_directly(): void
    {
        $setting = $this->createSetting('BIZ POS PRICE ZERO', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'ZERO', ['pos.overrides.price']);

        $product = $this->createStockedProduct($setting, $location, 'SKU-Z1', 'Product Z1', 10000, $cashier->id);

        $resStore = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1]);
        $lineId = $resStore->json('cart_snapshot.lines.0.line_id');

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.price-override', ['lineId' => $lineId]), [
                 'unit_price' => 0
             ])
             ->assertStatus(200)
             ->assertJsonPath('cart_snapshot.lines.0.unit_price', 0);
    }

    // Task 6.3: Non-privileged user attempting price override receives APPROVAL_REQUIRED
    public function test_non_privileged_user_requires_approval_for_price_override(): void
    {
        $setting = $this->createSetting('BIZ POS PRICE NON-PRIV', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'NON-PRIV', []);

        $product = $this->createStockedProduct($setting, $location, 'SKU-NP1', 'Product NP1', 10000, $cashier->id);

        $resStore = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1]);
        $lineId = $resStore->json('cart_snapshot.lines.0.line_id');

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.price-override', ['lineId' => $lineId]), [
                 'unit_price' => 8000
             ])
             ->assertStatus(422)
             ->assertJsonPath('message', 'APPROVAL_REQUIRED');
    }

    // Task 6.4: Non-privileged user with valid approval token can apply approved price
    public function test_non_privileged_user_can_apply_approved_price_with_token(): void
    {
        $setting = $this->createSetting('BIZ POS PRICE TOKEN', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'TOKEN-CASHIER', []);

        $supervisor = $this->createUserForSetting(
            $setting,
            'TOKEN-SUPERVISOR',
            ['pos.access', 'pos.supervisor.approval', 'pos.overrides.price']
        );

        $product = $this->createStockedProduct($setting, $location, 'SKU-T1', 'Product T1', 10000, $cashier->id);

        $resStore = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1]);
        $lineId = $resStore->json('cart_snapshot.lines.0.line_id');

        // 1. Request approval
        $resReq = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.approval-requests.store'), [
                 'action_type' => 'PRICE_OVERRIDE',
                 'target_type' => 'pos_cart_line',
                 'target_id' => $lineId,
                 'payload' => ['unit_price' => 5000]
             ]);
        $requestId = $resReq->json('request_id');

        // 2. Approve
        $this->actingAs($supervisor)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.supervisor.approval-requests.approve', ['id' => $requestId]))
             ->assertStatus(200);

        // 3. Get token
        $resToken = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->getJson(route('pos.sell.approval-requests.show', ['id' => $requestId]));
        $token = $resToken->json('approval_token');

        // 4. Apply with token
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.price-override', ['lineId' => $lineId]), [
                 'unit_price' => 5000,
                 'approval_token' => $token
             ])
             ->assertStatus(200)
             ->assertJsonPath('cart_snapshot.lines.0.unit_price', 5000);
    }

    // Task 6.5: Negative price is rejected by validation
    public function test_negative_price_is_rejected(): void
    {
        $setting = $this->createSetting('BIZ POS PRICE NEG', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'NEG', ['pos.overrides.price']);

        $product = $this->createStockedProduct($setting, $location, 'SKU-N1', 'Product N1', 10000, $cashier->id);

        $resStore = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1]);
        $lineId = $resStore->json('cart_snapshot.lines.0.line_id');

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.price-override', ['lineId' => $lineId]), [
                 'unit_price' => -100
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['unit_price']);
    }

    // Task 6.6: Snapshot includes requested_unit_price for PRICE_OVERRIDE approvals
    public function test_snapshot_includes_requested_unit_price(): void
    {
        $setting = $this->createSetting('BIZ POS PRICE SNAP', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SNAP', []);

        $product = $this->createStockedProduct($setting, $location, 'SKU-S1', 'Product S1', 10000, $cashier->id);

        $resStore = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1]);
        $lineId = $resStore->json('cart_snapshot.lines.0.line_id');

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.approval-requests.store'), [
                 'action_type' => 'PRICE_OVERRIDE',
                 'target_type' => 'pos_cart_line',
                 'target_id' => $lineId,
                 'payload' => ['unit_price' => 7500]
             ]);

        $resSnap = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->getJson(route('pos.sell.cart.show'));

        $resSnap->assertStatus(200);
        $pending = $resSnap->json('cart_snapshot.lines.0.pending_approvals.0');
        $this->assertEquals('PRICE_OVERRIDE', $pending['action_type']);
        $this->assertEquals(7500, $pending['requested_unit_price']);
    }
}
