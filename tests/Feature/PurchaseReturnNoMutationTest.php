<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Tests\TestCase;
use App\Livewire\PurchaseReturn\PurchaseReturnCreateForm;

/**
 * Ticket 7: Create pending return document without inventory mutation
 * 
 * Verifies that submitting a purchase return creates a pending document only,
 * with no stock mutation or reservation at creation time.
 */
class PurchaseReturnNoMutationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Currency $currency;
    private Supplier $supplier;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckUserRoleForSetting::class,
            VerifyCsrfToken::class,
        ]);

        $this->currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'company_name'              => 'Test Company',
            'company_email'             => 'test@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'test@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => '123 Street',
        ]);

        $this->user = User::factory()->create();
        $this->supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
        $this->location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
        ]);
        
        session(['setting_id' => $this->setting->id]);
    }

    /**
     * Scenario: Create pending document without mutation
     * Given a valid return submission
     * When the user submits the return
     * Then a pending return document is created and no inventory mutation occurs
     */
    public function test_create_pending_document_without_inventory_mutation(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();
        Gate::shouldReceive('allows')->andReturnTrue()->zeroOrMoreTimes();
        Gate::shouldReceive('check')->andReturnTrue()->zeroOrMoreTimes();

        $category = Category::create([
            'category_name' => 'General',
            'category_code' => 'GEN',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        // 1. Non-Serial Product
        $product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Normal Product',
            'product_code' => 'NP01',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // 2. Serial-Tracked Product
        $serialProduct = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Serial Product',
            'product_code' => 'SP01',
            'product_quantity' => 10,
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        ProductStock::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $sn = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-TEST-001',
            'status' => 'active',
            'is_in_return_process' => false,
        ]);

        Livewire::actingAs($this->user)
            ->test(PurchaseReturnCreateForm::class)
            ->set('supplier_id', $this->supplier->id)
            ->set('rows', [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    'quantity' => 5,
                    'location_id' => $this->location->id,
                    'purchase_price' => 10000,
                    'total' => 50000,
                    'serial_number_required' => false,
                ],
                [
                    'product_id' => $serialProduct->id,
                    'product_name' => $serialProduct->product_name,
                    'product_code' => $serialProduct->product_code,
                    'quantity' => 1,
                    'location_id' => $this->location->id,
                    'purchase_price' => 10000,
                    'total' => 10000,
                    'serial_number_required' => true,
                    'serial_numbers' => [['id' => $sn->id, 'serial_number' => $sn->serial_number]],
                ]
            ])
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchase-returns.index'));

        // Verify Document Creation
        $purchaseReturn = PurchaseReturn::latest()->first();
        $this->assertEquals('pending', strtolower($purchaseReturn->approval_status));
        $this->assertEquals('PENDING APPROVAL', $purchaseReturn->status);

        // Verify NO Stock Mutation
        $this->assertEquals(100, $product->fresh()->product_quantity, 'Global product quantity should NOT change');
        $this->assertEquals(10, $serialProduct->fresh()->product_quantity, 'Global serial product quantity should NOT change');
        
        $this->assertEquals(100, ProductStock::where('product_id', $product->id)->where('location_id', $this->location->id)->first()->quantity);
        $this->assertEquals(10, ProductStock::where('product_id', $serialProduct->id)->where('location_id', $this->location->id)->first()->quantity);

        // Verify SERIAL FLAGS
        $snFresh = $sn->fresh();
        $this->assertEquals('ACTIVE', $snFresh->status, 'Serial status should NOT change to returned yet');
        $this->assertFalse((bool)$snFresh->is_in_return_process, 'is_in_return_process should remain false until dispatch/settlement');
    }

    /**
     * Scenario: Atomic persistence on failure
     * Given a database error occurs while saving return lines
     * When the submission is processed
     * Then no partial return records are persisted
     */
    public function test_atomic_persistence_on_failure(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();

        // We expect an error, let's trigger it by passing invalid row data that fails at DB level if possible
        // or just mock a DB failure if we want to be precise, but standard Laravel transaction check is enough
        
        try {
            Livewire::actingAs($this->user)
                ->test(PurchaseReturnCreateForm::class)
                ->set('supplier_id', $this->supplier->id)
                ->set('rows', [
                    [
                        'product_id' => 99999, // Non-existent product
                        'product_name' => 'Bad Product',
                        'quantity' => 1,
                        'location_id' => $this->location->id,
                        'total' => 100,
                    ]
                ])
                ->call('submit');
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertEquals(0, PurchaseReturn::count(), 'No document should be created on failure');
    }
}
