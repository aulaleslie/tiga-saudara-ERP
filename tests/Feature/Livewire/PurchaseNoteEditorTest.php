<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\PurchaseNoteEditor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseNoteEditorTest extends TestCase
{
    use RefreshDatabase;

    protected User $authorizedUser;
    protected User $unauthorizedUser;
    protected Setting $setting;
    protected Setting $foreignSetting;
    protected Purchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'purchases.update']);
        
        $this->authorizedUser = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Admin']);
        $role->givePermissionTo($permission);
        $this->authorizedUser->assignRole($role);

        $this->unauthorizedUser = User::factory()->create();

        $currency = \Modules\Currency\Entities\Currency::create([
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

        $codTerm = \Modules\Purchase\Entities\PaymentTerm::query()->firstOrCreate(
            ['name' => 'Cash on Delivery'],
            ['longevity' => 0]
        );

        $supplier = \Modules\People\Entities\Supplier::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $codTerm->id,
        ]);

        $this->purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'supplier_purchase_number' => null,
            'tax_ref_no' => null,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'note' => 'Original note',
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $codTerm->id,
        ]);
        
        session(['setting_id' => $this->setting->id]);
    }

    public function test_can_update_note_on_fully_received_purchase()
    {
        $this->actingAs($this->authorizedUser);

        Livewire::test(PurchaseNoteEditor::class, ['purchaseId' => $this->purchase->id])
            ->assertSet('canEdit', true)
            ->call('startEditing')
            ->set('note', 'Updated note')
            ->call('save')
            ->assertSet('editing', false)
            ->assertSet('note', 'Updated note');

        $this->assertDatabaseHas('purchases', [
            'id' => $this->purchase->id,
            'note' => 'Updated note',
        ]);
    }

    public function test_denies_note_update_for_unauthorized_user()
    {
        $this->actingAs($this->unauthorizedUser);

        Livewire::test(PurchaseNoteEditor::class, ['purchaseId' => $this->purchase->id])
            ->assertSet('canEdit', false)
            ->call('startEditing')
            ->assertForbidden();
    }

    public function test_denies_note_update_for_archived_purchase()
    {
        $this->actingAs($this->authorizedUser);

        $this->purchase->update(['archived_at' => now()]);

        Livewire::test(PurchaseNoteEditor::class, ['purchaseId' => $this->purchase->id])
            ->assertSet('canEdit', false)
            ->call('startEditing')
            ->assertForbidden();
    }

    public function test_denies_note_update_for_foreign_setting()
    {
        $this->actingAs($this->authorizedUser);

        session(['setting_id' => $this->foreignSetting->id]);

        Livewire::test(PurchaseNoteEditor::class, ['purchaseId' => $this->purchase->id])
            ->assertNotFound();
    }

    public function test_preserves_and_renders_multiline_note_with_pre_wrap()
    {
        $multilineNote = "First line\nSecond line";
        $this->purchase->update(['note' => $multilineNote]);

        $this->actingAs($this->authorizedUser);

        Livewire::test(PurchaseNoteEditor::class, ['purchaseId' => $this->purchase->id])
            ->assertSee($multilineNote)
            ->assertSeeHtml('style="white-space: pre-wrap;"')
            ->assertDontSeeHtml('text-wrap');
    }
}
