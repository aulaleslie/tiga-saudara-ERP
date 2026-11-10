<?php

namespace Modules\Consignment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Product\Entities\Category;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConsignmentBuyoutPurchaseReceivingTest extends TestCase
{
    use RefreshDatabase;

    protected $settingA;
    protected $settingB;
    protected $user;
    protected $locationA1;
    protected $locationA2;
    protected $locationB1;

    protected function setUp(): void
    {
        parent::setUp();
        
        Currency::create([
             'id' => 1,
             'currency_name' => 'Rupiah',
             'code' => 'IDR',
             'symbol' => 'Rp',
             'thousand_separator' => '.',
             'decimal_separator' => ',',
             'exchange_rate' => 1,
        ]);

        $this->settingA = Setting::create([
            'id' => 1,
            'company_name' => 'Setting A',
            'company_email' => 'a@test.com',
            'company_phone' => '1',
            'notification_email' => 'a@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->settingB = Setting::create([
            'id' => 2,
            'company_name' => 'Setting B',
            'company_email' => 'b@test.com',
            'company_phone' => '2',
            'notification_email' => 'b@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);
        
        $this->user = User::factory()->create();
        Permission::findOrCreate('purchases.receive', 'web');
        Permission::findOrCreate('purchases.receive.approval', 'web');
        $this->user->givePermissionTo(['purchases.receive', 'purchases.receive.approval']);
        
        $this->actingAs($this->user);

        // Required for some models
        Category::create([
            'id' => 1,
            'category_code' => 'CAT01',
            'category_name' => 'Test Category',
            'setting_id' => $this->settingA->id,
            'created_by' => $this->user->id,
        ]);

        Unit::create([
            'id' => 1,
            'operator' => '*',
            'operation_value' => 1,
            'short_name' => 'pc',
            'name' => 'Piece',
            'setting_id' => $this->settingA->id,
        ]);

        \Modules\People\Entities\Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->settingA->id,
        ]);

        $this->locationA1 = Location::create(['id' => 1, 'name' => 'Loc A1', 'setting_id' => $this->settingA->id]);
        $this->locationA2 = Location::create(['id' => 2, 'name' => 'Loc A2', 'setting_id' => $this->settingA->id]);
        $this->locationB1 = Location::create(['id' => 3, 'name' => 'Loc B1', 'setting_id' => $this->settingB->id]);
    }

    private function purchaseWithPendingNote(Product $product, Location $into, string $ref): ReceivedNote
    {
        $purchase = Purchase::create(['date' => now(), 'due_date' => now()->addDays(30), 'reference' => $ref, 'supplier_id' => 1,
            'supplier_name' => 'Supplier', 'status' => Purchase::STATUS_APPROVED, 'payment_status' => 'Unpaid', 'payment_method' => 'Cash',
            'total_amount' => 1000, 'paid_amount' => 0, 'due_amount' => 1000, 'setting_id' => $this->settingA->id]);
        $detail = PurchaseDetail::create(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => $product->product_name,
            'product_code' => $product->product_code, 'quantity' => 10, 'price' => 1000, 'unit_price' => 1000, 'sub_total' => 10000,
            'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        $note = ReceivedNote::create(['po_id' => $purchase->id, 'date' => now(), 'location_id' => $into->id, 'status' => ReceivedNote::STATUS_PENDING]);
        ReceivedNoteDetail::create(['received_note_id' => $note->id, 'quantity_received' => 10, 'po_detail_id' => $detail->id]);

        return $note;
    }

    /** @test */
    public function ordinary_purchase_receiving_coordinates_the_product_and_leaves_buyout_reservations_intact(): void
    {
        $product = Product::create(['product_name' => 'Test Product', 'product_code' => 'TPB1', 'product_unit' => 'pc', 'product_cost' => 1000,
            'product_price' => 2000, 'product_quantity' => 10, 'setting_id' => $this->settingA->id, 'category_id' => 1, 'unit_id' => 1, 'stock_managed' => 1]);
        $stock = ProductStock::create(['product_id' => $product->id, 'location_id' => $this->locationA1->id, 'quantity' => 10, 'quantity_tax' => 0,
            'quantity_non_tax' => 10, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]);

        // Approved buyout reserving 6 of the 10 consignment units at A1 (A1 holds the consignment receipt).
        $supplier = \Modules\People\Entities\Supplier::find(1);
        $receival = ConsignmentReceival::create(['setting_id' => 1, 'supplier_id' => $supplier->id, 'reference' => 'CR-PB', 'receival_number' => 'CR-PB', 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => 1, 'location_id' => $this->locationA1->id, 'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-PB', 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $product->id, 'product_name' => 'P', 'product_code' => 'P',
            'quantity' => 10, 'unit_cost' => 1, 'unit_dpp' => 1, 'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);
        $crd = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $product->id, 'quantity_received' => 10, 'received_base_quantity' => 10, 'unit_cost' => 1, 'unit_dpp' => 1]);
        $std = Location::create(['name' => 'Std', 'setting_id' => 1, 'is_consignment' => false]);
        $buyUser = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve'] as $p) {
            Permission::findOrCreate($p, 'web');
            $buyUser->givePermissionTo($p);
        }
        $buyUser->settings()->attach(1, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft(1, $supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $crd->id, 'destination_location_id' => $std->id,
            'base_quantity' => '6', 'buckets' => ['NON_TAXED' => '6'],
        ]], $buyUser);
        $buyout = $svc->approve($svc->submit($b, $buyUser), $buyUser);

