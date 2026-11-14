<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutPurchaseLineage;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Services\PurchaseMonetaryEditService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tab-3 financial entry over HTTP: preview, draft save with product terms / invoice data / attachments, reopening
 * DRAFT and REJECTED drafts in the same wizard, and the full draft -> approval -> Purchase -> monetary edit path.
 */
class ConsignmentBuyoutInvoiceEntryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    private User $maker;
    private array $wizardLines;
    private int $soldDetailId;
    private int $unsoldDetailId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);
        config(['consignment.buyouts.ui_enabled' => true]);
        Storage::fake('public');
        config(['media-library.disk_name' => 'public']);

        $a = $this->receipt($this->supplier, '10');
        $this->price($a, 100);
        $b = $this->receipt($this->supplier, '3');
        $this->price($b, 120);
        $src = $this->soldSource('5');
        $this->capacity()->setAttribution($src->id, [$a->id => '5'], null, 'attribute');
        $this->stock('8');
        $this->std($this->otherStd, 5);
        ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'sale_price' => 1,
            'last_purchase_price' => 777, 'average_purchase_price' => 100]);
        $this->soldDetailId = $a->id;
        $this->unsoldDetailId = $b->id;

        $this->wizardLines = [
            's0' => ['row_key' => 's0', 'line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $a->id, 'consignment_sold_source_id' => $src->id, 'base_quantity' => '2'],
            'u0' => ['row_key' => 'u0', 'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $b->id, 'source_location_id' => $this->location->id, 'base_quantity' => '3'],
        ];

        $this->maker = User::factory()->create();
        $this->maker->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        foreach (['consignments.buyouts.access', 'consignments.buyouts.create', 'consignments.buyouts.edit', 'consignments.buyouts.submit',
            'consignments.buyouts.approve', 'consignments.buyouts.reject', 'consignments.allocations.approve', 'consignments.buyouts.acquire_unsold',
            'consignments.buyouts.finalize', 'consignments.billing.convert', 'purchases.update', 'purchases.received.monetary.edit'] as $p) {
            Permission::findOrCreate($p, 'web');
            $this->maker->givePermissionTo($p);
        }
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['setting_id' => $this->setting->id]);
    }

    private function payload(array $product = ['unit_price' => '110'], array $extra = []): array
    {
        return array_replace_recursive([
            'supplier_id' => $this->supplier->id,
            'destinations' => [$this->setting->id => $this->dest1->id],
            'invoice_metadata' => ['supplier_invoice_number' => 'INV-77', 'invoice_date' => '2026-11-12', 'tax_ref_no' => 'FP-1'],
            'notes' => 'Faktur gabungan',
            'pricing_intent' => ['products' => [$this->product->id => $product], 'global_discount_type' => 'fixed', 'global_discount_value' => '10'],
            'lines' => $this->wizardLines,
        ], $extra);
    }

    /** @test */
    public function preview_returns_one_product_row_and_requires_explicit_price_for_differing_receipts(): void
    {
        $lines = array_values(array_map(fn ($l) => array_intersect_key($l, array_flip(['line_type', 'consignment_receiving_detail_id', 'base_quantity'])), $this->wizardLines));
        $res = $this->as($this->maker)->postJson(route('consignments.buyouts.preview'), ['supplier_id' => $this->supplier->id, 'seq' => 7, 'lines' => $lines,
            'pricing_intent' => ['products' => []]])->assertOk()->json();
        $this->assertSame(7, $res['seq']);
        $this->assertFalse($res['valid']);
        $this->assertCount(1, $res['products']);
        $this->assertTrue($res['products'][0]['needs_price']);
        $this->assertEquals(5.0, $res['products'][0]['quantity']);

        $res = $this->as($this->maker)->postJson(route('consignments.buyouts.preview'), ['supplier_id' => $this->supplier->id, 'lines' => $lines,
            'pricing_intent' => ['products' => [$this->product->id => ['row_total_override' => '500']]]])->json();
        $this->assertTrue($res['valid']);
        $this->assertEquals(100.0, $res['products'][0]['accepted']['unit_price']);
        $this->assertEquals(500.0, $res['totals']['total_amount']);

        // A foreign supplier's receipt cannot be priced.
        $other = $this->supplier('Other');
        $this->as($this->maker)->postJson(route('consignments.buyouts.preview'), ['supplier_id' => $other->id, 'lines' => $lines])->assertStatus(422);
    }

    /** @test */
    public function draft_save_persists_product_terms_invoice_fields_and_attachments_without_a_purchase(): void
    {
        $term = PaymentTerm::create(['name' => 'NET 30', 'longevity' => 30, 'is_active' => true]);
        $file = UploadedFile::fake()->createWithContent('faktur.pdf', "%PDF-1.4\n%TEST\n");
        $this->as($this->maker)->post(route('consignments.buyouts.store'), $this->payload(['unit_price' => '110'], [
            'invoice_metadata' => ['payment_term_id' => $term->id],
            'attachments' => [$file],
        ]))->assertRedirect();

        $b = ConsignmentBuyout::sole();
        $this->assertSame('DRAFT', $b->status);
        $this->assertSame(2, $b->pricing_intent['version']);
        $this->assertEquals('110', $b->pricing_intent['products'][$this->product->id]['unit_price']);
        $this->assertSame('INV-77', $b->invoice_metadata['supplier_invoice_number']);
        $this->assertSame('2026-12-12', $b->invoice_metadata['due_date'], 'due date derived from the payment term');
        $this->assertSame('2026-11-12', $b->date->toDateString(), 'invoice date is the document date');
        $this->assertCount(1, $b->invoice_metadata['attachments']);
        $this->assertSame(1, $b->getMedia(ConsignmentBuyout::ATTACHMENT_COLLECTION)->count());
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, DB::table('consignment_buyout_reservations')->count());

        // A manual due date survives an invoice-date change.
        $this->as($this->maker)->put(route('consignments.buyouts.update', $b->id), $this->payload(['unit_price' => '110'], [
            'revision' => $b->revision,
            'invoice_metadata' => ['invoice_date' => '2026-11-20', 'payment_term_id' => $term->id, 'due_date' => '2026-12-31', 'due_date_manual' => '1'],
            'keep_attachments' => [$b->invoice_metadata['attachments'][0]['media_id']],
        ]))->assertRedirect(route('consignments.buyouts.show', $b->id));
        $b = $b->fresh();
        $this->assertSame('2026-12-31', $b->invoice_metadata['due_date']);
        $this->assertCount(1, $b->invoice_metadata['attachments'], 'kept attachment retained');
    }

    /** @test */
    public function invalid_terms_and_files_are_rejected_without_a_partial_draft(): void
    {
        $this->as($this->maker)->from(route('consignments.buyouts.create'))->post(route('consignments.buyouts.store'), $this->payload([]))
            ->assertRedirect(route('consignments.buyouts.create'));
        $this->assertSame(0, ConsignmentBuyout::count(), 'differing prices without an explicit price');
        $this->assertSame(0, DB::table('media')->count());

        $this->as($this->maker)->post(route('consignments.buyouts.store'), $this->payload(['unit_price' => '-5']))->assertSessionHasErrors();
        $this->as($this->maker)->post(route('consignments.buyouts.store'), $this->payload(['unit_price' => '110'], [
            'attachments' => [UploadedFile::fake()->create('evil.exe', 10)]]))->assertSessionHasErrors('attachments.0');
        $this->assertSame(0, ConsignmentBuyout::count());

        // Pricing failure after files were staged: the draft and the newly stored file are both gone.
        $this->as($this->maker)->post(route('consignments.buyouts.store'), $this->payload(['unit_price' => '110'], [
            'pricing_intent' => ['global_discount_value' => '99999'],
            'attachments' => [UploadedFile::fake()->createWithContent('f.pdf', "%PDF-1.4\n")]]));
        $this->assertSame(0, ConsignmentBuyout::count());
        $this->assertSame(0, DB::table('media')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        // Explicit zero price is a deliberate value.
        $r = $this->as($this->maker)->post(route('consignments.buyouts.store'), $this->payload(['unit_price' => '0'], ['pricing_intent' => ['global_discount_value' => '0']]));
        $r->assertSessionHasNoErrors();
        $this->assertSame(1, ConsignmentBuyout::count(), json_encode(session()->all()));
    }

    /** @test */
    public function rejected_draft_reopens_in_the_wizard_and_revises_back_to_draft(): void
    {
        $this->as($this->maker)->post(route('consignments.buyouts.store'), $this->payload(['unit_price' => '110']));
        $b = ConsignmentBuyout::sole();
        $life = new ConsignmentBuyoutLifecycleService();
        $life->reject($life->submit($b, $this->maker), 'Harga salah', $this->maker);
        $b = $b->fresh();
        $this->assertSame('REJECTED', $b->status);

        $html = $this->as($this->maker)->get(route('consignments.buyouts.edit', $b->id))->assertOk()
            ->assertSee('Faktur supplier dan simpan draft')->assertSee('INV-77')
            ->assertSee(route('consignments.buyouts.update', $b->id))->assertSee('name="_method" value="PUT"', false)->getContent();
        preg_match('/<script type="application\/json" id="buyout-prep-data">(.*?)<\/script>/s', $html, $m);
        $cfg = json_decode($m[1], true);
        $byDetail = collect($cfg['old'])->mapWithKeys(fn ($o, $k) => [$cfg['rows'][$k]['fields']['line_type'] . ':' . $cfg['rows'][$k]['fields']['consignment_receiving_detail_id'] => (float) $o['base_quantity']])->all();
        ksort($byDetail);
        $this->assertSame(['SOLD_UNBILLED:' . $this->soldDetailId => 2.0, 'UNSOLD_BUYOUT:' . $this->unsoldDetailId => 3.0], $byDetail);
        $this->assertEquals('110', $cfg['terms'][$this->product->id]['unit_price']);
        $this->assertSame(3, $cfg['startStep']);

        $revision = $b->revision;
        $this->as($this->maker)->put(route('consignments.buyouts.update', $b->id), $this->payload(['row_total_override' => '520'], ['revision' => $revision]))
            ->assertRedirect(route('consignments.buyouts.show', $b->id));
        $b = $b->fresh();
        $this->assertSame('DRAFT', $b->status);
        $this->assertSame($revision + 1, $b->revision);
        $this->assertEquals('520', $b->pricing_intent['products'][$this->product->id]['row_total_override']);
        $this->assertSame(2, $b->lines()->count(), 'selections preserved');

        // Stale revision is rejected without replacing anything.
        $this->as($this->maker)->put(route('consignments.buyouts.update', $b->id), $this->payload(['unit_price' => '1'], ['revision' => $revision]));
        $this->assertEquals('520', $b->fresh()->pricing_intent['products'][$this->product->id]['row_total_override']);
    }

    /** @test */
    public function edit_is_denied_for_approved_foreign_or_unauthorized_requests(): void
    {
        $this->as($this->maker)->post(route('consignments.buyouts.store'), $this->payload(['unit_price' => '110']));
        $b = ConsignmentBuyout::sole();

        $viewer = User::factory()->create();
        $viewer->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        $viewer->givePermissionTo('consignments.buyouts.access');
        $this->as($viewer)->get(route('consignments.buyouts.edit', $b->id))->assertForbidden();
        $this->as($viewer)->put(route('consignments.buyouts.update', $b->id), $this->payload(['unit_price' => '1'], ['revision' => $b->revision]))->assertForbidden();

        $this->actingAs($this->maker)->withSession(['setting_id' => $this->setting->id + 999])->get(route('consignments.buyouts.edit', $b->id))->assertNotFound();

        $life = new ConsignmentBuyoutLifecycleService();
        $life->approve($life->submit($b, $this->maker), $this->maker);
        $this->as($this->maker)->get(route('consignments.buyouts.edit', $b->id))->assertRedirect(route('consignments.buyouts.show', $b->id));
        $hash = $b->fresh()->approved_revision_hash;
        $this->as($this->maker)->put(route('consignments.buyouts.update', $b->id), $this->payload(['unit_price' => '1'], ['revision' => $b->fresh()->revision]));
        $this->assertSame('APPROVED', $b->fresh()->status);
        $this->assertSame($hash, $b->fresh()->approved_revision_hash);
        $this->assertEquals('110', $b->fresh()->pricing_intent['products'][$this->product->id]['unit_price']);
    }

    /** @test */
    public function tab_three_draft_flows_through_approval_to_one_detail_purchase_and_monetary_edit(): void
    {
        $file = UploadedFile::fake()->createWithContent('faktur.pdf', "%PDF-1.4\n%FLOW\n");
        $this->as($this->maker)->post(route('consignments.buyouts.store'), $this->payload(['unit_price' => '110'], ['attachments' => [$file]]))->assertRedirect();
        $b = ConsignmentBuyout::sole();
        $life = new ConsignmentBuyoutLifecycleService();
        $life->approve($life->submit($b, $this->maker), $this->maker);

        $this->as($this->maker)->post(route('consignments.buyouts.finalize', $b->id), ['confirm' => '1'])->assertRedirect(route('consignments.buyouts.show', $b->id));
        $b = $b->fresh();
        $this->assertSame('FINALIZED', $b->status);
        $purchase = Purchase::findOrFail($b->purchase_id);

        // 5 units x 110 = 550, less 10 document discount.
        $this->assertEquals(540.0, (float) $purchase->total_amount);
        $this->assertSame('INV-77', $purchase->supplier_purchase_number);
        $this->assertSame('FP-1', $purchase->tax_ref_no);
        $this->assertSame('2026-11-12', $purchase->reporting_date->toDateString(), 'reporting date starts at the invoice date');
        $this->assertSame(1, $purchase->purchaseDetails()->count());
        $detail = $purchase->purchaseDetails()->sole();
        $this->assertEquals([5.0, 110.0, 550.0], [(float) $detail->quantity, (float) $detail->unit_price, (float) $detail->sub_total]);
        $links = ConsignmentBuyoutPurchaseLineage::where('purchase_id', $purchase->id)->get();
        $this->assertCount(2, $links);
        $this->assertEquals(540.0, round($links->sum('accepted_amount'), 2));
        $this->assertSame(1, $purchase->getMedia('attachments')->count());
        $this->assertSame('CONSIGNMENT_BUYOUT', $purchase->getMedia('attachments')->first()->getCustomProperty('source'));

        // Standard monetary edit on the generated Purchase.
        $editPage = $this->as($this->maker)->get(route('purchases.edit', $purchase->id));
        $this->assertSame(200, $editPage->status(), substr(strip_tags((string) $editPage->getContent()), 0, 600));
        $cost = DB::table('consignment_buyout_cost_events')->get()->toArray();
        $cart = collect([(object) ['id' => $this->product->id, 'name' => 'W', 'qty' => 5.0, 'price' => 100.0, 'options' => collect([
            PurchaseMonetaryEditService::DETAIL_ID_OPTION => $detail->id, 'product_id' => $this->product->id, 'unit_price' => 100.0, 'sub_total' => 500.0,
            'sub_total_before_tax' => 500.0, 'product_tax_amount' => 0.0, 'product_discount' => 0, 'product_discount_type' => 'fixed', 'code' => 'W'])]]);
        $this->as($this->maker);
        session(['setting_id' => $this->setting->id]);
        app(PurchaseMonetaryEditService::class)->apply($purchase, $cart, ['global_discount' => 0, 'global_discount_type' => 'fixed', 'shipping' => 0]);
        $purchase = $purchase->fresh();
        $this->assertEquals(500.0, (float) $purchase->total_amount);
        $this->assertSame([$detail->id], $purchase->purchaseDetails()->pluck('id')->all());
        $this->assertEquals($cost, DB::table('consignment_buyout_cost_events')->get()->toArray(), 'acquisition cost untouched');
        $this->assertEquals(540.0, round(ConsignmentBuyoutPurchaseLineage::where('purchase_id', $purchase->id)->sum('accepted_amount'), 2), 'lineage keeps accepted evidence');
    }

    /** @test */
    public function reopened_legacy_draft_with_conflicting_receipt_prices_requires_one_product_price(): void
    {
        // A draft saved before product-level terms: legacy (unversioned) intent, receipts at 100 and 120.
        $life = new ConsignmentBuyoutLifecycleService();
        $lines = array_values(array_map(fn ($l) => array_diff_key($l, ['row_key' => 1]) + ['destination_location_id' => $this->dest1->id], $this->wizardLines));
        $lines[1]['buckets'] = ['NON_TAXED' => '3'];
        $b = $life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', $lines, $this->maker,
            ['invoice_metadata' => ['invoice_date' => '2026-11-10'], 'pricing_intent' => ['global_discount_type' => 'fixed', 'global_discount_value' => 0]]);

        $html = $this->as($this->maker)->get(route('consignments.buyouts.edit', $b->id))->assertOk()->getContent();
        preg_match('/<script type="application\\/json" id="buyout-prep-data">(.*?)<\\/script>/s', $html, $m);
        $this->assertEquals([], (array) json_decode($m[1], true)['terms'], 'no shared price is invented');

        $this->as($this->maker)->put(route('consignments.buyouts.update', $b->id), $this->payload([], ['revision' => $b->revision]));
        $this->assertSame($b->revision, $b->fresh()->revision, 'rejected until one explicit price is given');
        $this->assertArrayNotHasKey('version', $b->fresh()->pricing_intent);

        $this->as($this->maker)->put(route('consignments.buyouts.update', $b->id), $this->payload(['unit_price' => '115'], ['revision' => $b->revision]))->assertRedirect();
        $this->assertSame(2, $b->fresh()->pricing_intent['version']);
    }

    /** @test */
    public function inactive_payment_terms_are_rejected(): void
    {
        $term = PaymentTerm::create(['name' => 'OLD', 'longevity' => 60, 'is_active' => false]);
        $this->as($this->maker)->post(route('consignments.buyouts.store'), $this->payload(['unit_price' => '110'], [
            'invoice_metadata' => ['payment_term_id' => $term->id]]))->assertSessionHasErrors('invoice_metadata.payment_term_id');
        $this->assertSame(0, ConsignmentBuyout::count());
    }

    /** @test */
    public function reopened_legacy_draft_with_conflicting_saved_overrides_requires_an_explicit_decision(): void
    {
        // Receipts agree at 100 but the saved per-line terms were 110 and 120.
        DB::table('consignment_receiving_details')->update(['unit_dpp' => 100, 'unit_cost' => 100]);
        $life = new ConsignmentBuyoutLifecycleService();
        $lines = array_values(array_map(fn ($l) => array_diff_key($l, ['row_key' => 1]) + ['destination_location_id' => $this->dest1->id], $this->wizardLines));
        $lines[1]['buckets'] = ['NON_TAXED' => '3'];
        $b = $life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', $lines, $this->maker, ['invoice_metadata' => ['invoice_date' => '2026-11-10']]);
        [$l1, $l2] = $b->lines()->orderBy('id')->pluck('id')->all();
        $b->update(['pricing_intent' => ['rows' => [$l1 => ['unit_price' => 110], $l2 => ['unit_price' => 120]]]]);

        $html = $this->as($this->maker)->get(route('consignments.buyouts.edit', $b->id))->assertOk()->getContent();
        preg_match('/<script type="application\/json" id="buyout-prep-data">(.*?)<\/script>/s', $html, $m);
        $this->assertSame([$this->product->id], json_decode($m[1], true)['conflicts']);

        $this->as($this->maker)->put(route('consignments.buyouts.update', $b->id), $this->payload([], ['revision' => $b->revision]));
        $this->assertSame($b->revision, $b->fresh()->revision, 'receipt default does not replace conflicting saved terms');

        $this->as($this->maker)->put(route('consignments.buyouts.update', $b->id), $this->payload(['unit_price' => '115'], ['revision' => $b->revision]))->assertRedirect();
        $this->assertSame($b->revision + 1, $b->fresh()->revision);
    }

    /** @test */
    public function reporting_date_cannot_be_set_during_preparation(): void
    {
        $html = $this->as($this->maker)->get(route('consignments.buyouts.create', ['supplier_id' => $this->supplier->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('invoice_metadata[reporting_date]', $html);
        $this->assertStringContainsString('window.consignmentNominal', $html, 'shared nominal formatter is loaded');
        $this->assertStringContainsString('inputmode="decimal"', $html);

        $this->as($this->maker)->post(route('consignments.buyouts.store'), $this->payload(['unit_price' => '110'], [
            'invoice_metadata' => ['reporting_date' => '2026-12-01']]))->assertSessionHasErrors('invoice_metadata.reporting_date');
        $this->assertSame(0, ConsignmentBuyout::count());
    }

    /** @test */
    public function legacy_approved_document_keeps_its_approved_reporting_date(): void
    {
        $life = new ConsignmentBuyoutLifecycleService();
        $lines = array_values(array_map(fn ($l) => array_diff_key($l, ['row_key' => 1]) + ['destination_location_id' => $this->dest1->id], $this->wizardLines));
        $lines[1]['buckets'] = ['NON_TAXED' => '3'];
        $b = $life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', $lines, $this->maker,
            ['invoice_metadata' => ['invoice_date' => '2026-11-10', 'reporting_date' => '2026-11-15']]);
        $b = $life->approve($life->submit($b, $this->maker), $this->maker);
        $purchase = (new \Modules\Consignment\Services\ConsignmentBuyoutFinalizationService())->finalize($b, $this->maker);
        $this->assertSame('2026-11-15', $purchase->reporting_date->toDateString());
    }
}
