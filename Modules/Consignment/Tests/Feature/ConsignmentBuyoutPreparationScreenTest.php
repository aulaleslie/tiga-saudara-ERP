<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Three-step preparation screen: grouped selection, serial numbers instead of IDs, derived tax buckets,
 * per-business destinations, blocked goods with explanations, preserved selections, draft-only save.
 */
class ConsignmentBuyoutPreparationScreenTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);
        config(['consignment.buyouts.ui_enabled' => true]);
    }

    private function userWith(array $perms): User
    {
        $u = User::factory()->create();
        $u->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
            $u->givePermissionTo($p);
        }

        return $u;
    }

    private function maker(): User
    {
        return $this->userWith(['consignments.buyouts.access', 'consignments.buyouts.create', 'consignments.buyouts.acquire_unsold']);
    }

    private function page(User $u, array $query = [])
    {
        return $this->actingAs($u)->withSession(['setting_id' => $this->setting->id])
            ->get(route('consignments.buyouts.create', $query + ['supplier_id' => $this->supplier->id]));
    }

    private function save(User $u, array $lines, array $extra = [])
    {
        return $this->actingAs($u)->withSession(['setting_id' => $this->setting->id])->post(route('consignments.buyouts.store'), $extra + [
            'supplier_id' => $this->supplier->id, 'date' => '2026-11-12',
            'destinations' => [$this->setting->id => $this->dest1->id],
            'invoice_metadata' => ['supplier_invoice_number' => 'INV-1', 'invoice_date' => '2026-11-12'],
            'lines' => $lines,
        ]);
    }

    private function sideEffects(): array
    {
        return array_map(fn ($t) => \DB::table($t)->count(), ['consignment_buyout_reservations', 'consignment_active_serial_claims', 'purchases']);
    }

    /** @test */
    public function step_two_groups_by_receipt_then_product_then_unsold_and_sold_with_identities_hidden(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $src = $this->soldSource('4');
        $this->capacity()->setAttribution($src->id, [$r->id => '4'], null, 'attribute');
        $this->stock('6');
        $receiptNo = $r->receiving->receiving_number;

        $html = $this->page($this->maker())->assertOk()
            ->assertSee('Supplier &amp; Tujuan', false)->assertSee('Pilih Barang')->assertSee('Tinjau &amp; Simpan Draft', false)
            ->assertDontSee('Pilih semua yang tersedia')->assertDontSee('Batalkan semua pilihan')->assertSee('Simpan Draft')
            ->assertSee('Tagihan supplier diterbitkan untuk')->assertSee($this->setting->company_name)
            ->assertSeeInOrder(['Penerimaan: ' . $receiptNo, 'Produk: ' . $this->product->product_name, 'Belum terjual', 'Terjual belum ditagih', 'Penjualan ' . $src->sale->reference])
            ->assertDontSee('pisah koma')->assertDontSee('Sumber terjual #')->assertDontSee('Cari nomor serial')
            ->getContent();

        [$selectionHtml, $reviewHtml] = explode('3. Tinjau dan simpan draft', $html, 2);
        $this->assertSame(1, substr_count($selectionHtml, 'Produk: ' . e($this->product->product_name)), 'one selection product heading per receipt, not per row');
        $this->assertSame(1, substr_count($reviewHtml, 'Produk: ' . e($this->product->product_name)), 'review groups the product once under its receipt');
        $this->assertStringContainsString('Penerimaan: ' . e($receiptNo), $reviewHtml);
        $this->assertStringContainsString('Belum terjual', $reviewHtml);
        $this->assertStringContainsString('Terjual belum ditagih', $reviewHtml);
        $this->assertStringContainsString('name="lines[u0][consignment_receiving_detail_id]" value="' . $r->id . '"', $html, 'identity kept as hidden line data');
        $this->assertStringContainsString('name="lines[s0][consignment_sold_source_id]" value="' . $src->id . '"', $html);
        $this->assertStringContainsString('name="destinations[' . $this->setting->id . ']"', $html);
        $this->assertStringNotContainsString('[buckets]', $html, 'tax buckets are derived, not typed');
        $this->assertStringContainsString('toggleGroup(', $html, 'receipt- and product-level controls');
        $this->assertStringContainsString('$el.indeterminate', $html, 'partial-selection indicator');
    }

    /** @test */
    public function each_eligible_serial_is_one_checkbox_shown_once_and_checked_serials_define_the_quantity(): void
    {
        \DB::table('products')->where('id', $this->product->id)->update(['serial_number_required' => true]);
        $r = $this->receipt($this->supplier, '3');
        $this->stock('3');
        $ids = [];
        foreach (['SN-ALPHA', 'SN-BRAVO', 'SN-CHARLIE'] as $sn) {
            $ids[$sn] = ProductSerialNumber::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'serial_number' => $sn,
                'consignment_receiving_detail_id' => $r->id, 'is_broken' => false, 'status' => 'active'])->id;
        }

        $html = $this->page($this->maker())->assertOk()->assertSeeInOrder(['Belum terjual', 'SN-ALPHA', 'SN-BRAVO', 'SN-CHARLIE'])->getContent();
        foreach ($ids as $sn => $id) {
            $this->assertSame(1, substr_count($html, 'data-serial="' . $sn . '"'), "{$sn} appears exactly once");
            $this->assertMatchesRegularExpression('/type="checkbox" value="' . $id . '" name="lines\[u0\]\[serial_ids\]\[\]"/', $html);
        }
        $this->assertStringNotContainsString('<select multiple', $html, 'no searchable multi-select any more');

        $this->save($this->maker(), ['u0' => ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id,
            'source_location_id' => $this->location->id, 'serial_ids' => [$ids['SN-ALPHA'], $ids['SN-CHARLIE']]]])->assertSessionHasNoErrors();
        $b = ConsignmentBuyout::with('lines.serials')->latest('id')->firstOrFail();
        $this->assertEquals('2.000', $b->lines->first()->base_quantity);
        $this->assertEqualsCanonicalizing([$ids['SN-ALPHA'], $ids['SN-CHARLIE']], $b->lines->first()->serials->pluck('product_serial_number_id')->map(fn ($x) => (int) $x)->all());
    }

    /** @test */
    public function sold_units_show_the_sales_document_with_a_link_only_when_permitted(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $src = $this->soldSource('2');
        $this->capacity()->setAttribution($src->id, [$r->id => '2'], null, 'attribute');
        $this->stock('8');
        $link = 'href="' . route('sales.show', $src->sale_id) . '"';

        $this->assertStringNotContainsString($link, $this->page($this->maker())->getContent(), 'no link without sales.show');
        $html = $this->page($this->userWith(['consignments.buyouts.access', 'consignments.buyouts.create', 'sales.show']))->getContent();
        $this->assertStringContainsString($link, $html);
        $this->assertStringContainsString('Penjualan ' . $src->sale->reference, $html);
        $this->assertStringNotContainsString('/sales/' . $src->sale_id . '"', str_replace($link, '', $html), 'the id appears only inside the permitted URL');
    }

    /** @test */
    public function a_pos_sale_prefers_the_originating_pos_receipt_and_names_the_selling_business(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $src = $this->soldSource('2');
        $this->capacity()->setAttribution($src->id, [$r->id => '2'], null, 'attribute');
        $this->stock('8');
        $cashier = \Modules\Setting\Entities\Setting::create(['company_name' => 'Kasir Lain', 'company_email' => 'k@e.com', 'company_phone' => '0811',
            'default_currency_id' => $this->setting->default_currency_id, 'default_currency_position' => 'prefix', 'notification_email' => 'k@e.com',
            'footer_text' => 'F', 'company_address' => 'A', 'is_pkp' => false, 'document_prefix' => 'KSR']);
        \DB::statement('PRAGMA defer_foreign_keys = ON');   // POS session/terminal rows are irrelevant here; the test transaction never commits
        $checkout = array_filter(['pos_transaction_id' => null, 'setting_id' => $cashier->id, 'pos_session_id' => 1, 'terminal_id' => 1, 'cashier_user_id' => 1,
            'status' => 'FINALIZED', 'idempotency_key' => 'k1', 'payload_hash' => 'h', 'payment_method_code' => 'CASH', 'receipt_number' => 'KSR-RCP-0042',
            'sale_id' => $src->sale_id, 'created_at' => now(), 'updated_at' => now()], fn ($v, $k) => \Illuminate\Support\Facades\Schema::hasColumn('pos_checkouts', $k), ARRAY_FILTER_USE_BOTH);
        $cid = \DB::table('pos_checkouts')->insertGetId($checkout);
        \DB::table('pos_checkout_sales')->insert(['pos_checkout_id' => $cid, 'split_key' => 'x', 'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id, 'tax_bucket' => 'NON_TAX', 'sale_id' => $src->sale_id, 'created_at' => now(), 'updated_at' => now()]);

        $this->page($this->maker())->assertOk()
            ->assertSeeInOrder(['Terjual belum ditagih', 'POS KSR-RCP-0042', 'Penjualan ' . $src->sale->reference, 'POS: ' . strtoupper('Kasir Lain'), 'Penjualan: ' . $this->setting->company_name]);
    }

    /** @test */
    public function zero_unsold_stock_is_an_informational_summary_while_sold_units_remain_selectable(): void
    {
        $receipt = $this->receipt($this->supplier, '2');
        $source = $this->soldSource('2');
        $this->capacity()->setAttribution($source->id, [$receipt->id => '2'], null, 'attribute');
        $this->stock('0');

        $html = $this->page($this->maker())->assertOk()
            ->assertSee('Tidak ada stok tersisa di lokasi ini (1 baris produk/lokasi)')
            ->assertSee('Terjual belum ditagih')
            ->assertDontSee('Barang yang belum dapat dipilih')->getContent();
        $this->assertStringContainsString('id="empty-stock-summary"', $html);
        $this->assertStringContainsString('name="lines[s0][consignment_sold_source_id]" value="' . $source->id . '"', $html);
        $this->assertSame(0, ConsignmentBuyout::count());
    }

    /** @test */
    public function fully_billed_sales_are_summarised_not_listed_as_blocked_units(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $src = $this->soldSource('2');
        $this->capacity()->setAttribution($src->id, [$r->id => '2'], null, 'attribute');
        $this->stock('8');
        $b = ConsignmentBuyout::create(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'reference' => 'BO-X', 'status' => 'FINALIZED', 'date' => now()]);
        \Modules\Consignment\Entities\ConsignmentBuyoutLine::create(['consignment_buyout_id' => $b->id, 'line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $r->id,
            'product_id' => $this->product->id, 'consignment_sold_source_id' => $src->id, 'source_location_id' => $this->location->id,
            'destination_location_id' => $this->dest1->id, 'base_quantity' => '2', 'original_snapshot' => []]);

        $html = $this->page($this->maker())->assertOk()->assertSee('1 penjualan sudah ditagih')->getContent();
        $this->assertStringNotContainsString('name="lines[s0]', $html);
        $this->assertStringNotContainsString('Tidak ada kuantitas terjual yang tersedia', $html);
    }

    /** @test */
    public function a_complete_mixed_selection_saves_one_draft_only_with_derived_buckets_and_the_step_one_destination(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $src = $this->soldSource('4');
        $this->capacity()->setAttribution($src->id, [$r->id => '4'], null, 'attribute');
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 6,
            'quantity_tax' => 4, 'quantity_non_tax' => 2, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0]);
        $before = $this->sideEffects();

        $this->save($this->maker(), [
            'u0' => ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'source_location_id' => $this->location->id, 'base_quantity' => '6'],
            's0' => ['line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $r->id, 'consignment_sold_source_id' => $src->id, 'base_quantity' => '4'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $b = ConsignmentBuyout::with('lines')->latest('id')->firstOrFail();
        $this->assertSame('DRAFT', $b->status);
        $this->assertSame($before, $this->sideEffects(), 'saving creates a draft only');
        $unsold = $b->lines->firstWhere('line_type', 'UNSOLD_BUYOUT');
        $this->assertEquals(['TAXED' => '4', 'NON_TAXED' => '2'], $unsold->original_snapshot['buckets'], 'non-taxed first, then taxed');
        $this->assertSame((int) $this->dest1->id, (int) $unsold->destination_location_id);
        $this->assertSame((int) $this->dest1->id, (int) $b->lines->firstWhere('line_type', 'SOLD_UNBILLED')->destination_location_id);
        $this->assertSame([], app(\Modules\Consignment\Services\ConsignmentBuyoutLifecycleService::class)->validateDocument($b));
    }

    /** @test */
    public function a_partial_selection_saves_exactly_the_entered_quantity_and_server_validation_stays_authoritative(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $line = fn ($q) => ['u0' => ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'source_location_id' => $this->location->id, 'base_quantity' => $q]];

        $this->save($this->maker(), $line('2.5'))->assertSessionHasNoErrors();
        $b = ConsignmentBuyout::with('lines')->latest('id')->firstOrFail();
        $this->assertEquals('2.500', $b->lines->first()->base_quantity);
        $this->assertEquals(['TAXED' => '0', 'NON_TAXED' => '2.5'], $b->lines->first()->original_snapshot['buckets']);

        // Without a destination the server does not guess one.
        $count = ConsignmentBuyout::count();
        $this->save($this->maker(), $line('1'), ['destinations' => []])->assertSessionHasErrors('lines.0.destination_location_id');
        $this->assertSame($count, ConsignmentBuyout::count());
    }

    /** @test */
    public function blocked_goods_are_listed_separately_with_a_plain_explanation_and_an_action_and_are_not_selectable(): void
    {
        $this->receipt($this->supplier, '10');
        $this->stock('10');
        $src = $this->soldSource('3');                       // unattributed sale in the pool

        $html = $this->page($this->maker())->assertOk()
            ->assertSee('Barang yang belum dapat dipilih')
            ->assertSee('belum dihubungkan (diatribusikan) ke penerimaan supplier')
            ->assertSee('Penjualan ' . $src->sale->reference)
            ->assertSee(route('consignments.reconciliation.index'))
            ->getContent();
        $this->assertStringNotContainsString('name="lines[u0]', $html, 'a blocked receipt is not offered for selection');
        $this->assertStringContainsString('Tidak ada barang supplier ini yang dapat dipilih', $html);
    }

    /** @test */
    public function a_failed_save_returns_to_review_with_the_selection_and_destinations_restored(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $lines = ['u0' => ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'source_location_id' => $this->location->id, 'base_quantity' => '3']];

        $html = $this->actingAs($this->maker())->withSession(['setting_id' => $this->setting->id, '_old_input' => [
            'lines' => $lines, 'destinations' => [$this->setting->id => $this->dest2->id],
            'pricing_intent' => ['global_discount_type' => 'percentage', 'global_discount_value' => '5'],
        ]])->get(route('consignments.buyouts.create', ['supplier_id' => $this->supplier->id]))->assertOk()->getContent();

        $cfg = $this->config($html);
        $this->assertSame('3', $cfg['old']['u0']['base_quantity']);

        $this->assertSame((int) $this->dest2->id, (int) $cfg['destinations'][$this->setting->id]);
        $this->assertSame(3, $cfg['startStep']);
        $this->assertSame('percentage', $cfg['oldDiscount']['type']);
    }

    /** @test */
    public function a_real_failed_save_flashes_lines_that_still_carry_their_row_keys(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $res = $this->save($this->maker(), ['u0' => ['row_key' => 'u0', 'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id,
            'source_location_id' => $this->location->id, 'base_quantity' => '3']], ['date' => '']);

        $res->assertRedirect()->assertSessionHasErrors('date');
        $this->assertSame(0, ConsignmentBuyout::count());
        $flashed = collect($res->baseResponse->getSession()->get('_old_input.lines'))->first();
        $this->assertSame('u0', $flashed['row_key'] ?? null);
        $this->assertSame((int) $this->dest1->id, (int) $res->baseResponse->getSession()->get('_old_input.destinations.' . $this->setting->id));
    }

    /** @test */
    public function rows_carry_receipt_prices_and_limits_used_for_review_totals(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->price($r, 1500);
        $src = $this->soldSource('4');
        $this->capacity()->setAttribution($src->id, [$r->id => '4'], null, 'attribute');
        $this->stock('6');

        $cfg = $this->config($this->page($this->maker())->getContent());
        $this->assertEquals(1500.0, $cfg['rows']['u0']['unit_price']);
        $this->assertEquals(6.0, $cfg['rows']['u0']['max']);
        $this->assertEquals(4.0, $cfg['rows']['s0']['max']);
        $this->assertEquals(4.0, $cfg['soldLimits'][(string) $src->id]);
        $this->assertEquals(6.0, $cfg['pools'][$this->product->id . ':' . $this->location->id]['non_taxed']);
    }

    /** @test */
    public function permissions_and_the_mutation_gate_are_unchanged(): void
    {
        $this->receipt($this->supplier, '4');
        $this->stock('4');

        $this->page($this->userWith([]))->assertForbidden();

        $viewer = $this->userWith(['consignments.buyouts.access']);
        $html = $this->page($viewer)->assertOk()->assertDontSee('<i class="bi bi-save"></i> Simpan Draft', false)
            ->assertSee('tidak memiliki izin membuat draft')->getContent();
        $this->assertStringNotContainsString('name="lines[', $html, 'inspection only without create permission');

        config(['consignment.buyouts.ui_enabled' => false]);
        $html = $this->page($this->maker())->assertOk()->assertSee('belum diaktifkan')->getContent();
        $this->assertStringNotContainsString('name="lines[', $html, 'no draft fields while the feature is disabled');
        $this->actingAs($this->maker())->withSession(['setting_id' => $this->setting->id])
            ->post(route('consignments.buyouts.store'), [])->assertNotFound();
    }

    private function config(string $html): array
    {
        $this->assertSame(1, preg_match('/<script type="application\/json" id="buyout-prep-data">(.*?)<\/script>/s', $html, $m));

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
