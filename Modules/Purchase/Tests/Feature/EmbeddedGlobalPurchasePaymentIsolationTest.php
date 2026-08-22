<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Supplier;
use Tests\TestCase;

class EmbeddedGlobalPurchasePaymentIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected Supplier $supplier1;
    protected Supplier $supplier2;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting1 = Setting::factory()->create();
        $this->setting2 = Setting::factory()->create();
        session(['setting_id' => $this->setting1->id]);

        $this->supplier1 = Supplier::factory()->create([
            'setting_id' => $this->setting1->id,
            'supplier_name' => 'Target Supplier',
        ]);
        $this->supplier2 = Supplier::factory()->create([
            'setting_id' => $this->setting2->id,
            'supplier_name' => 'Other Supplier',
        ]);

        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        \Illuminate\Support\Facades\Gate::define('purchasePayments.global.access', fn() => true);
        \Illuminate\Support\Facades\Gate::define('purchasePayments.create', fn() => true);
    }

    private function createPurchaseForSupplier(Supplier $supplier, Setting $setting, array $overrides = []): Purchase
    {
        return Purchase::create(array_merge([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 15000,
            'paid_amount' => 0,
            'due_amount' => 15000,
            'setting_id' => $setting->id,
        ], $overrides));
    }

    public function test_embedded_purchase_table_isolates_exact_supplier_across_businesses()
    {
        $supp1Po1 = $this->createPurchaseForSupplier($this->supplier1, $this->setting1, ['reference' => 'PO-SUPP1-S1']);
        $supp1Po2 = $this->createPurchaseForSupplier($this->supplier1, $this->setting2, ['reference' => 'PO-SUPP1-S2']);
        $supp2Po = $this->createPurchaseForSupplier($this->supplier2, $this->setting1, ['reference' => 'PO-SUPP2-S1']);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, [
            'globalMode' => true,
            'supplierId' => $this->supplier1->id,
        ])
            ->assertSee('PO-SUPP1-S1')
            ->assertSee('PO-SUPP1-S2')
            ->assertDontSee('PO-SUPP2-S1');
    }

    public function test_embedded_purchase_table_composes_supplier_constraint_with_business_filter()
    {
        $supp1Po1 = $this->createPurchaseForSupplier($this->supplier1, $this->setting1, ['reference' => 'PO-SUPP1-S1']);
        $supp1Po2 = $this->createPurchaseForSupplier($this->supplier1, $this->setting2, ['reference' => 'PO-SUPP1-S2']);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, [
            'globalMode' => true,
            'supplierId' => $this->supplier1->id,
        ])
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            ->call('applyGlobalFilters')
            ->assertSee('PO-SUPP1-S1')
            ->assertDontSee('PO-SUPP1-S2');
    }

    public function test_embedded_purchase_summary_cards_and_table_are_consistent_for_supplier()
    {
        // Target supplier: 2 unpaid purchases of 15000 each = 30000
        $this->createPurchaseForSupplier($this->supplier1, $this->setting1, ['due_amount' => 15000]);
        $this->createPurchaseForSupplier($this->supplier1, $this->setting2, ['due_amount' => 15000]);

        // Other supplier: 1 unpaid purchase of 40000
        $this->createPurchaseForSupplier($this->supplier2, $this->setting1, ['due_amount' => 40000]);

        $cardsComponent = Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, [
            'globalMode' => true,
            'supplierId' => $this->supplier1->id,
        ]);

        $piutang = $cardsComponent->get('belumDibayar');
        $this->assertEquals(2, $piutang['count']);
        $this->assertEquals(30000.0, $piutang['total']);
    }

    public function test_purchase_table_rejects_supplier_id_mutation()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [supplierId]');

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, [
            'globalMode' => true,
            'supplierId' => $this->supplier1->id,
        ])
            ->set('supplierId', $this->supplier2->id);
    }

    public function test_purchase_summary_cards_rejects_supplier_id_mutation()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [supplierId]');

        Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, [
            'globalMode' => true,
            'supplierId' => $this->supplier1->id,
        ])
            ->set('supplierId', $this->supplier2->id);
    }

    public function test_standalone_global_purchase_workspace_remains_unconstrained_by_supplier()
    {
        $supp1Po = $this->createPurchaseForSupplier($this->supplier1, $this->setting1, ['reference' => 'PO-CROSS-1']);
        $supp2Po = $this->createPurchaseForSupplier($this->supplier2, $this->setting2, ['reference' => 'PO-CROSS-2']);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, [
            'globalMode' => true,
            'supplierId' => null,
        ])
            ->assertSee('PO-CROSS-1')
            ->assertSee('PO-CROSS-2');

        $cards = Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, [
            'globalMode' => true,
            'supplierId' => null,
        ]);

        $piutang = $cards->get('belumDibayar');
        $this->assertEquals(2, $piutang['count']);
        $this->assertEquals(30000.0, $piutang['total']);
    }

    public function test_embedded_eligible_purchase_opens_existing_multi_invoice_payment_workflow()
    {
        $po1 = $this->createPurchaseForSupplier($this->supplier1, $this->setting1, ['due_amount' => 15000]);
        $po2 = $this->createPurchaseForSupplier($this->supplier1, $this->setting2, ['due_amount' => 25000]);

        $response = $this->get(route('purchases.global-payments.create', [
            'supplier' => $this->supplier1->id,
            'purchase_id' => $po1->id,
        ]));
        $response->assertSuccessful();
        $response->assertSee($po1->reference);
        $response->assertSee($po2->reference);
    }
}
