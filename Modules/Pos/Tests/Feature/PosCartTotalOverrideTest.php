<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSupervisorApproval;
use Modules\Pos\Services\PosCartService;
use Modules\Pos\Services\PosCartTotalAllocationService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosCartTotalOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected PosCartService $cartService;
    protected PosCartTotalAllocationService $allocationService;
    protected User $user;
    protected Setting $setting;
    protected PosSession $session;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cartService = app(PosCartService::class);
        $this->allocationService = app(PosCartTotalAllocationService::class);

        // Create currency
        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        // Clear cached permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.overrides.total-price',
            'pos.supervisor.approval',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $role->syncPermissions(['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.overrides.total-price', 'pos.supervisor.approval']);

        $this->setting = Setting::create([
            'company_name' => 'TEST POS SETTING',
            'company_email' => 'pos@example.com',
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
            'is_pkp' => false,
        ]);

        $this->user = User::factory()->create([
            'email' => 'cashier.test@example.com',
            'is_active' => true,
        ]);
        $this->user->assignRole($role);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);
        $this->location = Location::factory()->create(['setting_id' => $this->setting->id]);

        $this->session = PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => null,
            'cashier_user_id' => $this->user->id,
            'opened_by' => $this->user->id,
            'opened_at' => now(),
            'status' => PosSession::STATUS_OPEN,
            'active_marker' => 1,
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    private function createProduct(int $price = 50000): Product
    {
        $category = Category::firstOrCreate(
            ['category_code' => 'TEST-CAT'],
            [
                'category_name' => 'Test Category',
                'created_by' => 1,
                'setting_id' => $this->setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT',
            'short_name' => 'PUNIT',
        ]);

        $product = Product::query()->create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-' . uniqid(),
            'barcode' => 'TEST-BAR-' . uniqid(),
            'product_quantity' => 100,
            'product_cost' => $price / 2,
            'product_price' => $price,
            'product_unit' => 'PUNIT',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        // Create price for the setting
        ProductPrice::query()->create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'price' => $price,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        return $product;
    }

    public function test_cart_total_override_endpoint_returns_retired_error_and_does_not_mutate_cart(): void
    {
        $this->user->givePermissionTo('pos.overrides.total-price');

        // Add a line: Qty 2 @ Rp 50.000 = Rp 100.000
        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        $initialSnapshot = $this->cartService->getSnapshot($this->setting->id, $this->session->id);
        $this->assertEquals(10000000, (int) round($initialSnapshot['totals']['grand_total'] * 100));

        // Call retired total-override route
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('pos.sell.cart.total-override.store'), [
                'target_total' => 8000000,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'retired')
            ->assertJsonPath('code', 'FEATURE_RETIRED');

        // Cart totals and lines must remain unaffected
        $afterSnapshot = $this->cartService->getSnapshot($this->setting->id, $this->session->id);
        $this->assertEquals(10000000, (int) round($afterSnapshot['totals']['grand_total'] * 100));
        $this->assertNull($afterSnapshot['total_price_override_approval'] ?? null);
    }

    public function test_cart_override_price_route_returns_retired_error_and_does_not_mutate_cart(): void
    {
        $this->user->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Call retired line price-override route
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('pos.sell.cart.lines.price-override', ['lineId' => 1]), [
                'unit_price' => 40000,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'retired')
            ->assertJsonPath('code', 'FEATURE_RETIRED');

        // Verify cart is unchanged
        $afterSnapshot = $this->cartService->getSnapshot($this->setting->id, $this->session->id);
        $this->assertEquals(10000000, (int) round($afterSnapshot['totals']['grand_total'] * 100));
    }

    public function test_service_override_total_price_throws_retirement_exception(): void
    {
        $this->user->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Fitur penyesuaian total keranjang telah digantikan');

        $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            null,
            null,
            $this->user
        );
    }

    public function test_approval_request_store_rejects_total_price_override_creation(): void
    {
        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->postJson(route('pos.sell.approval-requests.store'), [
                'action_type' => 'TOTAL_PRICE_OVERRIDE',
                'target_type' => 'pos_session',
                'target_id' => $this->session->id,
                'payload' => [
                    'target_total' => 8000000,
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_active_cart_snapshot_omits_total_price_override_approval(): void
    {
        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        $snapshot = $this->cartService->getSnapshot($this->setting->id, $this->session->id);

        $this->assertArrayNotHasKey('total_price_override_approval', $snapshot);
    }
}
