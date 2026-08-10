<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

/**
 * @group pos-critical-path
 */
class POSCheckoutPreflightTest extends PosTransactionFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        Permission::findOrCreate('pos.checkout.payment', 'web');
    }

    public function test_preflight_passes_for_valid_cart(): void
    {
        $context = $this->createCheckoutContext('PREFLIGHT PASS');
        $product = $this->createStockedProduct($context['setting'], $context['location'], [
            'product_code' => 'PROD-OK',
            'sale_price' => 100000,
            'serial_number_required' => false,
        ]);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.preflight'))
            ->assertOk()
            ->assertJson(['status' => 'OK']);
    }

    public function test_preflight_fails_for_insufficient_stock(): void
    {
        $context = $this->createCheckoutContext('PREFLIGHT NO STOCK');
        $product = $this->createStockedProduct($context['setting'], $context['location'], [
            'product_code' => 'PROD-NO-STOCK',
            'sale_price' => 100000,
            'serial_number_required' => false,
            'stock_qty' => 5,
        ]);

        // Add 5 to cart (valid)
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 5);

        // Decrease stock to 2
        DB::table('product_stocks')
            ->where('product_id', $product->id)
            ->where('location_id', $context['location']->id)
            ->update(['quantity' => 2, 'quantity_non_tax' => 2]);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.preflight'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOCK_UNAVAILABLE')
            ->assertJsonPath('details.unfulfilled_lines.0.product_id', $product->id)
            ->assertJsonPath('details.unfulfilled_lines.0.requested_qty', 5)
            ->assertJsonPath('details.unfulfilled_lines.0.allocated_qty', 2);
    }

    public function test_preflight_fails_for_missing_serials(): void
    {
        $context = $this->createCheckoutContext('PREFLIGHT NO SERIAL');
        $product = $this->createStockedProduct($context['setting'], $context['location'], [
            'product_code' => 'PROD-SER',
            'sale_price' => 100000,
            'serial_number_required' => true,
        ]);

        // Add 1 to cart but don't assign serial
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.preflight'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SERIAL_INVALID');
    }

    public function test_preflight_fails_for_inactive_serial(): void
    {
        $context = $this->createCheckoutContext('PREFLIGHT INACTIVE SERIAL');
        $product = $this->createStockedProduct($context['setting'], $context['location'], [
            'product_code' => 'PROD-SER-INACTIVE',
            'sale_price' => 100000,
            'serial_number_required' => true,
        ]);
        $sn = $this->createSerialNumber($product, $context['location'], 'SN-001');

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign serial
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-001'],
            ])
            ->assertOk();

        // Mark serial inactive
        $sn->update(['status' => 'SOLD']);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.preflight'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SERIAL_INVALID');
    }

    /**
     * Task 2.2: Loaded non-stock service line does not create false stock mismatch
     * Proves that a service product without stock_managed=true doesn't trigger
     * STOCK_UNAVAILABLE error even if no product stock record exists.
     */
    public function test_preflight_passes_for_loaded_non_stock_service_line(): void
    {
        $context = $this->createCheckoutContext('PREFLIGHT NON-STOCK SERVICE');

        // Create a non-stock service product (no inventory)
        $serviceProduct = $this->createNonStockServiceProduct($context['setting'], 'SERVICE-CONSULT', 'Consulting Service', 100000);

        // Add service to cart
        $this->addCartLine($context['cashier'], $context['setting'], $serviceProduct->id, 1);

        // Save draft
        $saveResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);

        $transactionId = (int) $saveResponse->json('transaction.id');

        // Clear cart and load draft
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->deleteJson(route('pos.sell.cart.clear'))
            ->assertOk();

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        // Preflight should PASS even though service has no stock record
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.preflight'))
            ->assertOk()
            ->assertJson(['status' => 'OK']);
    }

    /**
     * Task 2.3: Loaded stock-managed product remains subject to stock validation
     * Proves that a stock-managed product loaded from draft still validates inventory.
     */
    public function test_preflight_fails_for_loaded_stock_managed_product_with_insufficient_stock(): void
    {
        $context = $this->createCheckoutContext('PREFLIGHT LOADED STOCK');
        $product = $this->createStockedProduct($context['setting'], $context['location'], [
            'product_code' => 'PROD-STOCK',
            'sale_price' => 100000,
            'serial_number_required' => false,
            'stock_qty' => 10,
        ]);

        // Add 5 to cart
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 5);

        // Save draft
        $saveResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);

        $transactionId = (int) $saveResponse->json('transaction.id');

        // Clear cart and load draft
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->deleteJson(route('pos.sell.cart.clear'))
            ->assertOk();

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        // Reduce stock to 2
        DB::table('product_stocks')
            ->where('product_id', $product->id)
            ->where('location_id', $context['location']->id)
            ->update(['quantity' => 2, 'quantity_non_tax' => 2]);

        // Preflight should FAIL because stock-managed line still requires validation
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.preflight'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOCK_UNAVAILABLE')
            ->assertJsonPath('details.unfulfilled_lines.0.product_id', $product->id)
            ->assertJsonPath('details.unfulfilled_lines.0.requested_qty', 5)
            ->assertJsonPath('details.unfulfilled_lines.0.allocated_qty', 2);
    }

    /**
     * Task 2.1 (Regression): Stock-managed line missing stock_managed metadata key
     * restores as stock-managed and still fails preflight when stock is insufficient.
     * Verifies that missing stock_managed key does not silently become false,
     * bypassing stock validation.
     */
    public function test_preflight_fails_for_missing_stock_managed_key_with_insufficient_stock(): void
    {
        $context = $this->createCheckoutContext('PREFLIGHT MISSING STOCK_MANAGED');
        $product = $this->createStockedProduct($context['setting'], $context['location'], [
            'product_code' => 'PROD-MISSING-STOCK-KEY',
            'sale_price' => 100000,
            'serial_number_required' => false,
            'stock_qty' => 10,
        ]);

        // Add 5 to cart
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 5);

        // Save draft
        $saveResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);

        $transactionId = (int) $saveResponse->json('transaction.id');

        // Manually update persisted line metadata to remove stock_managed key
        // (simulating legacy draft or edge case where key was never set)
        DB::table('pos_transaction_lines')
            ->where('pos_transaction_id', $transactionId)
            ->update([
                'line_meta' => json_encode([
                    'barcode' => null,
                    'price_source' => 'BASE',
                    // Deliberately omitting stock_managed key
                ]),
            ]);

        // Clear cart and load draft
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->deleteJson(route('pos.sell.cart.clear'))
            ->assertOk();

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        // Reduce stock to 2 (less than requested 5)
        DB::table('product_stocks')
            ->where('product_id', $product->id)
            ->where('location_id', $context['location']->id)
            ->update(['quantity' => 2, 'quantity_non_tax' => 2]);

        // Preflight must FAIL because missing stock_managed key defaults to true (conservative)
        // and stock validation must proceed
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.preflight'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOCK_UNAVAILABLE')
            ->assertJsonPath('details.unfulfilled_lines.0.product_id', $product->id)
            ->assertJsonPath('details.unfulfilled_lines.0.requested_qty', 5)
            ->assertJsonPath('details.unfulfilled_lines.0.allocated_qty', 2);
    }

    protected function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $cashier = $this->createUserForSetting($setting, $name . '-cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.transactions.save',
            'pos.transactions.load',
            'pos.cart.clear',
        ]);

        $session = $this->openSession($setting, $terminal, $cashier);
        $this->seedPaymentMethods($setting);

        return [
            'setting' => $setting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'location' => $location,
            'session' => $session,
        ];
    }

    protected function seedPaymentMethods(Setting $setting): array
    {
        $methods = [];

        foreach (['CASH' => true, 'TRANSFER' => false, 'QRIS' => false] as $name => $isCash) {
            $coaId = DB::table('chart_of_accounts')->insertGetId([
                'name' => "COA $name " . uniqid(),
                'account_number' => "ACC-$name-" . uniqid(),
                'category' => 'Kas & Bank',
                'setting_id' => $setting->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $methods[strtolower($name)] = \Modules\Setting\Entities\PaymentMethod::create([
                'name' => "$name POS " . uniqid(),
                'coa_id' => $coaId,
                'is_cash' => $isCash,
                'requires_reference' => !$isCash,
            ]);

            DB::table('setting_pos_payment_methods')->updateOrInsert(
                [
                    'setting_id' => $setting->id,
                    'payment_method_id' => $methods[strtolower($name)]->id,
                ],
                [
                    'is_enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        return $methods;
    }

    protected function createNonStockServiceProduct(Setting $setting, string $code, string $name, float $salePrice): Product
    {
        $category = Category::firstOrCreate(
            ['category_code' => $code . '-SERVICE-CAT'],
            [
                'category_name' => $code . ' SERVICE CATEGORY',
                'created_by' => 1,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'Service Unit',
            'short_name' => 'SVC',
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $code . '-SVC-BAR',
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => $salePrice,
            'product_unit' => 'SVC',
            'product_stock_alert' => 0,
            'stock_managed' => false,  // Non-stock service
            'is_sold' => true,
            'serial_number_required' => false,
        ]);

        // Non-stock products have no ProductStock records

        ProductPrice::query()->updateOrCreate([
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

    protected function addCartLine(User $cashier, Setting $setting, int $productId, int $qty): void
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
