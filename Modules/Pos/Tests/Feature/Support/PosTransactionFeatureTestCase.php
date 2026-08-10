<?php

namespace Modules\Pos\Tests\Feature\Support;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

abstract class PosTransactionFeatureTestCase extends TestCase
{
    use RefreshDatabase;

    private int $terminalSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

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
            'pos.checkout.payment',
            'pos.sessions.open',
            'pos.sessions.view',
            'pos.cart.clear',
            'pos.cart.line.remove',
            'pos.cart.line.reduce',
            'pos.supervisor.approval',
            'pos.transactions.view',
            'pos.transactions.save',
            'pos.transactions.load',
            'pos.transactions.edit.any',
            'pos.void',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    protected function createSetting(string $name, bool $isPkp = false): Setting
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
            'pos_transactions_enabled' => true,
            'is_pkp' => $isPkp,
        ]);
    }

    protected function createUserForSetting(
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
            'password' => Hash::make($password ?? 'secret'),
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    /**
     * @return array{0: PosTerminal, 1: Location}
     */
    protected function createTerminalWithLocation(Setting $setting): array
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'POS TXN LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-TXN-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Txn Terminal ' . $sequence,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => false,
            'cash_threshold' => 50000,
        ]);

        return [$terminal, $location];
    }

    protected function openSession(Setting $setting, PosTerminal $terminal, User $cashier): PosSession
    {
        return PosSession::create([
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
    }

    protected function createStockedProduct(Setting $setting, Location $location, array $overrides = []): Product
    {
        $code = $overrides['product_code'] ?? ('SKU-TXN-' . strtoupper(substr(md5((string) microtime(true)), 0, 6)));
        $name = $overrides['product_name'] ?? ('Produk TXN ' . $code);
        $salePrice = (float) ($overrides['sale_price'] ?? 10000);
        $saleTaxId = $overrides['sale_tax_id'] ?? null;
        $serialRequired = (bool) ($overrides['serial_number_required'] ?? false);
        $stockQty = (int) ($overrides['stock_qty'] ?? 30);
        $createdBy = User::query()->value('id') ?? User::factory()->create(['is_active' => true])->id;

        $category = Category::firstOrCreate(
            ['category_code' => 'POS-TXN-CAT-' . $setting->id],
            [
                'category_name' => 'POS TXN Category ' . $setting->id,
                'setting_id' => $setting->id,
                'created_by' => $createdBy,
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
            'product_quantity' => $stockQty,
            'product_cost' => 5000,
            'product_price' => $salePrice,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => $serialRequired,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $stockQty,
            'quantity_non_tax' => $stockQty,
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

        return $product;
    }

    protected function createNonStockProduct(Setting $setting, string $code, string $name, float $salePrice): Product
    {
        $createdBy = User::query()->value('id') ?? User::factory()->create(['is_active' => true])->id;

        $category = Category::firstOrCreate(
            ['category_code' => 'POS-NON-STOCK-CAT-' . $setting->id],
            [
                'category_name' => 'POS Non-Stock Category ' . $setting->id,
                'setting_id' => $setting->id,
                'created_by' => $createdBy,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'Service',
            'short_name' => 'SVC',
        ]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $code . '-BC',
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => $salePrice,
            'product_unit' => 'SVC',
            'product_stock_alert' => 0,
            'stock_managed' => false,  // Non-stock product
            'is_sold' => true,  // Sellable despite no inventory
            'serial_number_required' => false,
        ]);

        // Non-stock products do NOT have product stock records
        // They are sold but do not consume inventory

        ProductPrice::updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 0,
            'average_purchase_price' => 0,
            'purchase_tax_id' => null,
            'sale_tax_id' => null,
        ]);

        return $product;
    }

    protected function createSerialNumber(Product $product, Location $location, string $serialNumber): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $serialNumber,
            'status' => 'ACTIVE',
            'dispatch_detail_id' => null,
        ]);
    }

    protected function actingAsInSetting(User $user, Setting $setting): void
    {
        $this->actingAs($user);
        $this->withSession(['setting_id' => $setting->id]);
    }
}
