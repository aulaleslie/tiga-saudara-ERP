<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferApprovalAllocation;
use Modules\Adjustment\Entities\TransferApprovalAllocationSerial;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Entities\TransferRequestRevision;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StockTransfersListSearchTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Location $origin;
    private Location $destination;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);

        foreach (['stockTransfers.access', 'stockTransfers.show'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->setting = Setting::factory()->create();
        $this->origin = Location::factory()->create(['setting_id' => $this->setting->id]);
        $this->destination = Location::factory()->create(['setting_id' => $this->setting->id]);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['stockTransfers.access', 'stockTransfers.show']);
        session(['setting_id' => $this->setting->id]);
    }

    private function product(string $name, ?string $barcode = null): Product
    {
        return Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => $name,
            'product_code' => 'P-' . uniqid(),
            'barcode' => $barcode,
            'product_cost' => 1000,
            'product_price' => 2000,
            'stock_managed' => true,
        ]);
    }

    private function transfer(array $attrs = []): Transfer
    {
        return Transfer::create($attrs + [
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'status' => Transfer::STATUS_PENDING,
            'created_by' => $this->user->id,
        ]);
    }

    private function serial(Product $product, string $number): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->origin->id,
            'serial_number' => $number,
        ]);
    }

    private function search(string $term, array $extra = []): array
    {
        foreach (['datatables', 'datatables.request', 'datatables.config'] as $binding) {
            app()->forgetInstance($binding);
        }

        $response = $this->actingAs($this->user)->getJson(route('transfers.index', $extra + [
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'search' => ['value' => $term],
            'columns' => [['data' => 'document_number', 'name' => 'document_number', 'searchable' => 'true', 'orderable' => 'true']],
            'order' => [['column' => 0, 'dir' => 'desc']],
        ]), ['X-Requested-With' => 'XMLHttpRequest']);

        $response->assertOk();

        return $response->json();
    }

    private function ids(string $term): array
    {
        return collect($this->search($term)['data'])->map(fn ($r) => (int) $r['id'])->sort()->values()->all();
    }

    /** @test */
    public function it_matches_v3_draft_object_serials_and_many_common_matches(): void
    {
        $p = $this->product('Draft Item');
        $draftSerial = $this->serial($p, 'DRAFT-OBJ-1');

        $now = now();
        \DB::table('product_serial_numbers')->insert(array_map(fn ($i) => [
            'product_id' => $p->id, 'location_id' => $this->origin->id,
            'serial_number' => 'COMMON-' . $i, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, 250)));
        $lastCommon = ProductSerialNumber::where('serial_number', 'COMMON-250')->first();

        $draft = $this->transfer(['workflow_version' => Transfer::WORKFLOW_V3, 'origin_location_id' => null, 'destination_location_id' => null]);
        TransferProduct::create([
            'transfer_id' => $draft->id, 'product_id' => $p->id, 'quantity' => 1,
            'serial_numbers' => [['id' => $draftSerial->id, 'serial_number' => 'DRAFT-OBJ-1']],
        ]);
        $late = $this->transfer();
        TransferProduct::create([
            'transfer_id' => $late->id, 'product_id' => $p->id, 'quantity' => 1,
            'serial_numbers' => [$lastCommon->id],
        ]);

        $this->assertSame([$draft->id], $this->ids('draft-obj'));
        $this->assertSame([$late->id], $this->ids('common'));
    }

    /** @test */
    public function it_matches_product_only_present_in_a_historical_request_revision(): void
    {
        $removed = $this->product('Removed Before Allocation', '5550001');
        $kept = $this->product('Kept Item');
        $t = $this->transfer(['workflow_version' => Transfer::WORKFLOW_V3, 'origin_location_id' => null, 'destination_location_id' => null]);
        TransferRequestRevision::create([
            'transfer_id' => $t->id, 'revision_number' => 1, 'stock_condition' => 'GOOD',
            'lines' => [['product_id' => $removed->id, 'quantity' => 1, 'serials' => []]],
        ]);
        TransferRequestRevision::create([
            'transfer_id' => $t->id, 'revision_number' => 2, 'stock_condition' => 'GOOD',
            'lines' => [['product_id' => $kept->id, 'quantity' => 1, 'serials' => []]],
        ]);

        $this->assertSame([$t->id], $this->ids('removed before'));
        $this->assertSame([$t->id], $this->ids('5550001'));
        $this->assertSame([$t->id], $this->ids('kept item'));
    }

    /** @test */
    public function it_keeps_status_and_date_search(): void
    {
        $t = $this->transfer();

        $this->assertSame([$t->id], $this->ids('pending'));
        $this->assertSame([$t->id], $this->ids(date('Y-m-d')));
    }

    /** @test */
    public function it_matches_current_product_name_and_primary_barcode_once_per_transfer(): void
    {
        $a = $this->product('Alpha Widget', '8999001');
        $b = $this->product('Alpha Gadget', '8999002');
        $t = $this->transfer();
        TransferProduct::create(['transfer_id' => $t->id, 'product_id' => $a->id, 'quantity' => 1]);
        TransferProduct::create(['transfer_id' => $t->id, 'product_id' => $b->id, 'quantity' => 1]);
        $other = $this->transfer();

        $this->assertSame([$t->id], $this->ids('alpha'));
        $this->assertSame([$t->id], $this->ids('8999001'));
        $this->assertSame([$t->id], $this->ids('Alpha Widget'));
        $this->assertSame([], $this->ids('unrelated-term'));

        $a->update(['product_name' => 'Renamed Thing', 'barcode' => '7770001']);
        $this->assertSame([$t->id], $this->ids('renamed'));
        $this->assertSame([$t->id], $this->ids('7770001'));
        $this->assertSame([$t->id], $this->ids('Alpha Gadget'));
        $this->assertNotContains($other->id, $this->ids('alpha'));
    }

    /** @test */
    public function it_keeps_document_number_search_and_escapes_wildcards(): void
    {
        $t = $this->transfer();
        $this->transfer();

        $this->assertSame([$t->id], $this->ids((string) $t->document_number));
        $this->assertSame([], $this->ids('%'));
        $this->assertSame([], $this->ids('_'));
    }

    /** @test */
    public function it_ignores_conversion_barcodes(): void
    {
        $p = $this->product('Plain Item', '1111');
        $t = $this->transfer();
        TransferProduct::create(['transfer_id' => $t->id, 'product_id' => $p->id, 'quantity' => 1]);

        $columns = \Schema::getColumnListing('product_unit_conversions');
        if (in_array('barcode', $columns, true)) {
            \DB::table('product_unit_conversions')->insert(array_filter([
                'product_id' => $p->id,
                'unit_id' => \DB::table('units')->insertGetId(['name' => 'Box', 'short_name' => 'bx', 'setting_id' => $this->setting->id, 'created_at' => now(), 'updated_at' => now()]),
                'base_unit_id' => $p->base_unit_id ?? 1,
                'barcode' => 'CONV-9999',
                'conversion_factor' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ], fn ($v, $k) => in_array($k, $columns, true), ARRAY_FILTER_USE_BOTH));
        }

        $this->assertSame([], $this->ids('CONV-9999'));
    }

    /** @test */
    public function it_matches_legacy_serials_by_text(): void
    {
        $p = $this->product('Serial Item');
        $s1 = $this->serial($p, 'SN-LEGACY-100');
        $this->serial($p, 'SN-NEVER-ON-TRANSFER');
        $t = $this->transfer();
        TransferProduct::create([
            'transfer_id' => $t->id, 'product_id' => $p->id, 'quantity' => 1,
            'serial_numbers' => [$s1->id],
        ]);

        $this->assertSame([$t->id], $this->ids('legacy-100'));
        $this->assertSame([$t->id], $this->ids('SN-LEGACY-100'));
        $this->assertSame([], $this->ids('NEVER-ON'));
    }

    /** @test */
    public function it_matches_v3_request_allocation_and_movement_serials(): void
    {
        $p = $this->product('V3 Item');
        $reqSerial = $this->serial($p, 'REQ-SERIAL-1');
        $allocSerial = $this->serial($p, 'ALLOC-SERIAL-2');
        $unrelated = $this->serial($p, 'FREE-SERIAL-3');

        $t = $this->transfer(['workflow_version' => Transfer::WORKFLOW_V3, 'origin_location_id' => null, 'destination_location_id' => null]);
        $revision = TransferRequestRevision::create([
            'transfer_id' => $t->id,
            'revision_number' => 1,
            'stock_condition' => 'GOOD',
            'lines' => [['product_id' => $p->id, 'quantity' => 1, 'serials' => [['id' => $reqSerial->id, 'serial_number' => 'REQ-SERIAL-1']]]],
        ]);
        $allocation = TransferApprovalAllocation::create([
            'transfer_id' => $t->id, 'request_revision_id' => $revision->id, 'configuration_revision' => 1,
            'product_id' => $p->id, 'source_location_id' => $this->origin->id, 'quantity' => 1,
        ]);
        TransferApprovalAllocationSerial::create([
            'transfer_approval_allocation_id' => $allocation->id, 'transfer_id' => $t->id,
            'request_revision_id' => $revision->id, 'configuration_revision' => 1,
            'product_serial_number_id' => $allocSerial->id,
        ]);

        $t2 = $this->transfer(['workflow_version' => Transfer::WORKFLOW_V3, 'origin_location_id' => null, 'destination_location_id' => null]);
        $movement = TransferMovement::create([
            'transfer_id' => $t2->id,
            'type' => 'DISPATCH',
            'status' => 'CANCELLED',
            'created_by' => $this->user->id,
            'transfer_revision' => 1,
            'stock_condition' => 'GOOD',
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
        ]);
        $line = \Modules\Adjustment\Entities\TransferMovementLine::create([
            'transfer_movement_id' => $movement->id,
            'product_id' => $p->id,
            'quantity' => 1,
        ]);
        TransferMovementSerial::create([
            'transfer_movement_id' => $movement->id,
            'transfer_movement_line_id' => $line->id,
            'product_id' => $p->id,
            'serial_number' => 'MOVE-SERIAL-4',
            'stock_condition' => 'GOOD',
        ]);

        $this->assertSame([$t->id], $this->ids('req-serial'));
        $this->assertSame([$t->id], $this->ids('ALLOC-SERIAL-2'));
        $this->assertSame([$t2->id], $this->ids('move-serial'));
        $this->assertSame([], $this->ids('FREE-SERIAL'));
        $this->assertSame([$t->id, $t2->id], $this->ids('serial'));
    }

    /** @test */
    public function it_preserves_visibility_sorting_pagination_and_actions(): void
    {
        $p = $this->product('Paged Product');
        $foreign = Setting::factory()->create();
        $foreignLoc = Location::factory()->create(['setting_id' => $foreign->id]);
        $foreignLoc2 = Location::factory()->create(['setting_id' => $foreign->id]);

        $visible = [];
        foreach (range(1, 3) as $i) {
            $t = $this->transfer();
            TransferProduct::create(['transfer_id' => $t->id, 'product_id' => $p->id, 'quantity' => 1]);
            $visible[] = $t->id;
        }
        $hidden = $this->transfer(['origin_location_id' => $foreignLoc->id, 'destination_location_id' => $foreignLoc2->id]);
        TransferProduct::create(['transfer_id' => $hidden->id, 'product_id' => $p->id, 'quantity' => 1]);

        $result = $this->search('paged', ['length' => 2]);

        $this->assertSame(3, $result['recordsFiltered']);
        $this->assertCount(2, $result['data']);
        $this->assertArrayHasKey('action', $result['data'][0]);
        $this->assertNotContains($hidden->id, collect($result['data'])->pluck('id')->all());
        $this->assertSame($visible[2], (int) $result['data'][0]['id']);
    }
}