        session(['setting_id' => $this->settingA->id]);
        $note = $this->purchaseWithPendingNote($product, $this->locationA1, 'PO-PB-1');
        $this->actingAs($this->user)->post(route('receivings.approve', $note->id))->assertSessionHasNoErrors();

        $this->assertSame(20, (int) $stock->fresh()->quantity);
        $this->assertSame(2, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());
        $this->assertSame(['setting_id' => 1, 'product_id' => $product->id],
            collect(\DB::table('consignment_inventory_coordinations')->first(['setting_id', 'product_id']))->map(fn ($v) => (int) $v)->all());
        // The receipt only added stock; the buyout still protects its own 6 units at the location.
        $this->assertSame(6.0, \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::reservedQuantity($product->id, $this->locationA1->id));
    }

    /** @test */
    public function cancelling_an_ordinary_receiving_cannot_remove_goods_an_approved_buyout_reserved(): void
    {
        $product = Product::create(['product_name' => 'Test Product', 'product_code' => 'TPB2', 'product_unit' => 'pc', 'product_cost' => 1000,
            'product_price' => 2000, 'product_quantity' => 2, 'setting_id' => $this->settingA->id, 'category_id' => 1, 'unit_id' => 1, 'stock_managed' => 1]);
        $stock = ProductStock::create(['product_id' => $product->id, 'location_id' => $this->locationA1->id, 'quantity' => 2, 'quantity_tax' => 0,
            'quantity_non_tax' => 2, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]);
        $supplier = \Modules\People\Entities\Supplier::find(1);
        $receival = ConsignmentReceival::create(['setting_id' => 1, 'supplier_id' => $supplier->id, 'reference' => 'CR-PC', 'receival_number' => 'CR-PC', 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => 1, 'location_id' => $this->locationA1->id, 'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-PC', 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $product->id, 'product_name' => 'P', 'product_code' => 'P',
            'quantity' => 10, 'unit_cost' => 1, 'unit_dpp' => 1, 'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);
        $crd = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $product->id, 'quantity_received' => 10, 'received_base_quantity' => 10, 'unit_cost' => 1, 'unit_dpp' => 1]);

        // An ordinary purchase receiving adds 10 units, so 12 are physically present...
        session(['setting_id' => $this->settingA->id]);
        $note = $this->purchaseWithPendingNote($product, $this->locationA1, 'PO-PC-1');
        $this->actingAs($this->user)->post(route('receivings.approve', $note->id))->assertSessionHasNoErrors();
        $this->assertSame(12, (int) $stock->fresh()->quantity);

        // ...and an approved buyout reserves 10 of them.
        $std = Location::create(['name' => 'Std', 'setting_id' => 1, 'is_consignment' => false]);
        $buyUser = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve', 'consignments.buyouts.cancel'] as $p) {
            Permission::findOrCreate($p, 'web');
            $buyUser->givePermissionTo($p);
        }
        $buyUser->settings()->attach(1, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft(1, $supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $crd->id, 'destination_location_id' => $std->id,
            'base_quantity' => '10', 'buckets' => ['NON_TAXED' => '10'],
        ]], $buyUser);
        $buyout = $svc->approve($svc->submit($b, $buyUser), $buyUser);

        // Cancelling the 10-unit receiving would leave 2 units against 10 reserved: refused, nothing changes.
        Permission::findOrCreate('purchases.receive.cancel', 'web');
        $this->user->givePermissionTo('purchases.receive.cancel');
        $purchase = Purchase::find($note->po_id);
        $stockBefore = (float) $stock->fresh()->quantity_non_tax;
        $txBefore = \DB::table('transactions')->count();
        $this->actingAs($this->user)->withSession(['setting_id' => 1])
            ->post(route('purchases.receivings.cancel', $purchase->id), ['reason' => 'salah input']);

        $this->assertSame($stockBefore, (float) $stock->fresh()->quantity_non_tax);
        $this->assertSame(12.0, (float) $product->fresh()->product_quantity);
        $this->assertSame($txBefore, \DB::table('transactions')->count());
        $this->assertSame(2, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());

        // Negative control: once the buyout is cancelled the same cancellation goes through.
        $svc->cancel($buyout->fresh(), 'release', $buyUser);
        $this->actingAs($this->user)->withSession(['setting_id' => 1])
            ->post(route('purchases.receivings.cancel', $purchase->id), ['reason' => 'salah input']);
        $this->assertSame(2.0, (float) $stock->fresh()->quantity_non_tax);
    }
}
