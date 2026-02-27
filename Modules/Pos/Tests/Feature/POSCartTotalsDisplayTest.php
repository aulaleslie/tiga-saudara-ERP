<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSCartTotalsDisplayTest extends TestCase
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

    public function test_cart_routes_follow_pos_session_guard_when_no_active_session_exists(): void
    {
        $setting = $this->createSetting('BIZ POS CART GUARD', true);
        $cashier = $this->createUserForSetting(
            $setting,
            'POS CART CASHIER NO SESSION',
            ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.overrides.price']
        );

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell.cart.show'))
            ->assertRedirect(route('pos.sessions.create'))
            ->assertSessionHas('warning', 'Active POS session is required before accessing POS sell screen.');
    }

    public function test_add_line_increments_existing_line_and_returns_estimated_tax_snapshot(): void
    {
        $setting = $this->createSetting('BIZ POS CART ADD', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'POS CART ADD');
        $tax = $this->createTax('PPN 11%', 11);
        $product = $this->createStockedProduct($setting, $location, 'SKU-CART-01', 'Kopi Hitam', 10000, $tax->id, $cashier->id);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.product_id', $product->id)
            ->assertJsonPath('cart_snapshot.lines.0.qty', 1)
            ->assertJsonPath('cart_snapshot.meta.tax_display_mode', 'ESTIMATED')
            ->assertJsonPath('cart_snapshot.meta.tax_mode', 'EXCLUDED');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 2,
            ]);

        $response->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.qty', 3)
            ->assertJsonPath('cart_snapshot.totals.subtotal', 30000)
            ->assertJsonPath('cart_snapshot.totals.tax_total', 3300)
            ->assertJsonPath('cart_snapshot.totals.grand_total', 33300);
    }

    public function test_line_and_bill_discount_recalculate_totals_deterministically(): void
    {
        $setting = $this->createSetting('BIZ POS CART STACK', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'POS CART STACK');
        $tax = $this->createTax('PPN 11% STACK', 11);
        $productA = $this->createStockedProduct($setting, $location, 'SKU-A', 'Produk A', 10000, $tax->id, $cashier->id);
        $productB = $this->createStockedProduct($setting, $location, 'SKU-B', 'Produk B', 20000, $tax->id, $cashier->id);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $productA->id, 'qty' => 1])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $productB->id, 'qty' => 1])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $productA->id]), [
                'line_discount_type' => 'percentage',
                'line_discount_value' => 10,
            ])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $productB->id]), [
                'line_discount_type' => 'fixed',
                'line_discount_value' => 2000,
            ])
            ->assertOk();

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.discount.update'), [
                'bill_discount_type' => 'percentage',
                'bill_discount_value' => 10,
            ]);

        $response->assertOk()
            ->assertJsonPath('cart_snapshot.totals.subtotal', 24300)
            ->assertJsonPath('cart_snapshot.totals.discount_total', 5700)
            ->assertJsonPath('cart_snapshot.totals.tax_total', 2673)
            ->assertJsonPath('cart_snapshot.totals.grand_total', 26973)
            ->assertJsonPath('cart_snapshot.lines.0.bill_discount_amount', 900)
            ->assertJsonPath('cart_snapshot.lines.1.bill_discount_amount', 1800)
            ->assertJsonPath('cart_snapshot.meta.tax_display_mode', 'ESTIMATED')
            ->assertJsonPath('cart_snapshot.meta.tax_mode', 'EXCLUDED');
    }

    public function test_price_override_rejected_with_invalid_supervisor_pin(): void
    {
        $setting = $this->createSetting('BIZ POS CART PIN FAIL', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'POS CART PIN FAIL', true);
        $tax = $this->createTax('PPN 11% PIN FAIL', 11);
        $product = $this->createStockedProduct($setting, $location, 'SKU-PIN-F', 'Produk PIN Gagal', 10000, $tax->id, $cashier->id);

        $supervisor = $this->createUserForSetting(
            $setting,
            'POS CART SUPERVISOR FAIL',
            ['pos.overrides.price', 'pos.supervisor.approval'],
            'supervisor.pos.fail@example.com',
            'correct-pin'
        );

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.price-override', ['lineId' => $product->id]), [
                'unit_price' => 9000,
                'supervisor_identifier' => $supervisor->email,
                'supervisor_pin' => 'wrong-pin',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Supervisor approval failed for price override.');

        $this->assertDatabaseHas('pos_supervisor_approvals', [
            'setting_id' => $setting->id,
            'action_type' => 'PRICE_OVERRIDE',
            'target_type' => 'pos_session',
            'target_id' => $session->id,
            'requested_by' => $cashier->id,
            'approved_by' => null,
            'approval_result' => 'REJECTED',
            'reason' => 'INVALID_CREDENTIALS',
        ]);
    }

    public function test_price_override_success_updates_line_price_and_logs_approval(): void
    {
        $setting = $this->createSetting('BIZ POS CART PIN OK', true);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'POS CART PIN OK', true);
        $tax = $this->createTax('PPN 11% PIN OK', 11);
        $product = $this->createStockedProduct($setting, $location, 'SKU-PIN-S', 'Produk PIN Sukses', 10000, $tax->id, $cashier->id);

        $supervisor = $this->createUserForSetting(
            $setting,
            'POS CART SUPERVISOR OK',
            ['pos.overrides.price', 'pos.supervisor.approval'],
            'supervisor.pos.ok@example.com',
            'supervisor-pin'
        );

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.price-override', ['lineId' => $product->id]), [
                'unit_price' => 9000,
                'supervisor_identifier' => $supervisor->email,
                'supervisor_pin' => 'supervisor-pin',
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.unit_price', 9000)
            ->assertJsonPath('cart_snapshot.totals.subtotal', 9000)
            ->assertJsonPath('cart_snapshot.totals.tax_total', 990)
            ->assertJsonPath('cart_snapshot.totals.grand_total', 9990);

        $this->assertDatabaseHas('pos_supervisor_approvals', [
            'setting_id' => $setting->id,
            'action_type' => 'PRICE_OVERRIDE',
            'target_type' => 'pos_session',
            'target_id' => $session->id,
            'requested_by' => $cashier->id,
            'approved_by' => $supervisor->id,
            'approval_result' => 'APPROVED',
        ]);
    }

    public function test_cart_mutations_do_not_write_sales_payment_or_dispatch_tables(): void
    {
        $setting = $this->createSetting('BIZ POS CART NO POST', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'POS CART NO POST', true);
        $tax = $this->createTax('PPN 11% NO POST', 11);
        $product = $this->createStockedProduct($setting, $location, 'SKU-NO-POST', 'Produk No Post', 15000, $tax->id, $cashier->id);

        $salesBefore = DB::table('sales')->count();
        $paymentsBefore = DB::table('sale_payments')->count();
        $dispatchBefore = DB::table('dispatches')->count();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 2])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.discount.update'), [
                'bill_discount_type' => 'fixed',
                'bill_discount_value' => 5000,
            ])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->deleteJson(route('pos.sell.cart.clear'))
            ->assertOk();

        $this->assertSame($salesBefore, DB::table('sales')->count());
        $this->assertSame($paymentsBefore, DB::table('sale_payments')->count());
        $this->assertSame($dispatchBefore, DB::table('dispatches')->count());
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

    /**
     * @return array{0: User, 1: Location, 2: PosSession}
     */
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
        $location = Location::query()->findOrFail($terminal->location_id);

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

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-CART-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Cart Terminal ' . $sequence,
            'location_id' => $location->id,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
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
