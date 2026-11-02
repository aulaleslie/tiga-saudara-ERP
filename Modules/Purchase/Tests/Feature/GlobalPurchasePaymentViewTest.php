<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GlobalPurchasePaymentViewTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting1;
    protected $setting2;
    protected $purchaseSetting2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting1 = Setting::create([
            'company_name' => 'Setting 1',
            'company_email' => 'setting1@test.com',
            'company_phone' => '111',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'setting1@test.com',
            'company_address' => 'Addr 1',
            'footer_text' => 'Footer 1'
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Setting 2',
            'company_email' => 'setting2@test.com',
            'company_phone' => '222',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'setting2@test.com',
            'company_address' => 'Addr 2',
            'footer_text' => 'Footer 2'
        ]);

        Permission::firstOrCreate(['name' => 'purchasePayments.global.access']);
        
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $role->givePermissionTo('purchasePayments.global.access');

        $this->user = User::factory()->create();
        $this->user->assignRole($role);

        $supplier = Supplier::create([
            'supplier_name' => 'Supplier 2 ' . uniqid(),
            'supplier_email' => uniqid() . '@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Address 2',
            'setting_id' => $this->setting2->id,
        ]);

        $this->purchaseSetting2 = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PR-' . uniqid(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'RECEIVED',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'tax_rate' => 0,
            'setting_id' => $this->setting2->id,
        ]);
        session(['setting_id' => $this->setting1->id]);
    }

    public function test_global_show_bypasses_setting_id_and_returns_200()
    {
        $this->withoutExceptionHandling();
        // Act as user in setting1
        session(['setting_id' => $this->setting1->id]);

        // Access purchase in setting2 via global show
        $response = $this->actingAs($this->user)->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));
        $response->assertStatus(200);
        $response->assertViewIs('purchase::show');
        $response->assertViewHas('globalMode', true);

        // Assert inline editors are mounted for inline metadata edits
        $response->assertSeeLivewire('purchase.supplier-purchase-number-editor');
        $response->assertSeeLivewire('purchase.tax-ref-no-editor');
        $response->assertSeeLivewire('purchase.purchase-note-editor');

        // Assert non-inline action buttons like "Duplikat" are not present because of globalMode
        $response->assertDontSeeText('Duplikat');

        // Assert breadcrumb points back to global index
        $response->assertSee(route('purchases.global-payments.index'));
    }

    public function test_global_history_bypasses_setting_id_and_returns_200()
    {
        // Act as user in setting1
        session(['setting_id' => $this->setting1->id]);

        // Access purchase in setting2 via global history
        $response = $this->actingAs($this->user)->get(route('purchases.global-payments.history', $this->purchaseSetting2->id));
        $response->assertStatus(200);
        $response->assertViewIs('purchase::payments.index');
        
        // Assert breadcrumb points back to global index
        $response->assertSee(route('purchases.global-payments.index'));
    }

    public function test_projection_index_validates_product_and_hides_payment_summary_cards()
    {
        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Projection Index Product',
            'product_code' => 'PROJECTION-INDEX',
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_unit' => 'pc',
            'stock_managed' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('purchases.global-payments.index', ['projection_product_id' => $product->id]));

        $response->assertOk()
            ->assertSee($product->product_name)
            ->assertSee('Menampilkan pembelian disetujui dengan sisa kuantitas produk ini di seluruh bisnis.')
            ->assertSeeLivewire('purchase.purchase-table')
            ->assertDontSeeLivewire('purchase.purchase-summary-cards');

        $this->actingAs($this->user)
            ->get(route('purchases.global-payments.index', ['projection_product_id' => 999999]))
            ->assertSessionHasErrors('projection_product_id');
    }

    public function test_projection_context_opens_approved_sources_and_rejects_noncontributing_details()
    {
        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Projection Detail Product',
            'product_code' => 'PROJECTION-DETAIL',
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_unit' => 'pc',
            'stock_managed' => true,
            'is_active' => true,
        ]);
        $this->purchaseSetting2->update(['status' => Purchase::STATUS_APPROVED]);
        $this->createPurchaseDetail($this->purchaseSetting2, $product);

        $sourceUrl = route('purchases.global-payments.show', [
            'purchase_id' => $this->purchaseSetting2->id,
            'projection_product_id' => $product->id,
        ]);

        $this->actingAs($this->user)
            ->get($sourceUrl)
            ->assertOk()
            ->assertSee(route('purchases.global-payments.index', ['projection_product_id' => $product->id]))
            ->assertDontSee('id="payments-table"', false)
            ->assertDontSee('global-payments/datatable', false);

        $otherProduct = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Unrelated Detail Product',
            'product_code' => 'UNRELATED-DETAIL',
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_unit' => 'pc',
            'stock_managed' => true,
            'is_active' => true,
        ]);
        $unrelated = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'UNRELATED-PROJECTION-' . uniqid(),
            'supplier_id' => $this->purchaseSetting2->supplier_id,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting2->id,
        ]);
        $this->createPurchaseDetail($unrelated, $otherProduct);

        $this->actingAs($this->user)
            ->get(route('purchases.global-payments.show', [
                'purchase_id' => $unrelated->id,
                'projection_product_id' => $product->id,
            ]))
            ->assertNotFound();

        $this->actingAs($this->user)
            ->get(route('purchases.global-payments.show', $this->purchaseSetting2->id))
            ->assertNotFound();

        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'purchasePayments.create', 'guard_name' => 'web']));
        $partiallyReceivedSource = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PARTIAL-PROJECTION-' . uniqid(),
            'supplier_id' => $this->purchaseSetting2->supplier_id,
            'status' => Purchase::STATUS_RECEIVED_PARTIALLY,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting2->id,
        ]);
        $this->createPurchaseDetail($partiallyReceivedSource, $product);

        $this->actingAs($this->user)
            ->get(route('purchases.global-payments.show', [
                'purchase_id' => $partiallyReceivedSource->id,
                'projection_product_id' => $product->id,
            ]))
            ->assertOk()
            ->assertDontSee('Tambah Pembayaran');

        $this->actingAs($this->user)
            ->get(route('purchases.global-payments.show', $partiallyReceivedSource->id))
            ->assertOk()
            ->assertSee('Tambah Pembayaran');
    }

    private function createPurchaseDetail(Purchase $purchase, Product $product): PurchaseDetail
    {
        return PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'unit_price' => 100,
            'price' => 100,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
    }
}
