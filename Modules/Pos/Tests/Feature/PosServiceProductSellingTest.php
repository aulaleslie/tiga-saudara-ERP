<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use App\Support\SalesLocationResolver;
use Modules\Setting\Entities\Location;

class PosServiceProductSellingTest extends TestCase
{
    use RefreshDatabase;

    private int $terminalSequence = 1;

    public function test_service_product_is_searchable_and_scannable(): void
    {
        $setting = $this->createSetting('BIZ SERVICE');
        [$cashier, $allowedLocation] = $this->createCashierAndOpenSession($setting, 'POS SERVICE');

        // Create a non-stock-managed service product
        $serviceProduct = $this->createServiceProduct(
            setting: $setting,
            code: 'SRV-01',
            name: 'Jasa Pemasangan',
            barcode: 'SRV-01-BARCODE',
            salePrice: 50000,
            createdBy: $cashier->id
        );

        // 1. Search returns non-stock-managed product
        $searchResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.products.search', ['q' => 'Jasa']));

        $searchResponse->assertOk();
        $resultIds = collect($searchResponse->json('results'))->pluck('id')->all();
        $this->assertContains($serviceProduct->id, $resultIds);

        // 2. Scan resolves non-stock-managed product
        $scanResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => 'SRV-01-BARCODE']));

        $scanResponse->assertOk();
        $scanResponse->assertJsonPath('type', 'product_exact');
        $scanResponse->assertJsonPath('product.id', $serviceProduct->id);
    }

    public function test_service_product_can_be_added_to_cart_without_stock_caps(): void
    {
        $setting = $this->createSetting('BIZ CART SERVICE');
        [$cashier, $allowedLocation, $session] = $this->createCashierAndOpenSession($setting, 'POS CART SERVICE', returnSession: true);

        $serviceProduct = $this->createServiceProduct(
            setting: $setting,
            code: 'SRV-02',
            name: 'Jasa Perbaikan',
            barcode: 'SRV-02-BARCODE',
            salePrice: 100000,
            createdBy: $cashier->id
        );

        // Add to cart
        $addResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $serviceProduct->id,
                'qty' => 5, // No stock check
            ]);

        $addResponse->assertOk();
        $addResponse->assertJsonPath('cart_snapshot.lines.0.product_id', $serviceProduct->id);
        $addResponse->assertJsonPath('cart_snapshot.lines.0.qty', 5);
        $addResponse->assertJsonPath('cart_snapshot.lines.0.stock_managed', false);

        // Update qty to a huge number
        $lineId = $addResponse->json('cart_snapshot.lines.0.line_id');
        $updateResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
                'qty' => 9999,
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('cart_snapshot.lines.0.qty', 9999);
    }

    public function test_stock_managed_product_is_blocked_when_out_of_stock(): void
    {
        $setting = $this->createSetting('BIZ OOS BLOCK');
        [$cashier, $allowedLocation, $session] = $this->createCashierAndOpenSession($setting, 'POS OOS BLOCK', returnSession: true);

        $oosProduct = $this->createStockedProduct(
            setting: $setting,
            location: $allowedLocation,
            code: 'OOS-01',
            name: 'Barang Kosong',
            barcode: 'OOS-01-BARCODE',
            availableQty: 0,
            salePrice: 20000,
            createdBy: $cashier->id
        );

        $addResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $oosProduct->id,
                'qty' => 1,
            ]);

        $addResponse->assertStatus(422);
    }

    public function test_service_product_checkout_bypasses_stock_mutations(): void
    {
        $setting = $this->createSetting('BIZ CHK SERVICE');
        [$cashier, $allowedLocation, $session] = $this->createCashierAndOpenSession($setting, 'POS CHK SERVICE', returnSession: true);

        $serviceProduct = $this->createServiceProduct(
            setting: $setting,
            code: 'SRV-03',
            name: 'Jasa Setup',
            barcode: 'SRV-03-BARCODE',
            salePrice: 150000,
            createdBy: $cashier->id
        );

        // Add to cart
        $addResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $serviceProduct->id,
                'qty' => 2, 
            ]);
            
        $addResponse->assertOk();
        $snapshot = $addResponse->json('cart_snapshot');
        
        $coa = \Modules\Setting\Entities\ChartOfAccount::create([
            'setting_id' => $setting->id,
            'name' => 'Cash Account',
            'account_number' => '1000',
            'category' => 'Kas & Bank'
        ]);

        $paymentMethod = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coa->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        \Illuminate\Support\Facades\DB::table('setting_pos_payment_methods')->updateOrInsert(
            ['setting_id' => $setting->id, 'payment_method_id' => $paymentMethod->id],
            ['is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $customer = \Modules\People\Entities\Customer::factory()->create([
            'setting_id' => $setting->id,
        ]);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer->id,
            ])
            ->assertOk();

        $checkoutPayload = [
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'payments' => [
                [
                    'payment_method_id' => $paymentMethod->id,
                    'amount_paid' => 300000,
                ],
            ],
        ];

        $checkoutResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $checkoutPayload);

        $checkoutResponse->assertCreated();
        $checkoutResponse->assertJsonPath('status', 'POSTED');

        $saleId = $checkoutResponse->json('sale_id');
        $this->assertNotNull($saleId);

        // The service line is sold on the sale document...
        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $saleId,
            'product_id' => $serviceProduct->id,
            'quantity' => 2,
        ]);

        // ...but no inventory is allocated or mutated for a non-stock-managed product.
        $this->assertDatabaseMissing('product_stocks', [
            'product_id' => $serviceProduct->id,
        ]);
        $this->assertDatabaseMissing('transactions', [
            'product_id' => $serviceProduct->id,
        ]);
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id') ?? Currency::factory()->create()->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
        ]);
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        
        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission]);
        }
        
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createCashierAndOpenSession(Setting $setting, string $roleSuffix, bool $returnSession = false): array
    {
        $cashier = $this->createUserForSetting(
            $setting,
            $roleSuffix . ' CASHIER',
            ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']
        );

        $terminal = $this->createTerminalForSetting($setting);
        $allowedLocation = SalesLocationResolver::resolve((int) $terminal->setting_id);

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

        if ($returnSession) {
            return [$cashier, $allowedLocation, $session];
        }

        return [$cashier, $allowedLocation];
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'POS SERVICE LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-SRV-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Service Terminal ' . $sequence,
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

    private function createServiceProduct(
        Setting $setting,
        string $code,
        string $name,
        string $barcode,
        float $salePrice,
        int $createdBy
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => 'CAT-' . $setting->id],
            [
                'category_name' => 'Kategori POS ' . $setting->id,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $barcode,
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => $salePrice,
            'product_unit' => 'JASA',
            'product_stock_alert' => 0,
            'stock_managed' => false,
            'is_sold' => true,
            'serial_number_required' => false,
        ]);

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

        return $product->fresh();
    }

    private function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        string $name,
        ?string $barcode,
        int $availableQty,
        float $salePrice,
        int $createdBy
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => 'CAT-' . $setting->id],
            [
                'category_name' => 'Kategori POS ' . $setting->id,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $barcode,
            'product_quantity' => $availableQty,
            'product_cost' => 10000,
            'product_price' => $salePrice,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'is_sold' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $availableQty,
            'quantity_non_tax' => $availableQty,
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
            'sale_tax_id' => null,
        ]);

        return $product->fresh();
    }
}
