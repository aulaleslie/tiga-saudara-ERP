<?php

namespace Modules\Sale\Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\PosSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

abstract class PosDraftFeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;

    protected Location $location;

    protected User $user;

    protected PosSession $posSession;

    protected Customer $customer;

    protected ChartOfAccount $chartOfAccount;

    protected PaymentMethod $cashMethod;

    protected PaymentMethod $cardMethod;

    protected Unit $unit;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $currency = Currency::first() ?: Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'POS Draft Test Setting',
            'company_email' => 'pos-draft-test@example.com',
            'company_phone' => '081200000001',
            'company_address' => 'Jl. Test No. 1',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'pos_document_prefix' => 'PDT',
            'pos_draft_flow_enabled' => true,
            'pos_draft_expiry_minutes' => 120,
        ]);

        $this->location = Location::factory()->create([
            'setting_id' => $this->setting->id,
            'name' => 'POS Main Location',
        ]);

        SettingSaleLocation::updateOrCreate(
            ['location_id' => $this->location->id],
            ['setting_id' => $this->setting->id, 'position' => 1]
        );

        $this->user = User::factory()->create();
        $this->user->settings()->attach($this->setting->id, ['role_id' => 1]);
        $this->syncPermissions($this->user, [
            'pos.access',
            'pos.create',
            'pos.transactions.access',
            'pos.drafts.view',
            'pos.drafts.update',
            'pos.drafts.submit',
            'pos.drafts.void',
            'pos.drafts.lock.override',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->posSession = PosSession::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'device_name' => 'TEST DEVICE',
            'cash_float' => 100000,
            'expected_cash' => 100000,
            'status' => PosSession::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'POS Test Customer',
        ]);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->category = Category::create([
            'category_code' => 'POS-CAT',
            'category_name' => 'POS Category',
            'created_by' => $this->user->id,
            'setting_id' => $this->setting->id,
        ]);

        $chartOfAccountId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Kas POS',
            'account_number' => '1000-POS',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->chartOfAccount = ChartOfAccount::findOrFail($chartOfAccountId);

        $this->cashMethod = PaymentMethod::create([
            'name' => 'Tunai POS',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        $this->cardMethod = PaymentMethod::create([
            'name' => 'Kartu POS',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => false,
            'is_available_in_pos' => true,
        ]);
    }

    protected function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'POS Product',
            'product_code' => 'POS-PROD-' . mt_rand(1000, 9999),
            'product_quantity' => 100,
            'product_cost' => 50,
            'product_price' => 100,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'stock_managed' => true,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'sale_price' => 100,
            'tier_1_price' => 100,
            'tier_2_price' => 100,
            'serial_number_required' => false,
        ], $overrides));
    }

    protected function createStock(Product $product, int $nonTaxQty, int $taxQty = 0, ?int $taxId = null): ProductStock
    {
        return ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity_non_tax' => $nonTaxQty,
            'quantity_tax' => $taxQty,
            'quantity' => $nonTaxQty + $taxQty,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $taxId,
        ]);
    }

    protected function makeDraftPayload(Product $product, int $qty = 1, float $price = 100, array $optionsOverrides = []): array
    {
        return [
            'customer_id' => $this->customer->id,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => round($qty * $price, 2),
            'payload' => [
                'customer_id' => $this->customer->id,
                'tax_percentage' => 0,
                'discount_percentage' => 0,
                'shipping_amount' => 0,
                'total_amount' => round($qty * $price, 2),
                'cart' => [[
                    'id' => (string) $product->id,
                    'name' => $product->product_name,
                    'qty' => $qty,
                    'price' => $price,
                    'weight' => 1,
                    'options' => array_merge([
                        'product_id' => $product->id,
                        'code' => $product->product_code,
                        'unit_price' => $price,
                        'sub_total' => round($qty * $price, 2),
                        'product_discount' => 0,
                        'product_discount_type' => 'fixed',
                        'product_tax' => null,
                    ], $optionsOverrides),
                ]],
            ],
        ];
    }

    protected function syncPermissions(User $user, array $permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->syncPermissions($permissions);
        $user->refresh();
    }
}
