<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\Pos\Checkout;
use App\Models\User;
use App\Support\PosLocationResolver;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PosMultiLocationInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected Product $product;
    protected PaymentMethod $cashMethod;
    protected Location $primaryLocation;
    protected Location $secondaryLocation;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->withoutMiddleware(CheckUserRoleForSetting::class);

        $user = User::factory()->create();
        $this->actingAs($user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '0800000000',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Street 1',
        ]);

        Session::put('setting_id', $this->setting->id);

        $chartOfAccount = ChartOfAccount::create([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $this->setting->id,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $chartOfAccount->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        $this->customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
        ]);

        $this->primaryLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Storefront',
        ]);

        $this->secondaryLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse',
        ]);

        SettingSaleLocation::updateOrCreate(
            ['location_id' => $this->primaryLocation->id],
            ['setting_id' => $this->setting->id, 'is_pos' => true, 'position' => 1]
        );

        SettingSaleLocation::updateOrCreate(
            ['location_id' => $this->secondaryLocation->id],
            ['setting_id' => $this->setting->id, 'is_pos' => true, 'position' => 2]
        );

        PosLocationResolver::forget($this->setting->id);

        $unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $category = Category::create([
            'category_code' => 'CAT-01',
            'category_name' => 'Category',
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Sample Product',
            'product_code' => 'PRD-01',
            'product_barcode_symbology' => null,
            'product_quantity' => 100,
            'product_cost' => 5.00,
            'product_price' => 10.00,
            'product_unit' => 'PCS',
            'product_stock_alert' => 5,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'stock_managed' => true,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'sale_price' => 10.00,
            'tier_1_price' => 10.00,
            'tier_2_price' => 10.00,
        ]);

        Cart::instance('sale')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('sale')->destroy();

        parent::tearDown();
    }

    public function test_multi_location_stock_is_allocated_by_position(): void
    {
        $this->createStock($this->primaryLocation->id, 2, 0, null);
        $this->createStock($this->secondaryLocation->id, 5, 0, null);

        $cartItem = $this->prepareCartForQuantity(3);
        $allocations = $this->extractAllocations($cartItem->options->pos_location_allocations ?? []);

        $this->assertSame([
            [
                'location_id' => $this->primaryLocation->id,
                'allocated_non_tax' => 2,
                'allocated_tax' => 0,
            ],
            [
                'location_id' => $this->secondaryLocation->id,
                'allocated_non_tax' => 1,
                'allocated_tax' => 0,
            ],
        ], $allocations);

        $this->completeSale();

        $this->assertStock($this->primaryLocation->id, 0, 0);
        $this->assertStock($this->secondaryLocation->id, 4, 0);
    }

    public function test_multi_location_deductions_respect_tax_buckets(): void
    {
        $tax = Tax::create(['name' => 'VAT', 'value' => 10]);

        $this->createStock($this->primaryLocation->id, 1, 1, $tax->id);
        $this->createStock($this->secondaryLocation->id, 0, 3, $tax->id);

        $cartItem = $this->prepareCartForQuantity(3);
        $allocations = $this->extractAllocations($cartItem->options->pos_location_allocations ?? []);

        $this->assertSame([
            [
                'location_id' => $this->primaryLocation->id,
                'allocated_non_tax' => 1,
                'allocated_tax' => 1,
            ],
            [
                'location_id' => $this->secondaryLocation->id,
                'allocated_non_tax' => 0,
                'allocated_tax' => 1,
            ],
        ], $allocations);

        $this->completeSale();

        $this->assertStock($this->primaryLocation->id, 0, 0);
        $this->assertStock($this->secondaryLocation->id, 0, 2);
    }

    private function createStock(int $locationId, int $nonTax, int $tax, ?int $taxId): void
    {
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $locationId,
            'quantity' => $nonTax + $tax,
            'quantity_non_tax' => $nonTax,
            'quantity_tax' => $tax,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $taxId,
        ]);
    }

    private function prepareCartForQuantity(int $quantity)
    {
        $component = Livewire::test(Checkout::class, [
            'cartInstance' => 'sale',
            'customers' => Customer::all(),
        ]);

        $component->call('addProduct', $this->product->fresh()->toArray());

        $cart = Cart::instance('sale');
        $item = $cart->content()->first();
        $cartKey = $item->options->cart_key;

        $component->set("quantity.$cartKey", $quantity);
        $component->call('updateQuantity', $item->rowId, $cartKey);

        return $cart->get($item->rowId);
    }

    private function extractAllocations($raw): array
    {
        if ($raw instanceof \Illuminate\Support\Collection) {
            $raw = $raw->toArray();
        } elseif (is_object($raw) && method_exists($raw, 'toArray')) {
            $raw = $raw->toArray();
        }

        if (! is_array($raw)) {
            $raw = (array) $raw;
        }

        $result = [];

        foreach ($raw as $entry) {
            if ($entry instanceof \Illuminate\Support\Collection) {
                $entry = $entry->toArray();
            } elseif (is_object($entry) && method_exists($entry, 'toArray')) {
                $entry = $entry->toArray();
            } elseif (is_object($entry)) {
                $entry = (array) $entry;
            }

            if (! is_array($entry)) {
                continue;
            }

            $result[] = [
                'location_id' => (int) ($entry['location_id'] ?? 0),
                'allocated_non_tax' => (int) ($entry['allocated_non_tax'] ?? 0),
                'allocated_tax' => (int) ($entry['allocated_tax'] ?? 0),
            ];
        }

        return $result;
    }

    private function completeSale(): void
    {
        $cart = Cart::instance('sale');
        $total = (float) $cart->total();

        $response = $this->post(route('app.pos.store'), [
            'customer_id' => $this->customer->id,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => $total,
            'paid_amount' => $total,
            'payments' => [
                ['method_id' => $this->cashMethod->id, 'amount' => $total],
            ],
        ]);

        $response->assertRedirect(route('app.pos.index'));
    }

    private function assertStock(int $locationId, int $expectedNonTax, int $expectedTax): void
    {
        $stock = ProductStock::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $locationId)
            ->first();

        $this->assertNotNull($stock, 'Missing stock row for location '.$locationId);
        $this->assertSame($expectedNonTax, (int) $stock->quantity_non_tax);
        $this->assertSame($expectedTax, (int) $stock->quantity_tax);
    }
}
