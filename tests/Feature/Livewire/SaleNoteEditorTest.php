<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\SaleNoteEditor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SaleNoteEditorTest extends TestCase
{
    use RefreshDatabase;

    protected User $authorizedUser;
    protected User $unauthorizedUser;
    protected Setting $setting;
    protected Setting $foreignSetting;
    protected Customer $customer;
    protected Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'sales.edit', 'guard_name' => 'web']);
        
        $this->authorizedUser = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $this->authorizedUser->assignRole($role);

        $this->unauthorizedUser = User::factory()->create();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

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
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->foreignSetting = Setting::create([
            'company_name' => 'Foreign Company',
            'company_email' => 'foreign@example.com',
            'company_phone' => '654321',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'foreign@example.com',
            'footer_text' => 'Foreign',
            'company_address' => 'Foreign Address',
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_phone' => '123',
            'customer_email' => 'cust@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->sale = $this->createSaleWithStatus(Sale::STATUS_DRAFTED, 'Original note');
        
        session(['setting_id' => $this->setting->id]);
    }

    protected function createSaleWithStatus(string $status, ?string $note = null): Sale
    {
        return Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1500,
            'paid_amount' => 1500,
            'due_amount' => 0,
            'status' => $status,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'note' => $note,
            'setting_id' => $this->setting->id,
            'reference' => 'SL-' . Str::random(6),
        ]);
    }

    /**
     * Test note update across all lifecycle states.
     * @dataProvider saleLifecycleStatusesProvider
     */
    public function test_can_update_note_in_all_lifecycle_states_without_lifecycle_specific_permissions(string $status)
    {
        $sale = $this->createSaleWithStatus($status, 'Before update');

        $this->actingAs($this->authorizedUser);

        Livewire::test(SaleNoteEditor::class, ['saleId' => $sale->id])
            ->assertSet('canEdit', true)
            ->assertSet('note', 'Before update')
            ->call('startEditing')
            ->assertSet('editing', true)
            ->set('note', 'Updated note for ' . $status)
            ->call('save')
            ->assertSet('editing', false)
            ->assertSet('note', 'Updated note for ' . $status)
            ->assertDispatched('notify');

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'note' => 'Updated note for ' . $status,
            'status' => $status,
        ]);
    }

    public static function saleLifecycleStatusesProvider(): array
    {
        return [
            'drafted' => [Sale::STATUS_DRAFTED],
            'waiting approval' => [Sale::STATUS_WAITING_APPROVAL],
            'approved' => [Sale::STATUS_APPROVED],
            'rejected' => [Sale::STATUS_REJECTED],
            'partially dispatched' => [Sale::STATUS_DISPATCHED_PARTIALLY],
            'dispatched' => [Sale::STATUS_DISPATCHED],
            'partially returned' => [Sale::STATUS_RETURNED_PARTIALLY],
            'returned' => [Sale::STATUS_RETURNED],
        ];
    }

    public function test_denies_note_update_for_unauthorized_user()
    {
        $this->actingAs($this->unauthorizedUser);

        Livewire::test(SaleNoteEditor::class, ['saleId' => $this->sale->id])
            ->assertSet('canEdit', false)
            ->call('startEditing')
            ->assertForbidden();
    }

    public function test_denies_save_for_unauthorized_user()
    {
        $this->actingAs($this->unauthorizedUser);

        Livewire::test(SaleNoteEditor::class, ['saleId' => $this->sale->id])
            ->set('note', 'Hacked note')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseHas('sales', [
            'id' => $this->sale->id,
            'note' => 'Original note',
        ]);
    }

    public function test_denies_note_update_for_archived_sale()
    {
        $this->actingAs($this->authorizedUser);

        $this->sale->update(['archived_at' => now()]);

        Livewire::test(SaleNoteEditor::class, ['saleId' => $this->sale->id])
            ->assertSet('canEdit', false)
            ->call('startEditing')
            ->assertForbidden();
    }

    public function test_denies_save_for_archived_sale()
    {
        $this->actingAs($this->authorizedUser);

        $this->sale->update(['archived_at' => now()]);

        Livewire::test(SaleNoteEditor::class, ['saleId' => $this->sale->id])
            ->set('note', 'Updated archived note')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseHas('sales', [
            'id' => $this->sale->id,
            'note' => 'Original note',
        ]);
    }

    public function test_denies_note_update_for_foreign_setting()
    {
        $this->actingAs($this->authorizedUser);

        session(['setting_id' => $this->foreignSetting->id]);

        Livewire::test(SaleNoteEditor::class, ['saleId' => $this->sale->id])
            ->assertNotFound();
    }

    public function test_validates_1000_char_note_successfully()
    {
        $this->actingAs($this->authorizedUser);

        $maxNote = str_repeat('a', 1000);

        Livewire::test(SaleNoteEditor::class, ['saleId' => $this->sale->id])
            ->call('startEditing')
            ->set('note', $maxNote)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false)
            ->assertSet('note', $maxNote);

        $this->assertDatabaseHas('sales', [
            'id' => $this->sale->id,
            'note' => $maxNote,
        ]);
    }

    public function test_rejects_oversized_note()
    {
        $this->actingAs($this->authorizedUser);

        $oversizedNote = str_repeat('a', 1001);

        Livewire::test(SaleNoteEditor::class, ['saleId' => $this->sale->id])
            ->call('startEditing')
            ->set('note', $oversizedNote)
            ->call('save')
            ->assertHasErrors(['note' => 'max'])
            ->assertSet('editing', true);

        $this->assertDatabaseHas('sales', [
            'id' => $this->sale->id,
            'note' => 'Original note',
        ]);
    }

    public function test_empty_note_normalizes_to_null()
    {
        $this->actingAs($this->authorizedUser);

        Livewire::test(SaleNoteEditor::class, ['saleId' => $this->sale->id])
            ->call('startEditing')
            ->set('note', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false)
            ->assertSet('note', null);

        $this->sale->refresh();
        $this->assertNull($this->sale->note);
    }

    public function test_cancel_restores_original_note_without_persisting()
    {
        $this->actingAs($this->authorizedUser);

        Livewire::test(SaleNoteEditor::class, ['saleId' => $this->sale->id])
            ->call('startEditing')
            ->set('note', 'Changed in draft')
            ->call('cancelEdit')
            ->assertSet('editing', false)
            ->assertSet('note', 'Original note');

        $this->assertDatabaseHas('sales', [
            'id' => $this->sale->id,
            'note' => 'Original note',
        ]);
    }

    public function test_save_only_mutates_note_column_and_preserves_all_other_attributes()
    {
        $this->actingAs($this->authorizedUser);

        $category = Category::create([
            'category_code' => 'C-01',
            'category_name' => 'Cat 1',
            'created_by' => $this->authorizedUser->id,
            'setting_id' => $this->setting->id,
        ]);

        $product = Product::create([
            'product_name' => 'Product TEST',
            'product_code' => 'TEST-01',
            'product_quantity' => 100,
            'product_price' => 1000,
            'product_cost' => 800,
            'product_unit' => 'pcs',
            'product_stock_alert' => 10,
            'category_id' => $category->id,
            'product_barcode_symbology' => 'CODE128',
            'setting_id' => $this->setting->id,
        ]);

        $location = \Modules\Setting\Entities\Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $stock = \Modules\Product\Entities\ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 50,
            'quantity_non_tax' => 50,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $this->sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 750,
            'unit_price' => 750,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $this->sale->id,
            'dispatch_date' => now()->format('Y-m-d'),
            'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
        ]);

        $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $this->sale->id,
            'sale_detail_id' => $detail->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'dispatched_quantity' => 2,
        ]);

        $payment = \Modules\Sale\Entities\SalePayment::create([
            'sale_id' => $this->sale->id,
            'amount' => 1500,
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-' . Str::random(6),
            'payment_method' => 'Cash',
            'status' => \Modules\Sale\Entities\SalePayment::STATUS_ACTIVE,
        ]);

        $return = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->format('Y-m-d'),
            'reference' => 'SR-' . Str::random(6),
            'sale_id' => $this->sale->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'setting_id' => $this->setting->id,
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $beforeSale = $this->sale->fresh()->toArray();
        $beforeDetail = $detail->fresh()->toArray();
        $beforeDispatch = $dispatch->fresh()->toArray();
        $beforeDispatchDetail = $dispatchDetail->fresh()->toArray();
        $beforePayment = $payment->fresh()->toArray();
        $beforeReturn = $return->fresh()->toArray();
        $beforeStock = $stock->fresh()->toArray();

        Livewire::test(SaleNoteEditor::class, ['saleId' => $this->sale->id])
            ->call('startEditing')
            ->set('note', 'Strict isolated note update')
            ->call('save');

        $afterSale = $this->sale->fresh()->toArray();

        // Note should be updated on sale
        $this->assertEquals('Strict isolated note update', $afterSale['note']);

        // All other sale columns remain identical
        unset($beforeSale['note'], $beforeSale['updated_at']);
        unset($afterSale['note'], $afterSale['updated_at']);
        $this->assertEquals($beforeSale, $afterSale);

        // Related entities remain completely untouched
        $this->assertEquals($beforeDetail, $detail->fresh()->toArray());
        $this->assertEquals($beforeDispatch, $dispatch->fresh()->toArray());
        $this->assertEquals($beforeDispatchDetail, $dispatchDetail->fresh()->toArray());
        $this->assertEquals($beforePayment, $payment->fresh()->toArray());
        $this->assertEquals($beforeReturn, $return->fresh()->toArray());
        $this->assertEquals($beforeStock, $stock->fresh()->toArray());
    }

    public function test_preserves_and_renders_multiline_note_with_pre_wrap()
    {
        $multilineNote = "First line\nSecond line";
        $this->sale->update(['note' => $multilineNote]);

        $this->actingAs($this->authorizedUser);

        Livewire::test(SaleNoteEditor::class, ['saleId' => $this->sale->id])
            ->assertSee($multilineNote)
            ->assertSeeHtml('style="white-space: pre-wrap;"')
            ->assertDontSeeHtml('text-wrap');
    }
}
