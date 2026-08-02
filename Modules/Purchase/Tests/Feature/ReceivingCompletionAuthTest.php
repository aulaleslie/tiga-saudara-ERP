<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Product\Entities\Product;
use Modules\Purchase\Livewire\Modals\PurchaseReceivingCompletionModal;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReceivingCompletionAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('purchases.receive.complete_shortfall', 'web');
        Permission::findOrCreate('purchases.receive', 'web');
        Permission::findOrCreate('purchases.access', 'web');

        $this->user = \App\Models\User::factory()->create(['is_active' => 1]);
        $this->setting = \Modules\Setting\Entities\Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);

        // Create base unit and location for tests
        \Modules\Setting\Entities\Unit::firstOrCreate(
            ['name' => 'PCS'],
            ['short_name' => 'pcs']
        );
    }

    public function test_preview_endpoint_denies_without_permission()
    {
        $purchase = $this->createPartiallyReceivedPurchase();

        $response = $this->actingAs($this->user)->get(
            route('purchases.receiving-completion.preview', $purchase)
        );

        $response->assertStatus(403);
    }

    public function test_submit_endpoint_denies_without_permission()
    {
        $purchase = $this->createPartiallyReceivedPurchase();

        $response = $this->actingAs($this->user)->post(
            route('purchases.receiving-completion.submit', $purchase),
            ['reason' => 'Test reason']
        );

        $response->assertStatus(403);
    }

    public function test_permission_seeding_creates_complete_shortfall_permission()
    {
        $permission = Permission::findByName('purchases.receive.complete_shortfall');
        $this->assertNotNull($permission);
    }

    public function test_preview_succeeds_with_permission()
    {
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');
        $purchase = $this->createPartiallyReceivedPurchase();

        $response = $this->actingAs($this->user)->get(
            route('purchases.receiving-completion.preview', $purchase)
        );

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertArrayHasKey('preview', $response->json());
    }

    public function test_preview_denies_for_foreign_setting()
    {
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');
        $foreignSetting = \Modules\Setting\Entities\Setting::factory()->create();
        $purchase = $this->createPartiallyReceivedPurchase($foreignSetting);

        $response = $this->actingAs($this->user)->get(
            route('purchases.receiving-completion.preview', $purchase)
        );

        $response->assertStatus(404);
    }

    public function test_preview_denies_for_archived_purchase()
    {
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');
        $purchase = $this->createPartiallyReceivedPurchase();
        $purchase->update(['archived_at' => now(), 'archived_by' => $this->user->id]);

        $response = $this->actingAs($this->user)->get(
            route('purchases.receiving-completion.preview', $purchase)
        );

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    public function test_livewire_openModal_denies_unauthorized_user()
    {
        $purchase = $this->createPartiallyReceivedPurchase();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unauthorized');

        Livewire::actingAs($this->user)
            ->test(PurchaseReceivingCompletionModal::class)
            ->call('openModal', $purchase);
    }

    public function test_livewire_openModal_denies_foreign_setting_purchase()
    {
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');
        $foreignSetting = \Modules\Setting\Entities\Setting::factory()->create();
        $purchase = $this->createPartiallyReceivedPurchase($foreignSetting);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Purchase does not belong to the active setting');

        Livewire::actingAs($this->user)
            ->test(PurchaseReceivingCompletionModal::class)
            ->call('openModal', $purchase);
    }

    public function test_livewire_openModal_succeeds_authorized_user_active_setting()
    {
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');
        $purchase = $this->createPartiallyReceivedPurchase();

        Livewire::actingAs($this->user)
            ->test(PurchaseReceivingCompletionModal::class)
            ->call('openModal', $purchase)
            ->assertSet('purchase.id', $purchase->id)
            ->assertSet('showModal', true)
            ->assertSet('preview', fn($preview) => is_array($preview) && isset($preview['purchase_id']));
    }

    public function test_livewire_openModal_loads_preview()
    {
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');
        $purchase = $this->createPartiallyReceivedPurchase();

        Livewire::actingAs($this->user)
            ->test(PurchaseReceivingCompletionModal::class)
            ->call('openModal', $purchase)
            ->assertSet('purchase', function ($p) use ($purchase) {
                return $p->id === $purchase->id;
            })
            ->assertSet('preview', function ($preview) {
                return is_array($preview) && isset($preview['purchase_id']);
            });
    }

    public function test_livewire_loadPreview_denies_foreign_setting()
    {
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');
        $purchase = $this->createPartiallyReceivedPurchase();

        $foreignSetting = \Modules\Setting\Entities\Setting::factory()->create();
        session(['setting_id' => $foreignSetting->id]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Purchase does not belong to the active setting');

        Livewire::actingAs($this->user)
            ->test(PurchaseReceivingCompletionModal::class)
            ->call('openModal', $purchase);
    }

    public function test_livewire_submit_denies_unauthorized_user()
    {
        $purchase = $this->createPartiallyReceivedPurchase();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unauthorized');

        Livewire::actingAs($this->user)
            ->test(PurchaseReceivingCompletionModal::class)
            ->set('purchase', $purchase)
            ->set('reason', 'Test reason')
            ->call('submit');
    }

    public function test_livewire_submit_denies_foreign_setting_purchase()
    {
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');
        $foreignSetting = \Modules\Setting\Entities\Setting::factory()->create();
        $purchase = $this->createPartiallyReceivedPurchase($foreignSetting);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Purchase does not belong to the active setting');

        Livewire::actingAs($this->user)
            ->test(PurchaseReceivingCompletionModal::class)
            ->set('purchase', $purchase)
            ->set('reason', 'Test reason')
            ->call('submit');
    }

    public function test_livewire_submit_succeeds_authorized_user()
    {
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');
        $purchase = $this->createPartiallyReceivedPurchase();

        Livewire::actingAs($this->user)
            ->test(PurchaseReceivingCompletionModal::class)
            ->call('openModal', $purchase)
            ->set('reason', 'Supplier could not deliver remaining items')
            ->call('submit')
            ->assertDispatched('purchaseReceivingCompleted');

        $purchase->refresh();
        $this->assertEquals(Purchase::STATUS_RECEIVED, $purchase->status);
    }

    public function test_livewire_submit_shows_success_message()
    {
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');
        $purchase = $this->createPartiallyReceivedPurchase();

        Livewire::actingAs($this->user)
            ->test(PurchaseReceivingCompletionModal::class)
            ->call('openModal', $purchase)
            ->set('reason', 'Supplier could not deliver remaining items')
            ->call('submit')
            ->assertSet('showModal', false)
            ->assertSet('successMessage', 'Penerimaan berhasil diselesaikan.')
            ->assertSeeHtml('Penerimaan berhasil diselesaikan.');

        // Verify opening modal again clears the success message
        Livewire::actingAs($this->user)
            ->test(PurchaseReceivingCompletionModal::class)
            ->call('openModal', $purchase)
            ->assertSet('successMessage', null);
    }

    public function test_livewire_unauthorized_attempt_leaves_purchase_unchanged()
    {
        $purchase = $this->createPartiallyReceivedPurchase();
        $originalStatus = $purchase->status;
        $originalDetails = $purchase->purchaseDetails->count();

        try {
            Livewire::actingAs($this->user)
                ->test(PurchaseReceivingCompletionModal::class)
                ->call('openModal', $purchase);
        } catch (\Exception $e) {
            // Expected to throw exception
        }

        $purchase->refresh();
        $this->assertEquals($originalStatus, $purchase->status);
        $this->assertEquals($originalDetails, $purchase->purchaseDetails->count());
    }

    public function test_receiving_history_action_visible_for_authorized_partial_purchase()
    {
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');
        $purchase = $this->createPartiallyReceivedPurchase();

        $this->actingAs($this->user)->get(route('purchases.show', $purchase));

        $view = view('purchase::partials.actions-receiving', ['data' => $purchase])->render();

        $this->assertStringContainsString('Selesaikan Penerimaan', $view);
    }

    public function test_receiving_history_action_hidden_for_unauthorized_user()
    {
        $purchase = $this->createPartiallyReceivedPurchase();

        $view = view('purchase::partials.actions-receiving', ['data' => $purchase])->render();

        $this->assertStringNotContainsString('Selesaikan Penerimaan', $view);
    }

    public function test_receiving_history_action_hidden_for_non_partial_purchase()
    {
        $this->user->givePermissionTo('purchases.receive.complete_shortfall');
        $purchase = $this->createPartiallyReceivedPurchase();
        $purchase->update(['status' => \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED]);

        $view = view('purchase::partials.actions-receiving', ['data' => $purchase])->render();

        $this->assertStringNotContainsString('Selesaikan Penerimaan', $view);
    }

    private function createPartiallyReceivedPurchase($setting = null)
    {
        $setting = $setting ?? $this->setting;
        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $setting->id,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'base_unit_id' => \Modules\Setting\Entities\Unit::first()->id,
            'setting_id' => $setting->id,
            'product_cost' => 500,
            'product_price' => 1000,
        ]);

        $location = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $setting->id]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-TEST-' . uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'RECEIVED PARTIALLY',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $setting->id,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'status' => 'APPROVED',
            'approved_at' => now(),
            'approved_by' => $this->user->id,
            'location_id' => $location->id,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail->id,
            'quantity_received' => 5,
        ]);

        return $purchase;
    }
}
