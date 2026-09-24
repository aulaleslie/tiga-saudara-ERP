<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;
use Modules\Adjustment\Entities\TransferMovementAllocation;
use Modules\Adjustment\Tests\Support\BuildsV3TransferFixtures;
use Modules\Setting\Entities\Location;
use Tests\TestCase;

/**
 * Tasks 1.1, 1.4, 2.1-2.4, 5.4, 6.1, 6.3, 7.1, 7.2: active-business permission
 * matrix, global discovery, sentinel-based projection checks, legacy route
 * guards, receipt/cancellation endpoints and the permission-gated timeline.
 */
class TransferV3HttpBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsV3TransferFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);
        Model::preventLazyLoading(true);
        $this->buildV3Fixtures();

        // Distinctive route names that must never leak to non-approvers.
        $this->a1->update(['name' => 'SENTINEL-SOURCE-LOC']);
        $this->b1->update(['name' => 'SENTINEL-DEST-LOC']);
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $this->grant($user, $permissions);

        return $user;
    }

    private function dispatched(): Transfer
    {
        $transfer = $this->submittedTransfer(10, false);
        $this->saveCompletePlan($transfer, null, []);

        return $this->dispatchTransfer($transfer)->fresh();
    }

    /** @test */
    public function detail_shows_goods_without_routes_to_stock_visibility_holders_without_approval(): void
    {
        $transfer = $this->dispatched();
        $viewer = $this->userWith(['stockTransfers.show', 'stockTransfers.view-system-stock', 'stockTransfers.receive']);

        $response = $this->actingAs($viewer)->get(route('transfers.show', $transfer))->assertOk();

        $response->assertSee($this->bulk->fresh()->product_name)
            ->assertSee('Terima Barang')
            ->assertSee('Pastikan seluruh barang telah dihitung dan jumlahnya sesuai dengan daftar pada dokumen ini. Dengan mengonfirmasi, Anda menyatakan seluruh barang telah diterima lengkap.')
            ->assertSee('Konfirmasi Penerimaan')
            ->assertDontSee('SENTINEL-SOURCE-LOC')
            ->assertDontSee('SENTINEL-DEST-LOC')
            ->assertDontSee('Riwayat Transfer')
            ->assertDontSee('Batalkan Pengiriman')
            ->assertDontSee('Arsipkan');
    }

    /** @test */
    public function approvers_see_executed_routes_and_history_needs_its_own_permission(): void
    {
        $transfer = $this->dispatched();

        $approver = $this->userWith(['stockTransfers.show', 'stockTransfers.approval']);
        $this->actingAs($approver)->get(route('transfers.show', $transfer))
            ->assertOk()
            ->assertSee('SENTINEL-SOURCE-LOC')
            ->assertSee('SENTINEL-DEST-LOC')
            ->assertDontSee('Riwayat Transfer');

        $historian = $this->userWith(['stockTransfers.show', 'stockTransfers.view-history']);
        $this->actingAs($historian)->get(route('transfers.show', $transfer))
            ->assertOk()
            ->assertSee('Riwayat Transfer')
            ->assertSee('Disetujui')
            ->assertSee('Dikirim')
            ->assertDontSee('SENTINEL-SOURCE-LOC')
            ->assertDontSee('SENTINEL-DEST-LOC')
            ->assertDontSee('Revisi alokasi');
    }

    /** @test */
    public function approval_workspace_requires_approval_and_shows_totals_without_bucket_diagnostics(): void
    {
        $transfer = $this->submittedTransfer(10, false);

        $this->actingAs($this->userWith(['stockTransfers.show', 'stockTransfers.view-system-stock']))
            ->get(route('transfers.v3.approval', $transfer))->assertForbidden();

        $approver = $this->userWith(['stockTransfers.approval']);
        $html = $this->actingAs($approver)->get(route('transfers.v3.approval', $transfer))
            ->assertOk()
            ->assertSee('SENTINEL-SOURCE-LOC')
            ->assertSee('Simpan Progres')
            ->assertSee('Setujui dan Kirim')
            ->getContent();
        $map = $this->stockMap($html);
        $this->assertSame(['available' => 10], $map[(string) $this->a1->id]);
        $this->assertSame(['available' => 5], $map[(string) $this->a2->id]);

        $diagnostics = $this->userWith(['stockTransfers.approval', 'stockTransfers.view-system-stock']);
        $html = $this->actingAs($diagnostics)->get(route('transfers.v3.approval', $transfer))->assertOk()->getContent();
        $this->assertSame(['available' => 10, 'tax' => 4, 'non_tax' => 6], $this->stockMap($html)[(string) $this->a1->id]);
    }

    /** Stock data the page hands to JavaScript (the only client-side stock payload). */
    private function stockMap(string $html): array
    {
        $this->assertSame(1, preg_match_all('/<script type="application\/json" class="v3-stock-map">(.*?)<\/script>/s', $html, $matches));

        return json_decode($matches[1][0], true);
    }

    /** @test */
    public function approval_workspace_constrains_tables_and_keeps_stock_out_of_option_labels(): void
    {
        $this->a1->update(['name' => str_repeat('Gudang Sangat Panjang Sekali ', 6) . 'SENTINEL-SOURCE-LOC']);
        $transfer = $this->submittedTransfer(10, true);

        $html = $this->actingAs($this->userWith(['stockTransfers.approval']))
            ->get(route('transfers.v3.approval', $transfer))
            ->assertOk()
            ->assertSee('class="v3-table-scroll', false)
            ->assertSee('v3-alloc-table v3-bulk-table', false)
            ->assertSee('v3-alloc-table v3-serial-table', false)
            ->assertSee('<col class="v3-col-quantity">', false)
            ->assertSee('<col class="v3-col-remove">', false)
            ->assertSee('class="form-text text-muted v3-stock-info"', false)
            ->assertSee('table-layout: fixed', false)
            ->assertSee('id="v3-approval-root"', false)
            ->assertSee('V3TransferApproval', false)
            ->assertSee('+ Tambah Sumber')
            ->getContent();

        // Option labels carry only the location label, never stock figures.
        preg_match_all('/<option value="\d+"[^>]*>([^<]*)<\/option>/', $html, $options);
        $this->assertNotEmpty($options[1]);
        foreach ($options[1] as $label) {
            $this->assertStringNotContainsString('tersedia', $label);
            $this->assertStringNotContainsString('pajak', $label);
        }

        // Styling is scoped to the v3 approval page.
        preg_match('/<style>(.*?)<\/style>/s', $html, $style);
        foreach (preg_split('/}\s*/', trim(preg_replace('#/\*.*?\*/#s', '', $style[1] ?? ''))) as $rule) {
            if (trim($rule) === '') {
                continue;
            }
            $selectors = explode(',', strstr($rule, '{', true));
            foreach ($selectors as $selector) {
                $this->assertMatchesRegularExpression('/^\s*(\.v3-approval|#v3-approval-root)\b/', $selector, "unscoped selector: {$selector}");
            }
        }
    }

    /** @test */
    public function review_page_renders_a_ready_gated_summary_with_an_accessible_fallback_and_does_not_approve(): void
    {
        $transfer = $this->submittedTransfer(10, false);
        $approver = $this->userWith(['stockTransfers.approval', 'stockTransfers.show']);
        $this->actingAs($approver)->post(route('transfers.v3.approval.progress', $transfer), [
            'request_revision_id' => $transfer->current_request_revision_id,
            'configuration_revision' => 0,
            'rows' => [
                ['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->b1->id, 'quantity' => 10],
            ],
            'intent' => 'review',
        ])->assertRedirect(route('transfers.v3.approval', ['transfer' => $transfer->id, 'review' => 1]));

        // Saving for review never approves or dispatches.
        $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);
        $this->assertSame(0, $transfer->fresh()->movements()->count());

        $html = $this->actingAs($approver)->get(route('transfers.v3.approval', ['transfer' => $transfer->id, 'review' => 1]))
            ->assertOk()
            ->assertSee('id="v3-summary-modal"', false)
            ->assertSee('id="v3-summary-fallback"', false)
            ->assertSee('Jendela ringkasan tidak dapat dibuka')
            ->assertSee('lalu klik <strong>Konfirmasi Setujui dan Kirim</strong>', false)
            ->assertDontSee('Alokasi di bawah ini belum dapat disetujui')
            ->assertSee('Lihat Ringkasan Persetujuan')
            ->assertSee('#v3-summary-fallback[hidden] { display: block !important; }', false)
            ->assertSee('Approval.whenScriptsReady(document, openSummary)', false)
            ->assertDontSee("jQuery('#v3-summary-modal').modal('show')", false)
            ->assertSee('data-toggle="modal" data-target="#v3-reject-modal"', false)
            ->assertSee('data-dismiss="modal"', false)
            ->getContent();

        // The fallback starts hidden; it is only revealed by script (or noscript).
        $this->assertMatchesRegularExpression('/<section id="v3-summary-fallback"[^>]*\bhidden>/', $html);

        // Modal and fallback carry the same revision-bound confirmation with one operation key.
        preg_match_all('/<form action="([^"]+)" method="POST" class="d-inline">\s*<input type="hidden" name="_token"[^>]*>\s*'
            . '<input type="hidden" name="request_revision_id" value="(\d+)">\s*<input type="hidden" name="configuration_revision" value="(\d+)">\s*'
            . '<input type="hidden" name="operation_key" value="([^"]+)">/', $html, $forms, PREG_SET_ORDER);
        $this->assertCount(2, $forms);
        $this->assertSame(route('transfers.v3.approve', $transfer), html_entity_decode($forms[0][1]));
        $this->assertSame(array_slice($forms[0], 1), array_slice($forms[1], 1));
        $this->assertSame((string) $transfer->fresh()->current_request_revision_id, $forms[0][2]);
        $this->assertSame('1', $forms[0][3]);

        // Rendering the review page twice still has not approved anything.
        $this->actingAs($approver)->get(route('transfers.v3.approval', ['transfer' => $transfer->id, 'review' => 1]))->assertOk();
        $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);
    }

    /** @test */
    public function invalid_summary_offers_no_confirmation_in_the_modal_or_the_fallback(): void
    {
        $transfer = $this->submittedTransfer(10, false);
        $approver = $this->userWith(['stockTransfers.approval', 'stockTransfers.show']);
        // Incomplete: 6 of 10 allocated.
        $this->actingAs($approver)->post(route('transfers.v3.approval.progress', $transfer), [
            'request_revision_id' => $transfer->current_request_revision_id,
            'configuration_revision' => 0,
            'rows' => [
                ['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->b1->id, 'quantity' => 6],
            ],
            'intent' => 'review',
        ])->assertRedirect(route('transfers.v3.approval', ['transfer' => $transfer->id, 'review' => 1]));

        $html = $this->actingAs($approver)->get(route('transfers.v3.approval', ['transfer' => $transfer->id, 'review' => 1]))
            ->assertOk()
            ->assertSee('id="v3-summary-modal"', false)
            ->assertSee('id="v3-summary-fallback"', false)
            ->assertSee('Alokasi belum dapat disetujui')
            ->assertSee('Alokasi di bawah ini belum dapat disetujui')
            ->assertDontSee('Konfirmasi Setujui dan Kirim')
            ->assertDontSee(route('transfers.v3.approve', $transfer), false)
            ->getContent();

        $this->assertSame(0, substr_count($html, 'name="operation_key"'));
        $this->assertSame(2, substr_count($html, 'Alokasi belum dapat disetujui:'), 'errors shown in both modal and fallback');
        $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);
    }

    /** @test */
    public function workspace_without_review_has_no_summary_or_fallback(): void
    {
        $transfer = $this->submittedTransfer(10, false);
        $this->actingAs($this->userWith(['stockTransfers.approval']))
            ->get(route('transfers.v3.approval', $transfer))
            ->assertOk()
            ->assertDontSee('id="v3-summary-modal"', false)
            ->assertDontSee('id="v3-summary-fallback"', false)
            ->assertDontSee('Lihat Ringkasan Persetujuan')
            ->assertSee('data-target="#v3-reject-modal"', false);
    }

    /** @test */
    public function saved_source_shows_stock_below_the_selector_with_tax_split_only_for_stock_viewers(): void
    {
        $transfer = $this->submittedTransfer(10, false);
        $approver = $this->userWith(['stockTransfers.approval', 'stockTransfers.show']);
        $this->actingAs($approver)->post(route('transfers.v3.approval.progress', $transfer), [
            'request_revision_id' => $transfer->current_request_revision_id,
            'configuration_revision' => 0,
            'rows' => [
                ['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->b1->id, 'quantity' => 6],
                ['product_id' => $this->bulk->id, 'source_location_id' => $this->a2->id, 'destination_location_id' => $this->a1->id, 'quantity' => 4],
            ],
            'intent' => 'save',
        ])->assertRedirect();

        $this->actingAs($approver)->get(route('transfers.v3.approval', $transfer))
            ->assertOk()
            ->assertSee('Stok tersedia: 10')
            ->assertSee('Stok tersedia: 5')
            ->assertDontSee('Stok tersedia: 10 (pajak')
            ->assertSee('name="rows[1][quantity]" value="4"', false);
        $html = $this->actingAs($approver)->get(route('transfers.v3.approval', $transfer))->getContent();
        foreach ($this->stockMap($html) as $entry) {
            $this->assertSame(['available'], array_keys($entry));
        }

        $viewer = $this->userWith(['stockTransfers.approval', 'stockTransfers.show', 'stockTransfers.view-system-stock']);
        $this->actingAs($viewer)->get(route('transfers.v3.approval', $transfer))
            ->assertOk()
            ->assertSee('Stok tersedia: 10 (pajak 4, non-pajak 6)')
            ->assertSee('Stok tersedia: 5 (pajak 0, non-pajak 5)');
    }

    /** @test */
    public function approver_without_route_business_membership_saves_reviews_and_approves_by_http(): void
    {
        $transfer = $this->submittedTransfer(10, false);
        $approver = $this->userWith(['stockTransfers.approval', 'stockTransfers.show']);
        session(['setting_id' => $this->businessB->id]);

        $this->actingAs($approver)->post(route('transfers.v3.approval.progress', $transfer), [
            'request_revision_id' => $transfer->current_request_revision_id,
            'configuration_revision' => 0,
            'rows' => [
                ['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->b1->id, 'quantity' => 6],
                ['product_id' => $this->bulk->id, 'source_location_id' => $this->a2->id, 'destination_location_id' => $this->a1->id, 'quantity' => 4],
            ],
            'intent' => 'save',
        ])->assertRedirect(route('transfers.show', $transfer));

        $this->actingAs($approver)->post(route('transfers.v3.approval.progress', $transfer), [
            'request_revision_id' => $transfer->current_request_revision_id,
            'configuration_revision' => 1,
            'rows' => [
                ['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->b1->id, 'quantity' => 6],
                ['product_id' => $this->bulk->id, 'source_location_id' => $this->a2->id, 'destination_location_id' => $this->a1->id, 'quantity' => 4],
            ],
            'intent' => 'review',
        ])->assertRedirect(route('transfers.v3.approval', ['transfer' => $transfer->id, 'review' => 1]));

        $this->actingAs($approver)->get(route('transfers.v3.approval', ['transfer' => $transfer->id, 'review' => 1]))
            ->assertOk()
            ->assertSee('Ringkasan Persetujuan')
            ->assertSee('Lintas Bisnis')
            ->assertSee('Konfirmasi Setujui dan Kirim');

        // A stale modal (configuration 1) cannot dispatch the current plan (2).
        $this->actingAs($approver)->post(route('transfers.v3.approve', $transfer), [
            'request_revision_id' => $transfer->current_request_revision_id,
            'configuration_revision' => 1,
        ])->assertRedirect(route('transfers.v3.approval', $transfer));
        $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);

        $this->actingAs($approver)->post(route('transfers.v3.approve', $transfer), [
            'request_revision_id' => $transfer->current_request_revision_id,
            'configuration_revision' => 2,
            'operation_key' => 'http-approve-1',
        ])->assertRedirect(route('transfers.show', $transfer));

        $this->assertSame(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);
        $event = TransferActionHistory::where('transfer_id', $transfer->id)->where('action', 'APPROVED')->first();
        $this->assertSame($this->businessB->id, $event->metadata['active_setting_id']);
    }

    /** @test */
    public function receipt_needs_receive_only_and_rejects_overrides(): void
    {
        $transfer = $this->dispatched();

        $this->actingAs($this->userWith(['stockTransfers.show']))
            ->post(route('transfers.v3.receive', $transfer), ['confirm' => 1])->assertForbidden();

        $receiver = $this->userWith(['stockTransfers.receive']);

        $this->actingAs($receiver)->post(route('transfers.v3.receive', $transfer), [
            'confirm' => 1,
            'quantities' => [$this->bulk->id => 99],
            'destination_location_id' => $this->a2->id,
        ])->assertStatus(422);
        $this->assertSame(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);

        $this->actingAs($receiver)->post(route('transfers.v3.receive', $transfer), [])
            ->assertSessionHasErrors('confirm');

        $this->actingAs($receiver)->post(route('transfers.v3.receive', $transfer), ['confirm' => 1, 'operation_key' => 'rcv-1'])
            ->assertRedirect(route('transfers.show', $transfer));
        $this->actingAs($receiver)->post(route('transfers.v3.receive', $transfer), ['confirm' => 1, 'operation_key' => 'rcv-1'])
            ->assertRedirect(route('transfers.show', $transfer));

        $this->assertSame(Transfer::STATUS_COMPLETED, $transfer->fresh()->status);
        $this->assertSame(2, TransferMovementAllocation::where('kind', 'RECEIPT')->count());
        $this->assertSame(6, (int) $this->stockAt($this->bulk, $this->b1)->quantity);
    }

    /** @test */
    public function cancellation_needs_dedicated_permission_reason_and_dispatched_state(): void
    {
        $transfer = $this->dispatched();

        $this->actingAs($this->userWith(['stockTransfers.receive', 'stockTransfers.approval']))
            ->post(route('transfers.v3.cancel-dispatch', $transfer), ['confirm' => 1, 'reason' => 'x'])->assertForbidden();

        $canceller = $this->userWith(['stockTransfers.cancel-dispatch']);

        $this->actingAs($canceller)->post(route('transfers.v3.cancel-dispatch', $transfer), ['confirm' => 1, 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($canceller)->post(route('transfers.v3.cancel-dispatch', $transfer), ['reason' => 'Batal'])
            ->assertSessionHasErrors('confirm');
        $this->assertSame(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);

        $this->actingAs($canceller)->post(route('transfers.v3.cancel-dispatch', $transfer), ['confirm' => 1, 'reason' => 'Truk rusak'])
            ->assertRedirect(route('transfers.show', $transfer));
        $this->assertSame(Transfer::STATUS_CANCELLED, $transfer->fresh()->status);

        // Canceller lacks view-history: event recorded, timeline not shown.
        $this->assertTrue(TransferActionHistory::where('transfer_id', $transfer->id)->where('action', 'CANCELLED')->exists());

        $this->actingAs($this->userWith(['stockTransfers.receive']))
            ->post(route('transfers.v3.receive', $transfer), ['confirm' => 1]);
        $this->assertSame(Transfer::STATUS_CANCELLED, $transfer->fresh()->status);
    }

    /** @test */
    public function legacy_endpoints_reject_v3_documents_without_effects(): void
    {
        $transfer = $this->dispatched();
        $admin = $this->userWith(self::PERMISSIONS);

        foreach ([
            route('transfers.receive', $transfer),
            route('transfers.dispatch', $transfer),
            route('transfers.approve', $transfer),
            route('transfers.archive', $transfer),
            route('transfers.return-dispatch', $transfer),
            route('transfers.movements.return.create', $transfer),
        ] as $url) {
            $this->actingAs($admin)->post($url, ['reason' => 'x'])->assertNotFound();
        }

        $this->actingAs($admin)->get(route('transfers.movements.receipt.prepare', $transfer))->assertNotFound();
        $this->assertSame(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);
        $this->assertSame(0, (int) $this->stockAt($this->bulk, $this->b1)?->quantity);
    }

    /** @test */
    public function v3_actions_reject_legacy_documents(): void
    {
        $legacy = Transfer::create([
            'origin_location_id' => $this->a1->id,
            'destination_location_id' => $this->a2->id,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'created_by' => $this->actor->id,
            'status' => Transfer::STATUS_DISPATCHED,
            'workflow_version' => 2,
        ]);
        $admin = $this->userWith(self::PERMISSIONS);

        $this->actingAs($admin)->post(route('transfers.v3.receive', $legacy), ['confirm' => 1])->assertNotFound();
        $this->actingAs($admin)->post(route('transfers.v3.cancel-dispatch', $legacy), ['confirm' => 1, 'reason' => 'x'])->assertNotFound();
        $this->assertSame(Transfer::STATUS_DISPATCHED, $legacy->fresh()->status);
    }

    /** @test */
    public function list_discovers_all_v3_documents_and_keeps_legacy_scope(): void
    {
        $v3 = $this->submittedTransfer(1, false);
        $foreignLegacy = Transfer::create([
            'origin_location_id' => $this->b1->id,
            'destination_location_id' => Location::create(['setting_id' => $this->businessB->id, 'name' => 'B2'])->id,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'created_by' => $this->actor->id,
            'status' => Transfer::STATUS_PENDING,
            'workflow_version' => 2,
        ]);

        $viewer = $this->userWith(['stockTransfers.access', 'stockTransfers.show', 'stockTransfers.approval', 'stockTransfers.edit']);
        $response = $this->actingAs($viewer)->getJson(route('transfers.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        $numbers = collect($response->json('data'))->pluck('document_number');
        $this->assertContains($v3->document_number, $numbers);
        $this->assertNotContains($foreignLegacy->document_number, $numbers, 'legacy discovery unchanged');

        $row = collect($response->json('data'))->firstWhere('document_number', $v3->document_number);
        $this->assertStringContainsString(route('transfers.v3.approval', $v3->id), $row['action']);
        $this->assertSame('-', $row['origin_location_name']);
        $this->assertStringNotContainsString('SENTINEL', json_encode($response->json()));

        $this->actingAs($this->userWith(['stockTransfers.show']))->get(route('transfers.index'))->assertForbidden();
    }

    /** @test */
    public function list_submission_validates_and_redirects_to_detail(): void
    {
        $draft = app(\Modules\Adjustment\Services\TransferV3GoodsService::class)->createDraft(
            Transfer::CONDITION_GOOD, $this->goodsLines(), $this->actor, $this->businessA->id
        );
        $editor = $this->userWith(['stockTransfers.edit']);

        $this->actingAs($this->userWith(['stockTransfers.show']))->post(route('transfers.v3.submit', $draft))->assertForbidden();

        $this->actingAs($editor)->post(route('transfers.v3.submit', $draft))->assertRedirect(route('transfers.show', $draft));
        $this->assertSame(Transfer::STATUS_PENDING, $draft->fresh()->status);

        // Crafted direct detail access still needs explicit show.
        $this->actingAs($editor)->get(route('transfers.show', $draft))->assertForbidden();
    }

    /** @test */
    public function creation_uses_v3_form_only_when_activated(): void
    {
        $creator = $this->userWith(['stockTransfers.create']);

        $this->actingAs($creator)->get(route('transfers.create'))
            ->assertOk()
            ->assertSee('Buat Transfer Stok')
            ->assertSee('Barang Baik')
            ->assertSee('Barang Rusak')
            ->assertDontSee('Lokasi Asal')
            ->assertDontSee('Lokasi Tujuan');

        config(['stock_transfers.v3_creation_enabled' => false]);
        $this->actingAs($creator)->get(route('transfers.create'))->assertOk()->assertSee('Lokasi Asal');
    }
}
