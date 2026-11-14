<?php

namespace Tests\Feature\Adjustment;

use App\Livewire\Adjustment\AdjustmentProductTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\CountDraftService;
use Modules\Adjustment\Services\StockOpnameApprovalService;
use Modules\Adjustment\Services\StockOpnameLifecycleService;
use Modules\Adjustment\Services\StockOpnameReconciliationService;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * fix-stock-opname-zero-confirmation: explicit physical-zero intent in
 * drafts, submission/approval safeguards, and serialized unmatched-quantity
 * reconciliation.
 */
class StockOpnameZeroConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Setting $setting;
    private Location $perdana;
    private Location $whiteKnight;
    private CountDraftService $drafts;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'Rp',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Toko Uji', 'company_email' => 'uji@example.test',
            'company_phone' => '0800', 'notification_email' => 'uji@example.test',
            'footer_text' => 'Footer', 'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);

        $this->perdana = Location::create(['name' => 'Perdana', 'setting_id' => $this->setting->id, 'is_active' => true, 'is_consignment' => false]);
        $this->whiteKnight = Location::create(['name' => 'White Knight', 'setting_id' => $this->setting->id, 'is_active' => true, 'is_consignment' => false]);

        Tax::create(['name' => 'PPN', 'value' => 11, 'is_default' => true]);

        foreach (['adjustments.edit', 'adjustments.approval', 'adjustments.view-system-stock', 'adjustments.create', 'adjustments.access'] as $perm) {
            Permission::findOrCreate($perm, 'web');
        }

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo(['adjustments.edit', 'adjustments.approval', 'adjustments.view-system-stock', 'adjustments.create', 'adjustments.access']);
        $this->otherUser = User::factory()->create(['is_active' => 1]);
        $this->otherUser->givePermissionTo(['adjustments.edit', 'adjustments.create', 'adjustments.access']);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->drafts = app(CountDraftService::class);
    }

    // ---------------------------------------------------------------- helpers

    private function makeProduct(bool $serialized, string $code = 'P1'): Product
    {
        return Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Produk ' . $code, 'product_code' => $code, 'barcode' => 'BC' . $code,
            'product_cost' => 1000, 'product_price' => 2000, 'product_quantity' => 0,
            'product_stock_alert' => 0, 'stock_managed' => true, 'is_active' => true,
            'serial_number_required' => $serialized,
        ]);
    }

    private function stock(Product $product, Location $location, int $good = 0, int $bad = 0): ProductStock
    {
        $stock = ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => $good + $bad, 'quantity_tax' => 0, 'quantity_non_tax' => $good,
            'broken_quantity' => $bad, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => $bad,
        ]);
        $product->update(['product_quantity' => (int) ProductStock::where('product_id', $product->id)->sum('quantity')]);

        return $stock;
    }

    private function serial(Product $product, Location $location, string $text, string $status = ProductSerialNumber::STATUS_ACTIVE): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => $text, 'status' => $status, 'is_broken' => false, 'tax_id' => null,
        ]);
    }

    private function scope(Product $product, array $locations): string
    {
        return CountDraftService::zeroConfirmationScope($product->id, array_map(fn (Location $l) => (int) $l->id, $locations), 0, 0);
    }

    private function save(array $rows, array $locations, ?Adjustment $existing = null, string $note = 'catatan'): Adjustment
    {
        $validated = $this->drafts->validateDraftInput([
            'reference' => 'SO-' . uniqid(),
            'date' => now()->toDateString(),
            'note' => $note,
            'location_ids' => array_map(fn (Location $l) => (int) $l->id, $locations),
            'count_draft' => json_encode(['rows' => $rows]),
        ], $existing);

        return $this->drafts->saveDraft($validated, $existing)->fresh();
    }

    private function confirmedRow(Product $product, array $locations, array $extra = []): array
    {
        return array_merge([
            'product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [],
            'zero_confirmed' => true, 'zero_confirmation_scope' => $this->scope($product, $locations),
        ], $extra);
    }

    private function submitAndApprove(Adjustment $adjustment): Adjustment
    {
        app(StockOpnameLifecycleService::class)->submit($adjustment, $this->user, $this->setting->id);

        return app(StockOpnameApprovalService::class)->approve($adjustment->fresh(), $this->user, $this->setting->id)->fresh();
    }

    private function locationQty(Product $product, Location $location): int
    {
        return (int) round((float) (ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->value('quantity') ?? 0));
    }

    // ------------------------------------------------- 1.1 / 1.3 draft persistence

    public function test_unconfirmed_zero_draft_is_saveable_without_confirmation(): void
    {
        $product = $this->makeProduct(true);
        $this->stock($product, $this->perdana, 1);

        $adj = $this->save([['product_id' => $product->id, 'serials' => []]], [$this->perdana]);
        $row = $adj->count_draft['rows'][0];

        $this->assertFalse($row['zero_confirmed']);
        $this->assertNull($row['zero_confirmed_by']);
        $this->assertNull($row['zero_confirmed_at']);
        $this->assertSame(1, $this->locationQty($product, $this->perdana));
    }

    public function test_confirmation_is_attributed_server_side_and_forged_attribution_ignored(): void
    {
        $product = $this->makeProduct(false);
        $this->stock($product, $this->perdana, 3);

        $adj = $this->save([$this->confirmedRow($product, [$this->perdana], [
            'zero_confirmed_by' => $this->otherUser->id,
            'zero_confirmed_at' => '2000-01-01T00:00:00+00:00',
        ])], [$this->perdana]);
        $row = $adj->count_draft['rows'][0];

        $this->assertTrue($row['zero_confirmed']);
        $this->assertSame($this->user->id, $row['zero_confirmed_by']);
        $this->assertNotSame('2000-01-01T00:00:00+00:00', $row['zero_confirmed_at']);
        $this->assertTrue(CountDraftService::hasValidZeroConfirmation($row, [$this->perdana->id]));
    }

    public function test_unchanged_resave_by_another_editor_preserves_original_attribution(): void
    {
        $product = $this->makeProduct(false);
        $this->stock($product, $this->perdana, 3);

        $adj = $this->save([$this->confirmedRow($product, [$this->perdana])], [$this->perdana]);
        $original = $adj->count_draft['rows'][0];

        $this->travel(5)->minutes();
        $this->actingAs($this->otherUser);
        $resaved = $this->save([$this->confirmedRow($product, [$this->perdana])], [$this->perdana], $adj, 'catatan diubah');
        $row = $resaved->count_draft['rows'][0];

        $this->assertSame($this->user->id, $row['zero_confirmed_by']);
        $this->assertSame($original['zero_confirmed_at'], $row['zero_confirmed_at']);
    }

    public function test_stale_confirmation_is_not_carried_into_changed_scope_or_count(): void
    {
        $product = $this->makeProduct(false);
        $this->stock($product, $this->perdana, 3);

        $adj = $this->save([$this->confirmedRow($product, [$this->perdana])], [$this->perdana]);

        // Direct request: selected locations changed but old confirmation scope forwarded.
        $changedScope = $this->save([$this->confirmedRow($product, [$this->perdana])], [$this->perdana, $this->whiteKnight], $adj);
        $this->assertFalse($changedScope->count_draft['rows'][0]['zero_confirmed']);

        // Direct request: count changed while claiming confirmation.
        $changedCount = $this->save([$this->confirmedRow($product, [$this->perdana], ['good_count' => 2])], [$this->perdana], $adj);
        $this->assertFalse($changedCount->count_draft['rows'][0]['zero_confirmed']);
    }

    public function test_legacy_rows_without_properties_are_unconfirmed(): void
    {
        $this->assertFalse(CountDraftService::hasValidZeroConfirmation(
            ['product_id' => 1, 'good_count' => 0, 'bad_count' => 0, 'serials' => []],
            [$this->perdana->id]
        ));
    }

    // ------------------------------------------------------------- 1.2 Livewire

    public function test_livewire_checkbox_confirms_and_count_changes_clear_confirmation(): void
    {
        $product = $this->makeProduct(false);
        $this->stock($product, $this->perdana, 4);

        $component = Livewire::test(AdjustmentProductTable::class, ['locationIds' => [$this->perdana->id]]);
        $component->call('productSelected', [
            'id' => $product->id, 'product_name' => $product->product_name, 'product_code' => $product->product_code,
            'serial_number_required' => false, 'unit' => '',
        ]);

        $this->assertFalse($component->get('products')[0]['zero_confirmed']);
        $component->assertSee('Saya sudah menghitung: stok fisik kosong');

        $component->call('toggleZeroConfirmation', 0, true);
        $this->assertTrue($component->get('products')[0]['zero_confirmed']);
        $payload = json_decode($component->get('countDraftPayload'), true);
        $this->assertTrue($payload['rows'][0]['zero_confirmed']);
        $this->assertSame($this->scope($product, [$this->perdana]), $payload['rows'][0]['zero_confirmation_scope']);

        // Change then return to zero: confirmation must be given again.
        $component->set('products.0.good_count', 2);
        $this->assertFalse($component->get('products')[0]['zero_confirmed']);
        $component->set('products.0.good_count', 0);
        $this->assertFalse($component->get('products')[0]['zero_confirmed']);

        // Confirmation fields are not client-writable.
        $component->set('products.0.zero_confirmed', true);
        $this->assertFalse($component->get('products')[0]['zero_confirmed']);
    }

    public function test_livewire_checkbox_available_to_counter_without_stock_visibility(): void
    {
        $product = $this->makeProduct(false);
        $this->stock($product, $this->perdana, 4);
        $this->actingAs($this->otherUser);

        $component = Livewire::test(AdjustmentProductTable::class, ['locationIds' => [$this->perdana->id]]);
        $component->call('productSelected', [
            'id' => $product->id, 'product_name' => $product->product_name, 'product_code' => $product->product_code,
            'serial_number_required' => false, 'unit' => '',
        ]);

        $component->assertSee('Saya sudah menghitung: stok fisik kosong');
        $component->assertDontSee('Stok Sistem');
    }

    // ----------------------------------------------------------- 2.1 submission

    public function test_submission_rejects_unconfirmed_zero_on_positive_stock_without_side_effects(): void
    {
        $product = $this->makeProduct(false);
        $this->stock($product, $this->perdana, 5);
        $adj = $this->save([['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0]], [$this->perdana]);

        try {
            app(StockOpnameLifecycleService::class)->submit($adj, $this->user, $this->setting->id);
            $this->fail('Submission should have been rejected.');
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('Produk P1', $message);
            $this->assertStringNotContainsString('5', $message);
        }

        $this->assertSame(AdjustmentStatus::Draft, AdjustmentStatus::normalize($adj->fresh()->status));
        $this->assertNull($adj->fresh()->submitted_at);
    }

    public function test_submission_allows_zero_stock_positive_counts_and_confirmed_zero(): void
    {
        $empty = $this->makeProduct(false, 'E1');
        $partial = $this->makeProduct(false, 'R1');
        $confirmed = $this->makeProduct(true, 'C1');
        $this->stock($partial, $this->perdana, 5);
        $this->stock($confirmed, $this->perdana, 1);

        $adj = $this->save([
            ['product_id' => $empty->id, 'good_count' => 0, 'bad_count' => 0],
            ['product_id' => $partial->id, 'good_count' => 2, 'bad_count' => 0],
            $this->confirmedRow($confirmed, [$this->perdana]),
        ], [$this->perdana]);

        app(StockOpnameLifecycleService::class)->submit($adj, $this->user, $this->setting->id);
        $this->assertSame(AdjustmentStatus::WaitingApproval, AdjustmentStatus::normalize($adj->fresh()->status));
    }

    // ------------------------------------------------------------- 2.2 approval

    public function test_approval_blocks_when_stock_became_positive_after_unconfirmed_submission(): void
    {
        $product = $this->makeProduct(false);
        $adj = $this->save([['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0]], [$this->perdana]);
        app(StockOpnameLifecycleService::class)->submit($adj, $this->user, $this->setting->id);

        $this->stock($product, $this->perdana, 2);

        try {
            app(StockOpnameApprovalService::class)->approve($adj->fresh(), $this->user, $this->setting->id);
            $this->fail('Approval should have been blocked.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Tolak dokumen', collect($e->errors())->flatten()->implode(' '));
        }

        $this->assertSame(AdjustmentStatus::WaitingApproval, AdjustmentStatus::normalize($adj->fresh()->status));
        $this->assertSame(2, $this->locationQty($product, $this->perdana));
    }

    public function test_confirmed_zero_non_serialized_is_applied_and_evidence_is_immutable(): void
    {
        $product = $this->makeProduct(false);
        $this->stock($product, $this->perdana, 3);

        $approved = $this->submitAndApprove($this->save([$this->confirmedRow($product, [$this->perdana])], [$this->perdana]));

        $this->assertSame(0, $this->locationQty($product, $this->perdana));
        $evidence = $approved->approval_result['products'][0]['zero_confirmation'];
        $this->assertTrue($evidence['confirmed']);
        $this->assertSame($this->user->id, $evidence['confirmed_by']);
        $this->assertSame($this->user->name, $evidence['confirmed_by_name']);

        // Repeated approval stays an idempotent no-op.
        $again = app(StockOpnameApprovalService::class)->approve($approved, $this->user, $this->setting->id);
        $this->assertSame($approved->approval_result, $again->fresh()->approval_result);
    }

    // ------------------------------------------- 3.x serialized unmatched quantity

    public function test_unmatched_unit_confirmed_empty_is_removed_and_other_location_untouched(): void
    {
        $product = $this->makeProduct(true);
        $this->stock($product, $this->perdana, 1);              // no serial behind it
        $this->stock($product, $this->whiteKnight, 1);
        $wk = $this->serial($product, $this->whiteKnight, 'SN-WK-1');

        $approved = $this->submitAndApprove($this->save([$this->confirmedRow($product, [$this->perdana])], [$this->perdana]));

        $this->assertSame(0, $this->locationQty($product, $this->perdana));
        $this->assertSame(1, $this->locationQty($product, $this->whiteKnight));
        $this->assertSame(1.0, (float) $product->fresh()->product_quantity);
        $this->assertSame($this->whiteKnight->id, (int) $wk->fresh()->location_id);
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, $wk->fresh()->status);

        $evidence = $approved->approval_result['products'][0]['unmatched_reconciliation'];
        $this->assertSame(1, $evidence['unmatched_before']['good']);
        $this->assertSame(1, $evidence['unmatched_removed']['good']);
        $this->assertSame(-1.0, (float) $approved->approval_result['products'][0]['net_delta']);
    }

    public function test_new_physical_serial_accounts_for_existing_unmatched_unit(): void
    {
        $product = $this->makeProduct(true);
        $this->stock($product, $this->perdana, 1);

        $adj = $this->save([['product_id' => $product->id, 'serials' => [['serial_number' => 'SN-NEW-1', 'condition' => 'good']]]], [$this->perdana]);
        $preview = app(StockOpnameReconciliationService::class)->reconcile($adj)->products[0];
        $approved = $this->submitAndApprove($adj);

        $this->assertSame(1, $this->locationQty($product, $this->perdana));
        $this->assertSame(1.0, (float) $product->fresh()->product_quantity);
        $this->assertDatabaseHas('product_serial_numbers', ['product_id' => $product->id, 'serial_number' => 'SN-NEW-1', 'location_id' => $this->perdana->id]);

        $result = $approved->approval_result['products'][0];
        $this->assertSame(0.0, (float) $result['net_delta']);
        $this->assertTrue($result['serials'][0]['accounts_for_existing_unit']);
        $this->assertSame(1, $result['unmatched_reconciliation']['existing_quantity_accounted_for_by_new_serials']['good']);
        $this->assertSame(1.0, (float) $preview->projectedGlobalTotal);
    }

    public function test_only_true_surplus_of_new_serials_increases_stock(): void
    {
        $product = $this->makeProduct(true);
        $this->stock($product, $this->perdana, 2);

        $serials = array_map(fn ($i) => ['serial_number' => "SN-S-{$i}", 'condition' => 'good'], [1, 2, 3]);
        $adj = $this->save([['product_id' => $product->id, 'serials' => $serials]], [$this->perdana]);
        $preview = app(StockOpnameReconciliationService::class)->reconcile($adj)->products[0];
        $approved = $this->submitAndApprove($adj);

        $this->assertSame(3, $this->locationQty($product, $this->perdana));
        $this->assertSame(3.0, (float) $product->fresh()->product_quantity);
        $this->assertSame(1.0, (float) $approved->approval_result['products'][0]['net_delta']);
        $this->assertSame(3.0, (float) $preview->projectedGlobalTotal);
    }

    public function test_retained_serial_with_unmatched_unit_removes_only_unmatched(): void
    {
        $product = $this->makeProduct(true);
        $this->stock($product, $this->perdana, 2);
        $this->serial($product, $this->perdana, 'SN-KEEP');

        $adj = $this->save([['product_id' => $product->id, 'serials' => [['serial_number' => 'SN-KEEP', 'condition' => 'good']]]], [$this->perdana]);
        $approved = $this->submitAndApprove($adj);

        $this->assertSame(1, $this->locationQty($product, $this->perdana));
        $this->assertSame(-1.0, (float) $approved->approval_result['products'][0]['net_delta']);
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, ProductSerialNumber::where('serial_number', 'SN-KEEP')->value('status'));
    }

    public function test_protected_serial_backed_stock_is_not_treated_as_unmatched(): void
    {
        $product = $this->makeProduct(true);
        $this->stock($product, $this->perdana, 1);
        $held = $this->serial($product, $this->perdana, 'SN-RETURN', ProductSerialNumber::STATUS_RETURN_IN_PROCESS);

        $approved = $this->submitAndApprove($this->save([$this->confirmedRow($product, [$this->perdana])], [$this->perdana]));

        $this->assertSame(1, $this->locationQty($product, $this->perdana));
        $this->assertSame(0, $approved->approval_result['products'][0]['unmatched_reconciliation']['unmatched_removed']['good']);
        $this->assertSame(ProductSerialNumber::STATUS_RETURN_IN_PROCESS, $held->fresh()->status);
    }

    public function test_reviewer_preview_matches_applied_and_counter_view_hides_unmatched(): void
    {
        $product = $this->makeProduct(true);
        $this->stock($product, $this->perdana, 1);
        $adj = $this->save([$this->confirmedRow($product, [$this->perdana])], [$this->perdana]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adj);
        $reviewer = $result->products[0]->toReviewerArray();
        $counter = $result->products[0]->toCounterArray();

        $this->assertSame(1, $reviewer['unmatched_reconciliation']['unmatched_removed']['good']);
        $this->assertSame(0.0, (float) $reviewer['projected_global_total']);
        $this->assertTrue($reviewer['zero_confirmation']['confirmed']);
        $this->assertArrayNotHasKey('unmatched_reconciliation', $counter);
        $this->assertTrue($counter['zero_confirmation']['confirmed']);

        $approved = $this->submitAndApprove($adj);
        $this->assertSame((float) $reviewer['projected_global_total'], (float) $product->fresh()->product_quantity);

        // Later stock movement does not rewrite history.
        ProductStock::where('product_id', $product->id)->update(['quantity' => 9, 'quantity_non_tax' => 9]);
        $this->assertSame(1, $approved->fresh()->approval_result['products'][0]['unmatched_reconciliation']['unmatched_before']['good']);
    }

    public function test_multi_location_new_serial_absorbs_deterministically_and_rest_is_removed(): void
    {
        $product = $this->makeProduct(true);
        $this->stock($product, $this->perdana, 1);
        $this->stock($product, $this->whiteKnight, 1);

        $adj = $this->save([['product_id' => $product->id, 'serials' => [['serial_number' => 'SN-ML-1', 'condition' => 'good']]]], [$this->perdana, $this->whiteKnight]);
        $approved = $this->submitAndApprove($adj);

        // Equal stock, both non-PKP: ascending location ID wins the existing unit.
        $this->assertSame(1, $this->locationQty($product, $this->perdana));
        $this->assertSame(0, $this->locationQty($product, $this->whiteKnight));
        $this->assertSame($this->perdana->id, (int) ProductSerialNumber::where('serial_number', 'SN-ML-1')->value('location_id'));
        $this->assertSame(-1.0, (float) $approved->approval_result['products'][0]['net_delta']);
        $this->assertSame(1.0, (float) $product->fresh()->product_quantity);
    }

    // ------------------------------------------------ review findings

    public function test_fresh_reconfirmation_by_another_user_records_that_user(): void
    {
        $product = $this->makeProduct(false);
        $this->stock($product, $this->perdana, 3);

        $adj = $this->save([$this->confirmedRow($product, [$this->perdana], ['zero_confirmation_token' => 'tick-a'])], [$this->perdana]);
        $first = $adj->count_draft['rows'][0];

        $this->travel(5)->minutes();
        $this->actingAs($this->otherUser);
        // B changed the count, restored zero and ticked again: a new checkbox action token.
        $row = $this->save([$this->confirmedRow($product, [$this->perdana], ['zero_confirmation_token' => 'tick-b'])], [$this->perdana], $adj)
            ->count_draft['rows'][0];

        $this->assertSame($this->otherUser->id, $row['zero_confirmed_by']);
        $this->assertNotSame($first['zero_confirmed_at'], $row['zero_confirmed_at']);

        // An unchanged save forwarding B's token keeps B's attribution.
        $this->actingAs($this->user);
        $again = $this->save([$this->confirmedRow($product, [$this->perdana], ['zero_confirmation_token' => 'tick-b'])], [$this->perdana], $adj->fresh())
            ->count_draft['rows'][0];
        $this->assertSame($this->otherUser->id, $again['zero_confirmed_by']);
    }

    public function test_preview_names_the_same_registration_location_as_approval(): void
    {
        $product = $this->makeProduct(true);
        $this->stock($product, $this->whiteKnight, 1); // Perdana empty, White Knight unmatched

        $adj = $this->save([['product_id' => $product->id, 'serials' => [['serial_number' => 'SN-WK-NEW', 'condition' => 'good']]]], [$this->perdana, $this->whiteKnight]);
        $preview = app(StockOpnameReconciliationService::class)->reconcile($adj)->products[0];

        $this->assertStringContainsStringIgnoringCase('White Knight', $preview->serials[0]->label);
        $this->assertSame(1.0, (float) $preview->projectedGlobalTotal);

        $this->submitAndApprove($adj);
        $this->assertSame($this->whiteKnight->id, (int) ProductSerialNumber::where('serial_number', 'SN-WK-NEW')->value('location_id'));
        $this->assertSame(1.0, (float) $product->fresh()->product_quantity);
    }

    public function test_projection_includes_omitted_serials_with_unmatched_removal(): void
    {
        $product = $this->makeProduct(true);
        $this->stock($product, $this->perdana, 2);
        $this->serial($product, $this->perdana, 'SN-REG');

        $adj = $this->save([$this->confirmedRow($product, [$this->perdana])], [$this->perdana]);
        $preview = app(StockOpnameReconciliationService::class)->reconcile($adj)->products[0];
        $this->assertSame(0.0, (float) $preview->projectedGlobalTotal);

        $this->submitAndApprove($adj);
        $this->assertSame(0.0, (float) $product->fresh()->product_quantity);
    }

    public function test_contradictory_protected_coverage_blocks_approval(): void
    {
        $product = $this->makeProduct(true);
        $this->stock($product, $this->perdana, 1);
        $this->serial($product, $this->perdana, 'SN-R1', ProductSerialNumber::STATUS_RETURN_IN_PROCESS);
        $this->serial($product, $this->perdana, 'SN-R2', ProductSerialNumber::STATUS_RETURN_IN_PROCESS);

        $adj = $this->save([$this->confirmedRow($product, [$this->perdana])], [$this->perdana]);
        $this->assertNotEmpty(app(StockOpnameReconciliationService::class)->reconcile($adj)->conflicts);

        app(StockOpnameLifecycleService::class)->submit($adj, $this->user, $this->setting->id);
        try {
            app(StockOpnameApprovalService::class)->approve($adj->fresh(), $this->user, $this->setting->id);
            $this->fail('Approval should have been blocked.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tidak konsisten', collect($e->errors())->flatten()->implode(' '));
        }

        $this->assertSame(AdjustmentStatus::WaitingApproval, AdjustmentStatus::normalize($adj->fresh()->status));
        $this->assertSame(1, $this->locationQty($product, $this->perdana));
    }
}
