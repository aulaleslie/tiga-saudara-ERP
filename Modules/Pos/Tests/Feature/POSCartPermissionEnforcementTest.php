<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Setting\Entities\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;
use App\Support\SalesLocationResolver;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class POSCartPermissionEnforcementTest extends TestCase
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

    public function test_cart_clear_requires_permission(): void
    {
        $setting = $this->createSetting('BIZ POS CART AUTH', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'POS CART AUTH', []);
        $product = $this->createStockedProduct($setting, $location, 'SKU-1', 'Product 1', 10000, $cashier->id);

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1]);

        // Attempt without permission -> fails
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->deleteJson(route('pos.sell.cart.clear'))
             ->assertStatus(422)
             ->assertJsonPath('message', 'APPROVAL_REQUIRED');

        // Give permission and try again
        $cashier->givePermissionTo('pos.cart.clear');
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->deleteJson(route('pos.sell.cart.clear'))
             ->assertStatus(200);
    }

    public function test_cart_remove_line_requires_permission(): void
    {
        $setting = $this->createSetting('BIZ POS CART AUTH 2', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'POS CART AUTH 2', []);
        $product = $this->createStockedProduct($setting, $location, 'SKU-2', 'Product 2', 10000, $cashier->id);

        $res = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1]);
        $lineId = $res->json('cart_snapshot.lines.0.line_id');

        // Attempt without permission -> fails
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->deleteJson(route('pos.sell.cart.lines.destroy', ['lineId' => $lineId]))
             ->assertStatus(422)
             ->assertJsonPath('message', 'APPROVAL_REQUIRED');

        // Give permission and try again
        $cashier->givePermissionTo('pos.cart.line.remove');
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->deleteJson(route('pos.sell.cart.lines.destroy', ['lineId' => $lineId]))
             ->assertStatus(200);
    }

    public function test_cart_reduce_qty_requires_permission(): void
    {
        $setting = $this->createSetting('BIZ POS CART AUTH 3', true);
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'POS CART AUTH 3', []);
        $product = $this->createStockedProduct($setting, $location, 'SKU-3', 'Product 3', 10000, $cashier->id);

        $res = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 2]);
        $lineId = $res->json('cart_snapshot.lines.0.line_id');

        // Attempt without permission -> fails
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), ['qty' => 1])
             ->assertStatus(422)
             ->assertJsonPath('message', 'APPROVAL_REQUIRED');

        // Give permission and try again
        $cashier->givePermissionTo('pos.cart.line.reduce');
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
             ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), ['qty' => 1])
             ->assertStatus(200);
    }
}
