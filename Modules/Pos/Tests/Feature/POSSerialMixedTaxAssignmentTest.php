<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSSerialMixedTaxAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

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

        foreach (['pos.access', 'pos.sell', 'pos.sessions.open'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_mixed_taxed_and_nontaxed_serials_can_be_assigned_via_store(): void
    {
        $context = $this->createCheckoutContext('MixedTaxStore');
        $tax = Tax::create(['name' => 'VAT 11%', 'value' => 11]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-MIX-1', 100000, true);

        $snTaxed = $this->createSerialNumber($product, $context['location'], 'SN-TAXED-1', $tax->id);
        $snNonTaxed = $this->createSerialNumber($product, $context['location'], 'SN-NONTAX-1', null);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign both taxed and non-taxed serials to the same line
        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-TAXED-1', 'SN-NONTAX-1'],
            ])
            ->assertOk();

        $assignedSerials = $response->json('cart_snapshot.lines.0.assigned_serials');
        $this->assertContains('SN-TAXED-1', $assignedSerials);
        $this->assertContains('SN-NONTAX-1', $assignedSerials);
    }

    public function test_mixed_taxed_and_nontaxed_serials_can_be_appended(): void
    {
        $context = $this->createCheckoutContext('MixedTaxAppend');
        $tax = Tax::create(['name' => 'VAT 11%', 'value' => 11]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-MIX-2', 100000, true);

        $snTaxed = $this->createSerialNumber($product, $context['location'], 'SN-TAXED-2', $tax->id);
        $snNonTaxed = $this->createSerialNumber($product, $context['location'], 'SN-NONTAX-2', null);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // First append the taxed serial
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => 'SN-TAXED-2',
            ])
            ->assertOk();

        // Then append the non-taxed serial
        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => 'SN-NONTAX-2',
            ])
            ->assertOk();

        $assignedSerials = $response->json('cart_snapshot.lines.0.assigned_serials');
        $this->assertContains('SN-TAXED-2', $assignedSerials);
        $this->assertContains('SN-NONTAX-2', $assignedSerials);
    }

    public function test_taxed_serial_on_nontax_line_is_allowed(): void
    {
        $context = $this->createCheckoutContext('TaxedOnNonTax');
        $tax = Tax::create(['name' => 'VAT 11%', 'value' => 11]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-MIX-3', 100000, true);

        // Create a taxed serial
        $this->createSerialNumber($product, $context['location'], 'SN-TAXED-3', $tax->id);

        // Add line (non-taxed line since product has no tax_id)
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assigning taxed serial to non-tax line should succeed
        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-TAXED-3'],
            ])
            ->assertOk();

        $this->assertEquals(['SN-TAXED-3'], $response->json('cart_snapshot.lines.0.assigned_serials'));
    }

    // ==== HELPER METHODS ====

    private function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        $cashier = $this->createUserForSetting($setting, $name . '-cashier', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $session = $sessionLifecycle->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );

        return [
            'setting' => $setting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'location' => $location,
            'session' => $session,
        ];
    }

    private function createSetting(string $name): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'pos.mix.' . $suffix . '@example.com',
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
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => strtoupper($roleName) . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $index = $this->sequence++;

        $location = Location::create([
            'name' => 'POS MIX LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-MIX-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Mix Terminal ' . $index,
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
        float $salePrice,
        bool $serialRequired
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => $code . '-CAT'],
            [
                'category_name' => $code . ' CATEGORY',
                'created_by' => 1,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT',
            'short_name' => 'PUNIT',
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => $code . '-BAR',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => $salePrice,
            'product_unit' => 'PUNIT',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => $serialRequired,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        return $product;
    }

    private function createSerialNumber(Product $product, Location $location, string $serialNumber, ?int $taxId): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $serialNumber,
            'tax_id' => $taxId,
            'status' => 'ACTIVE',
        ]);
    }

        private function selectCustomerInCart(User $cashier, Setting $setting, Customer $customer): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer->id,
            ])
            ->assertOk();
    }

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
            ])
            ->assertOk();
    }

    private function cartSnapshot(User $cashier, Setting $setting): array
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot');
    }
}
