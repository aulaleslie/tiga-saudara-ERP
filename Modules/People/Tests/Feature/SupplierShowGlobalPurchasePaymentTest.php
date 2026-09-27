<?php

namespace Modules\People\Tests\Feature;

use Tests\TestCase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

class SupplierShowGlobalPurchasePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting1 = Setting::factory()->create();
        $this->setting2 = Setting::factory()->create();
        session(['setting_id' => $this->setting1->id]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        \Spatie\Permission\Models\Permission::findOrCreate('purchases.received.correct', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.reporting-date.override', 'web');
    }

    public function test_supplier_detail_shows_embedded_workspace_and_renders_global_purchase_payments()
    {
        Gate::define('suppliers.show', fn() => true);
        Gate::define('purchasePayments.global.access', fn() => true);
        Gate::define('purchasePayments.create', fn() => true);

        $supplier = Supplier::factory()->create([
            'setting_id' => $this->setting1->id,
            'supplier_name' => 'Acme Supplier',
        ]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(15),
            'reference' => 'PO-SUPP-EMBEDDED-01',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 60000,
            'paid_amount' => 0,
            'due_amount' => 60000,
            'setting_id' => $this->setting2->id,
        ]);

        $response = $this->get(route('suppliers.show', $supplier->id));

        $response->assertSuccessful();
        $response->assertSee('ACME SUPPLIER');
        // New global purchase payment workspace header
        $response->assertSee('Pembayaran Pembelian Global');
        $response->assertSee('PO-SUPP-EMBEDDED-01');
        $response->assertSee(route('purchases.global-payments.create', ['supplier' => $supplier->id, 'purchase_id' => $purchase->id]));
    }

    public function test_supplier_detail_hides_embedded_workspace_when_user_lacks_global_access()
    {
        Gate::define('suppliers.show', fn() => true);
        Gate::define('purchasePayments.global.access', fn() => false);

        $supplier = Supplier::factory()->create([
            'setting_id' => $this->setting1->id,
            'supplier_name' => 'Beta Supplier',
        ]);

        $response = $this->get(route('suppliers.show', $supplier->id));

        $response->assertSuccessful();
        $response->assertSee('BETA SUPPLIER');
        $response->assertDontSee('Pembayaran Pembelian Global');
    }

    public function test_supplier_detail_shows_read_only_workspace_when_user_lacks_create_permission()
    {
        Gate::define('suppliers.show', fn() => true);
        Gate::define('purchasePayments.global.access', fn() => true);
        Gate::define('purchasePayments.create', fn() => false);

        $supplier = Supplier::factory()->create([
            'setting_id' => $this->setting1->id,
            'supplier_name' => 'Gamma Supplier',
        ]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(15),
            'reference' => 'PO-READONLY-01',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 45000,
            'paid_amount' => 0,
            'due_amount' => 45000,
            'setting_id' => $this->setting1->id,
        ]);

        $response = $this->get(route('suppliers.show', $supplier->id));

        $response->assertSuccessful();
        $response->assertSee('GAMMA SUPPLIER');
        $response->assertSee('PO-READONLY-01');
        $response->assertDontSee(route('purchases.global-payments.create', ['supplier' => $supplier->id, 'purchase_id' => $purchase->id]));
        $response->assertSee(route('purchases.global-payments.show', $purchase->id));
        $response->assertSee(route('purchases.global-payments.history', $purchase->id));
    }
}
