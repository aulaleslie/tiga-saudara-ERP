<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\Transfer\TransferV3GoodsForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Services\TransferScanResolverService;
use Modules\Adjustment\Services\TransferV3GoodsService;
use Modules\Adjustment\Tests\Support\BuildsV3TransferFixtures;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

/**
 * Tasks 3.1 and 3.4: cross-business, location-free scan resolution and the
 * version 3 goods form (labels, no locations, detail redirects). Scanner
 * focus/interaction is left to the human browser checklist.
 */
class TransferV3GoodsEntryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsV3TransferFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildV3Fixtures();
        $this->a1->update(['name' => 'SENTINEL-SOURCE-LOC']);
        $this->b1->update(['name' => 'SENTINEL-DEST-LOC']);
        $this->grant($this->actor, ['stockTransfers.create', 'stockTransfers.edit', 'stockTransfers.show']);
        $this->actingAs($this->actor);
    }

    private function conversion(Product $product, string $barcode, float $factor): ProductUnitConversion
    {
        $unit = Unit::create(['name' => 'Piece', 'short_name' => 'PCS']);
        $product->update(['base_unit_id' => $unit->id]);

        return ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'unit_conversion_name' => 'Box',
            'unit_conversion_code' => 'BOX',
            'conversion_factor' => $factor,
            'barcode' => $barcode,
        ]);
    }

    /**
     * Product barcode of one business collides with a conversion barcode of
     * a product stocked only in another business.
     */
    private function sharedBarcodeCollision(): void
    {
        $other = Product::create([
            'setting_id' => $this->businessB->id,
            'product_name' => 'Kabel Lain',
            'product_code' => 'OTHER-' . uniqid(),
            'product_cost' => 1,
            'product_price' => 1,
            'stock_managed' => true,
        ]);
        $this->stock($other, $this->b1, nonTax: 3);
        $this->bulk->update(['barcode' => 'SHARED-BARCODE']);
        $this->conversion($other, 'SHARED-BARCODE', 2);
    }

    /** @test */
    public function resolver_finds_serials_in_other_businesses_without_location_projection(): void
    {
        $result = app(TransferScanResolverService::class)->resolveAcrossBusinesses('SN-B1-1', false);

        $this->assertSame('resolved', $result['status']);
        $this->assertSame($this->serials[2]->id, $result['candidate']['serial']['id']);
        $this->assertArrayNotHasKey('location_id', $result['candidate']['serial']);
        $this->assertStringNotContainsString('SENTINEL', json_encode($result));
    }

    /** @test */
    public function resolver_rejects_serials_ineligible_for_condition_and_ambiguity_is_not_auto_resolved(): void
    {
        $this->assertSame('rejected', app(TransferScanResolverService::class)->resolveAcrossBusinesses('SN-A1-1', true)['status']);

        $this->sharedBarcodeCollision();

        $result = app(TransferScanResolverService::class)->resolveAcrossBusinesses('SHARED-BARCODE', false);
        $this->assertSame('ambiguous', $result['status']);
        $this->assertCount(2, $result['candidates']);
    }

    /** @test */
    public function form_has_no_location_controls_and_state_carries_no_provenance(): void
    {
        $component = Livewire::test(TransferV3GoodsForm::class)
            ->assertSee('Barang Baik')
            ->assertSee('Barang Rusak')
            ->assertSee('Simpan Draf')
            ->assertSee('Ajukan Persetujuan')
            ->assertDontSee('Lokasi Asal')
            ->assertDontSee('Lokasi Tujuan')
            ->call('scanBarcode', 'SN-B1-1')
            ->call('scanBarcode', 'SN-A1-1');

        $rows = $component->get('rows');
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['quantity']);
        $this->assertStringNotContainsString('SENTINEL', json_encode($component->instance()->all()));
        $component->assertDontSee('SENTINEL');
    }

    /** @test */
    public function duplicate_serial_scans_are_ignored_and_conversions_accumulate_whole_factors(): void
    {
        $this->conversion($this->bulk, 'BOX-OF-3', 3);

        $component = Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'SN-A1-1')
            ->call('scanBarcode', 'SN-A1-1')
            ->assertSet('scanMessage', 'Nomor seri SN-A1-1 sudah dipilih.')
            ->assertSet('scanMessageLevel', 'warning')
            ->call('scanBarcode', 'BOX-OF-3')
            ->call('scanBarcode', 'BOX-OF-3');

        $rows = collect($component->get('rows'))->keyBy('product_id');
        $this->assertCount(1, $rows[$this->serialized->id]['serials']);
        $this->assertSame(6, $rows[$this->bulk->id]['quantity']);
    }

    /** @test */
    public function ambiguous_scan_waits_for_explicit_token_bound_choice(): void
    {
        $this->sharedBarcodeCollision();

        $component = Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'SHARED-BARCODE', 'tok-amb')
            ->assertReturned(['status' => 'ambiguous', 'token' => 'tok-amb'])
            ->assertSet('pendingScanToken', 'tok-amb')
            ->assertSet('rows', []);

        $this->assertCount(2, $component->get('candidates'));

        // A choice without, or with another, token cannot apply to this scan.
        $component->call('chooseCandidate', 1)->assertReturned(['status' => 'stale', 'token' => null]);
        $component->call('chooseCandidate', 1, 'tok-other')->assertReturned(['status' => 'stale', 'token' => 'tok-other']);
        $this->assertSame([], $component->get('rows'));

        $component->call('chooseCandidate', 1, 'tok-amb')
            ->assertReturned(['status' => 'applied', 'token' => 'tok-amb'])
            ->assertSet('pendingScanToken', null)
            ->assertSet('candidates', []);
        $this->assertCount(1, $component->get('rows'));

        // A retried choice with the same token is a replay, not a second add.
        $component->call('chooseCandidate', 1, 'tok-amb')->assertReturned(['status' => 'replayed', 'token' => 'tok-amb']);
        $this->assertCount(1, $component->get('rows'));
    }

    /** @test */
    public function later_scans_do_not_clear_a_pending_ambiguity(): void
    {
        $this->sharedBarcodeCollision();

        $component = Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'SHARED-BARCODE', 'tok-amb')
            ->call('scanBarcode', 'SN-A1-1', 'tok-next')
            ->assertReturned(['status' => 'blocked', 'token' => 'tok-next'])
            ->assertSet('pendingScanToken', 'tok-amb')
            ->assertSet('rows', []);
        $this->assertCount(2, $component->get('candidates'));

        // Retrying the ambiguous scan itself keeps the same candidate list.
        $component->call('scanBarcode', 'SHARED-BARCODE', 'tok-amb')->assertReturned(['status' => 'ambiguous', 'token' => 'tok-amb']);

        // The blocked scan was not settled, so it applies once the choice is made.
        $component->call('cancelCandidates', 'tok-amb')->assertReturned(['status' => 'cancelled', 'token' => 'tok-amb']);
        $component->call('scanBarcode', 'SN-A1-1', 'tok-next')->assertReturned(['status' => 'applied', 'token' => 'tok-next']);
        $this->assertCount(1, $component->get('rows'));
    }

    /** @test */
    public function save_and_submit_are_refused_while_a_scan_awaits_a_choice(): void
    {
        $this->sharedBarcodeCollision();

        $component = Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'SN-A1-1')
            ->call('scanBarcode', 'SHARED-BARCODE', 'tok-amb');
        $count = Transfer::count();

        $component->call('saveDraft')
            ->assertNoRedirect()
            ->assertSet('errorMessage', fn ($message) => str_contains((string) $message, 'menunggu pilihan produk'));
        $component->call('submitForApproval')->assertNoRedirect();
        $this->assertSame($count, Transfer::count());

        // Condition changes are also held until the scan is resolved.
        $component->call('selectStockCondition', Transfer::CONDITION_BREAKAGE)
            ->assertSet('stockCondition', Transfer::CONDITION_GOOD)
            ->assertSet('confirmConditionChange', false);

        $component->call('cancelCandidates', 'tok-amb')
            ->call('saveDraft')
            ->assertRedirect(route('transfers.show', Transfer::latest('id')->first()));
    }

    /** @test */
    public function save_clears_a_previous_error_so_the_client_can_tell_success_from_failure(): void
    {
        $this->sharedBarcodeCollision();

        $component = Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'SN-B1-1')
            ->call('scanBarcode', 'SHARED-BARCODE', 'tok-amb')
            ->call('saveDraft')
            ->assertSet('errorMessage', fn ($message) => $message !== null);

        $component->call('cancelCandidates', 'tok-amb')
            ->call('submitForApproval')
            ->assertSet('errorMessage', null)
            ->assertRedirect();
    }

    /** @test */
    public function save_draft_and_create_submit_redirect_to_detail(): void
    {
        $draft = Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'SN-A1-1')
            ->call('setQuantity', 0, '2')
            ->call('saveDraft');

        $transfer = Transfer::latest('id')->first();
        $draft->assertRedirect(route('transfers.show', $transfer));
        $this->assertSame(Transfer::STATUS_DRAFT, $transfer->status);

        // Incomplete serials block submission with Bahasa Indonesia feedback.
        Livewire::test(TransferV3GoodsForm::class, ['transfer' => $transfer])
            ->call('submitForApproval')
            ->assertSet('errorMessage', fn ($message) => str_contains((string) $message, 'harus sama dengan jumlah nomor seri'));
        $this->assertSame(Transfer::STATUS_DRAFT, $transfer->fresh()->status);

        Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'SN-B1-1')
            ->call('submitForApproval')
            ->assertRedirect(route('transfers.show', Transfer::latest('id')->first()));
        $this->assertSame(Transfer::STATUS_PENDING, Transfer::latest('id')->first()->status);
    }

    /** @test */
    public function edit_form_requires_edit_permission_and_submits_existing_draft(): void
    {
        $draft = app(TransferV3GoodsService::class)->createDraft(Transfer::CONDITION_GOOD, $this->goodsLines(), $this->actor, $this->businessA->id);

        $this->get(route('transfers.edit', $draft))->assertOk()->assertSee('Ubah Transfer Stok')->assertDontSee('Lokasi Asal');

        Livewire::test(TransferV3GoodsForm::class, ['transfer' => $draft])
            ->call('submitForApproval')
            ->assertRedirect(route('transfers.show', $draft));
        $this->assertSame(Transfer::STATUS_PENDING, $draft->fresh()->status);

        $viewer = \App\Models\User::factory()->create();
        $viewer->givePermissionTo('stockTransfers.show');
        $this->actingAs($viewer);
        Livewire::test(TransferV3GoodsForm::class, ['transfer' => $draft->fresh()])
            ->call('saveDraft')
            ->assertForbidden();
    }

    /** @test */
    public function scan_input_never_falls_back_to_name_search(): void
    {
        Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'Kabel', 'tok-1')
            ->assertReturned(['status' => 'not_found', 'token' => 'tok-1'])
            ->assertSet('rows', [])
            ->assertSet('candidates', [])
            ->assertSet('searchResults', [])
            ->assertSet('scanMessageLevel', 'danger')
            ->assertSet('scanMessage', "Barcode atau nomor seri 'Kabel' tidak ditemukan.");
    }

    /** @test */
    public function search_modal_opens_closes_and_restores_scan_focus(): void
    {
        Livewire::test(TransferV3GoodsForm::class)
            ->assertSee('Cari Produk')
            ->assertDontSee('Cari Produk (Stok Dikelola)')
            ->call('openSearchModal')
            ->assertSet('showSearchModal', true)
            ->assertDispatched('v3-transfer-search-opened')
            ->assertSee('Cari Produk (Stok Dikelola)')
            ->assertSeeHtml('modal-dialog modal-lg')
            ->assertSeeHtml('data-v3-search-input')
            ->assertSee('Tutup')
            ->set('searchQuery', 'Kabel')
            ->call('closeSearchModal')
            ->assertSet('showSearchModal', false)
            ->assertSet('searchQuery', '')
            ->assertSet('searchResults', [])
            ->assertDispatched('v3-transfer-scan-focus', force: true)
            ->assertDontSee('Cari Produk (Stok Dikelola)');
    }

    /** @test */
    public function modal_search_requires_explicit_selection_even_for_one_match(): void
    {
        $component = Livewire::test(TransferV3GoodsForm::class)
            ->call('openSearchModal')
            ->set('searchQuery', 'Kabel')
            ->call('searchProducts') // Enter: search only
            ->assertSet('rows', [])
            ->assertSet('showSearchModal', true);

        $this->assertCount(1, $component->get('searchResults'));
        $this->assertSame($this->bulk->id, $component->get('searchResults')[0]['id']);
        $this->assertStringNotContainsString('SENTINEL', json_encode($component->get('searchResults')));
        $component->assertDontSee('SENTINEL');

        $component->call('selectSearchResult', 0)
            ->assertSet('showSearchModal', false)
            ->assertSet('searchQuery', '')
            ->assertDispatched('v3-transfer-scan-focus', force: true);
        $this->assertSame($this->bulk->id, $component->get('rows')[0]['product_id']);
        $this->assertSame(1, $component->get('rows')[0]['quantity']);
    }

    /** @test */
    public function modal_search_matches_code_barcode_category_and_brand_across_businesses(): void
    {
        $category = \Modules\Product\Entities\Category::create([
            'setting_id' => $this->businessA->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Aksesori Jaringan',
            'created_by' => $this->actor->id,
        ]);
        $brand = \Modules\Product\Entities\Brand::create(['setting_id' => $this->businessA->id, 'name' => 'MerekZeta', 'created_by' => $this->actor->id]);
        $this->bulk->update(['category_id' => $category->id, 'brand_id' => $brand->id, 'barcode' => 'BULK-BC-778']);

        $resolver = app(TransferScanResolverService::class);
        foreach (['aksesori', 'merekzeta', 'BULK-BC', $this->bulk->product_code, 'kabel merekzeta'] as $term) {
            $ids = array_map(fn ($r) => $r['product']['id'], $resolver->searchAcrossBusinesses($term, false));
            $this->assertContains($this->bulk->id, $ids, "term: {$term}");
        }

        $this->assertSame([], $resolver->searchAcrossBusinesses('kabel tidakada', false), 'every token must match');
        // Serialized product stocked only in another business is still discoverable.
        $ids = array_map(fn ($r) => $r['product']['id'], $resolver->searchAcrossBusinesses($this->serialized->product_code, false));
        $this->assertContains($this->serialized->id, $ids);
        $this->assertStringNotContainsString('SENTINEL', json_encode($resolver->searchAcrossBusinesses('kabel', false)));
    }

    /** @test */
    public function replayed_scan_token_is_applied_once_and_distinct_tokens_apply_in_order(): void
    {
        $this->conversion($this->bulk, 'BOX-OF-3', 3);

        $component = Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'BOX-OF-3', 'tok-1')->assertReturned(['status' => 'applied', 'token' => 'tok-1'])
            ->call('scanBarcode', 'BOX-OF-3', 'tok-1')->assertReturned(['status' => 'replayed', 'token' => 'tok-1'])
            ->call('scanBarcode', 'SN-A1-1', 'tok-2')
            ->call('scanBarcode', 'BOX-OF-3', 'tok-3');

        $rows = $component->get('rows');
        $this->assertSame([$this->bulk->id, $this->serialized->id], array_column($rows, 'product_id'));
        $this->assertSame(6, $rows[0]['quantity']);
        $this->assertCount(1, $rows[1]['serials']);
    }

    /** @test */
    public function scanner_terminators_are_stripped_and_blank_scans_are_ignored(): void
    {
        $component = Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', "SN-A1-1\r\n", 'tok-1')
            ->call('scanBarcode', "\r", 'tok-2')->assertReturned(['status' => 'empty', 'token' => 'tok-2']);

        $this->assertCount(1, $component->get('rows'));
        $this->assertSame('SN-A1-1', $component->get('rows')[0]['serials'][0]['serial_number']);
    }

    /** @test */
    public function rejected_and_duplicate_scans_settle_with_feedback(): void
    {
        $component = Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'SN-A1-1', 'tok-1')
            ->call('scanBarcode', 'SN-A1-1', 'tok-2')
            ->assertReturned(['status' => 'duplicate', 'token' => 'tok-2'])
            ->assertSet('scanMessageLevel', 'warning')
            ->call('selectStockCondition', Transfer::CONDITION_BREAKAGE)
            ->call('applyConditionChange')
            ->call('scanBarcode', 'SN-A1-1', 'tok-3')
            ->assertReturned(['status' => 'rejected', 'token' => 'tok-3'])
            ->assertSet('rows', [])
            ->assertSet('scanMessageLevel', 'danger');

        $this->assertNotEmpty($component->get('scanMessage'));
    }

    /** @test */
    public function view_renders_scan_input_search_button_and_coordinator_hooks(): void
    {
        Livewire::test(TransferV3GoodsForm::class)
            ->assertSeeHtml('data-v3-transfer-scan')
            ->assertSeeHtml('data-v3-open-search')
            ->assertSeeHtml('data-v3-action="saveDraft"')
            ->assertSeeHtml('data-v3-action="submitForApproval"')
            ->assertSeeHtml('id="v3-scan-queue-status"')
            ->assertSeeHtml('V3TransferScanCoordinator')
            ->assertSeeHtml('V3TransferLivewireTransport')
            ->assertDontSeeHtml('id="v3-product-search"')
            ->assertDontSeeHtml('wire:click="saveDraft"')
            ->assertSee('Scan Barcode / Nomor Seri');
    }

    /**
     * Two distinct serials of one product raise Jumlah 1 -> 2, and the
     * rendered quantity input carries that value (keyed by it, so the
     * browser replaces the input instead of relying on wire:model).
     *
     * @test
     */
    public function distinct_serials_raise_quantity_and_the_rendered_input_shows_it(): void
    {
        $second = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $this->serialized->id, 'location_id' => $this->a1->id,
            'serial_number' => 'NXDDESN00153303C1E6L01', 'tax_id' => null,
        ]);
        $this->serials[0]->update(['serial_number' => 'NXDDESN001533033AA6L01']);
        $key = fn (int $quantity) => 'wire:key="v3-qty-' . $this->serialized->id . '-' . $quantity . '"';

        $component = Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'NXDDESN001533033AA6L01', 'tok-1')
            ->assertSet('rows.0.quantity', 1)
            ->assertSeeHtml($key(1))
            ->assertSeeHtml('value="1"')
            ->call('scanBarcode', 'NXDDESN00153303C1E6L01', 'tok-2')
            ->assertSet('rows.0.quantity', 2)
            ->assertSeeHtml($key(2))
            ->assertDontSeeHtml($key(1))
            ->assertSee('2 dari 2 nomor seri dipilih');

        // Duplicate: neither serial count nor quantity changes.
        $component->call('scanBarcode', 'NXDDESN00153303C1E6L01', 'tok-3')
            ->assertReturned(['status' => 'duplicate', 'token' => 'tok-3'])
            ->assertSet('rows.0.quantity', 2)
            ->assertSeeHtml($key(2));
        $this->assertCount(2, $component->get('rows')[0]['serials']);
        $this->assertSame([$this->serials[0]->id, $second->id], array_column($component->get('rows')[0]['serials'], 'id'));
    }

    /** @test */
    public function a_manually_entered_higher_quantity_is_preserved_by_serial_scans(): void
    {
        $component = Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'SN-A1-1', 'tok-1')
            ->call('setQuantity', 0, '5')
            ->assertSet('rows.0.quantity', 5)
            ->assertSeeHtml('value="5"')
            ->call('scanBarcode', 'SN-B1-1', 'tok-2')
            ->assertSet('rows.0.quantity', 5)
            ->assertSee('2 dari 5 nomor seri dipilih');

        // Lowering below the serial count is kept as entered; submission rejects it.
        $component->call('setQuantity', 0, '1')->assertSet('rows.0.quantity', 1)
            ->call('submitForApproval')
            ->assertNoRedirect()
            ->assertSet('errorMessage', fn ($message) => str_contains((string) $message, 'melebihi jumlah yang diminta'));
    }

    /** @test */
    public function rapid_serial_scans_accumulate_quantity_in_order(): void
    {
        $component = Livewire::test(TransferV3GoodsForm::class);
        foreach (['SN-A1-1', 'SN-A1-2', 'SN-B1-1', 'SN-A1-2', 'SN-A1-1'] as $i => $code) {
            $component->call('scanBarcode', $code, 'tok-' . $i);
        }
        $row = $component->get('rows')[0];
        $this->assertSame(['SN-A1-1', 'SN-A1-2', 'SN-B1-1'], array_column($row['serials'], 'serial_number'));
        $this->assertSame(3, $row['quantity']);
        $component->assertSeeHtml('value="3"');
    }

    /** @test */
    public function quantity_input_is_server_rendered_and_edits_are_revalidated(): void
    {
        $component = Livewire::test(TransferV3GoodsForm::class)
            ->call('scanBarcode', 'SN-A1-1')
            ->assertDontSeeHtml('wire:model.blur="rows.0.quantity"')
            ->assertSeeHtml('wire:change="setQuantity(0, $event.target.value)"');

        $component->call('setQuantity', 7, '9')->assertSet('rows.0.quantity', 1); // unknown row ignored
        $component->call('setQuantity', 0, 'abc')->assertSet('rows.0.quantity', 0);

        $viewer = \App\Models\User::factory()->create();
        $this->actingAs($viewer);
        $component->call('setQuantity', 0, '3')->assertForbidden();
    }

    /** @test */
    public function stale_editor_submission_is_rejected_instead_of_overwriting_newer_edits(): void
    {
        $goods = app(TransferV3GoodsService::class);
        $draft = $goods->createDraft(Transfer::CONDITION_GOOD, $this->goodsLines(10, false), $this->actor, $this->businessA->id);

        $stalePage = Livewire::test(TransferV3GoodsForm::class, ['transfer' => $draft]);

        $goods->saveDraft($draft->fresh(), Transfer::CONDITION_GOOD, $this->goodsLines(7, false), $this->actor, $this->businessA->id);

        $stalePage->call('submitForApproval')
            ->assertSet('errorMessage', fn ($message) => str_contains((string) $message, 'telah diubah oleh proses lain'))
            ->assertNoRedirect();

        $fresh = $draft->fresh();
        $this->assertSame(Transfer::STATUS_DRAFT, $fresh->status);
        $this->assertSame(7, (int) $fresh->products()->first()->quantity);
    }
}
