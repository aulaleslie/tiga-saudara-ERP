<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Support\PurchaseSaveRedirect;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Successful Purchase create/update lands on the Purchase detail when the user can view it there,
 * otherwise on the Purchase list (no 403/404 after a successful save).
 */
class PurchaseSaveRedirectTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Supplier $supplier;
    private PaymentTerm $paymentTerm;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Landing pages check many permissions; they must exist for Gate lookups (only assigned ones grant access)
        foreach (require base_path('app/Config/Permissions.php') as $group) {
            foreach (array_keys($group) as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
        }

        $this->setting = Setting::factory()->create(['is_pkp' => false]);
        session(['setting_id' => $this->setting->id]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Redirect',
            'supplier_email' => 'redirect@test.local',
            'supplier_phone' => '08123456789',
            'address' => 'Jl. Test No. 1',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'is_active' => true,
        ]);
        $this->paymentTerm = PaymentTerm::create(['name' => 'COD', 'longevity' => 0, 'is_active' => true]);

        $pcs = Unit::create(['name' => 'PCS', 'short_name' => 'PCS', 'is_active' => true]);
        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Redirect Product',
            'product_code' => 'RDR-001',
            'unit_id' => $pcs->id,
            'base_unit_id' => $pcs->id,
            'product_quantity' => 10,
            'product_price' => 5000,
            'product_cost' => 4000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 4000,
        ]);
    }

    private function actingWith(array $permissions)
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $this->actingAs($user)->withSession(['setting_id' => $this->setting->id]);
    }

    /**
     * Follow the redirect: the landing page must actually be accessible, not a 403/404.
     */
    private function assertLandsOn($response, string $expectedUrl): void
    {
        $response->assertSessionHasNoErrors()->assertRedirect($expectedUrl);
        $this->get($response->headers->get('Location'))->assertOk();
    }

    /**
     * Same fields the Alpine create page posts as FormData (cart[i][...]).
     */
    private function payload(string $reference, float $quantity = 2): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'reference' => $reference,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term' => $this->paymentTerm->id,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 4000 * $quantity,
            'cart' => [[
                'product_id' => $this->product->id,
                'purchase_unit_id' => '',
                'product_unit_conversion_id' => '',
                'quantity' => $quantity,
                'unit_price' => 4000,
                'conversion_factor' => 1,
                'unit_name' => '',
                'base_unit_name' => '',
                'discount_type' => 'fixed',
                'discount' => 0,
                'tax_id' => '',
            ]],
        ];
    }

    public function test_create_redirects_to_created_purchase_detail(): void
    {
        $response = $this->actingWith(['purchases.create', 'purchases.show'])
            ->post(route('purchases.store'), $this->payload('PR-REDIRECT-001'));

        $purchase = Purchase::withoutGlobalScopes()->where('reference', 'PR-REDIRECT-001')->firstOrFail();
        $this->assertLandsOn($response, route('purchases.show', $purchase));
    }

    public function test_create_falls_back_to_list_without_purchases_show(): void
    {
        $response = $this->actingWith(['purchases.create', 'purchases.access'])
            ->post(route('purchases.store'), $this->payload('PR-REDIRECT-002'));

        $this->assertLandsOn($response, route('purchases.index'));
    }

    public function test_create_falls_back_to_home_without_show_or_list_access(): void
    {
        $response = $this->actingWith(['purchases.create'])
            ->post(route('purchases.store'), $this->payload('PR-REDIRECT-005'));

        $this->assertLandsOn($response, route('home'));
    }

    public function test_update_redirects_to_updated_purchase_detail(): void
    {
        $this->actingWith(['purchases.create'])->post(route('purchases.store'), $this->payload('PR-REDIRECT-003'));
        $purchase = Purchase::withoutGlobalScopes()->where('reference', 'PR-REDIRECT-003')->firstOrFail();

        $response = $this->actingWith(['purchases.update', 'purchases.show'])
            ->put(route('purchases.update', $purchase), $this->payload('PR-REDIRECT-003', 3));

        $this->assertLandsOn($response, route('purchases.show', $purchase));
    }

    public function test_update_falls_back_to_list_without_purchases_show(): void
    {
        $this->actingWith(['purchases.create'])->post(route('purchases.store'), $this->payload('PR-REDIRECT-004'));
        $purchase = Purchase::withoutGlobalScopes()->where('reference', 'PR-REDIRECT-004')->firstOrFail();

        $response = $this->actingWith(['purchases.update', 'purchases.access'])
            ->put(route('purchases.update', $purchase), $this->payload('PR-REDIRECT-004', 3));

        $this->assertLandsOn($response, route('purchases.index'));
    }

    public function test_update_falls_back_to_home_without_show_or_list_access(): void
    {
        $this->actingWith(['purchases.create'])->post(route('purchases.store'), $this->payload('PR-REDIRECT-006'));
        $purchase = Purchase::withoutGlobalScopes()->where('reference', 'PR-REDIRECT-006')->firstOrFail();

        $response = $this->actingWith(['purchases.update'])
            ->put(route('purchases.update', $purchase), $this->payload('PR-REDIRECT-006', 3));

        $this->assertLandsOn($response, route('home'));
    }

    public function test_purchase_saved_into_another_business_falls_back_to_list(): void
    {
        $this->actingWith(['purchases.show', 'purchases.access']);
        $otherSetting = Setting::factory()->create(['is_pkp' => false]);

        $own = new Purchase(['setting_id' => $this->setting->id]);
        $own->id = 101;
        $foreign = new Purchase(['setting_id' => $otherSetting->id]);
        $foreign->id = 102;

        // The detail page 404s outside the active setting, so cross-business saves land on the list
        $this->assertSame(route('purchases.show', $own), PurchaseSaveRedirect::url($own));
        $this->assertSame(route('purchases.index'), PurchaseSaveRedirect::url($foreign));
    }
}
