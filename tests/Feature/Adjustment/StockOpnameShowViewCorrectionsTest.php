<?php

namespace Tests\Feature\Adjustment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\StockOpnameApprovalService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Focused corrections to the section 5 (Bahasa Indonesia review interface)
 * Blade/controller wiring: the versioned show() path must never pass the raw
 * Adjustment model (and therefore its count_draft/approval_result casts) to
 * Blade, and approved serialized products must carry complete immutable
 * good/bad + product identity evidence rather than only a serial count.
 */
class StockOpnameShowViewCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $location;
    protected Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'Rp',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company', 'company_email' => 'test@company.com',
            'company_phone' => '123456789', 'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer', 'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->location = Location::create([
            'name' => 'Gudang Utama', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $this->tax = Tax::create(['name' => 'PPN', 'value' => 11, 'is_default' => true]);

        $this->user = User::factory()->create(['is_active' => 1]);

        Permission::findOrCreate('adjustments.show', 'web');
        Permission::findOrCreate('adjustments.edit', 'web');
        Permission::findOrCreate('adjustments.approval', 'web');
        Permission::findOrCreate('adjustments.delete', 'web');
        Permission::findOrCreate('adjustments.view-system-stock', 'web');
        $this->user->givePermissionTo([
            'adjustments.show', 'adjustments.edit', 'adjustments.approval',
            'adjustments.delete', 'adjustments.view-system-stock',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
    }

    private function makeProduct(bool $serialized = false, string $unitName = 'Pcs'): Product
    {
        $unit = Unit::create([
            'name' => $unitName, 'short_name' => strtolower(substr($unitName, 0, 3)),
            'operator' => '*', 'operation_value' => 1, 'is_active' => true,
        ]);

        return Product::create([
            'product_name' => $serialized ? 'Produk Serial' : 'Produk Uji',
            'product_code' => ($serialized ? 'PS-' : 'PU-') . uniqid(),
            'product_cost' => 1000, 'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $unit->id, 'base_unit_id' => $unit->id,
            'stock_managed' => true, 'is_active' => true,
            'serial_number_required' => $serialized,
        ]);
    }

    private function makeStockRow(Product $product, Location $location, int $goodTax = 0, int $goodNonTax = 0, int $badTax = 0, int $badNonTax = 0): ProductStock
    {
        return ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $goodTax + $goodNonTax + $badTax + $badNonTax,
            'quantity_tax' => $goodTax,
            'quantity_non_tax' => $goodNonTax,
            'broken_quantity' => $badTax + $badNonTax,
            'broken_quantity_tax' => $badTax,
            'broken_quantity_non_tax' => $badNonTax,
        ]);
    }

    private function makeWaitingApprovalAdjustment(array $rows): Adjustment
    {
        return Adjustment::create([
            'reference' => 'ADJ-VIEW-' . uniqid(),
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::WaitingApproval,
            'location_id' => $this->location->id,
            'submitted_by' => $this->user->id,
            'submitted_at' => now(),
            'count_draft' => ['schema_version' => 1, 'rows' => $rows],
        ]);
    }

    // --- Correction 1: raw model / protected payload must never reach Blade ---

    public function test_versioned_show_view_does_not_receive_the_raw_adjustment_model_or_protected_payloads()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location, goodTax: 5);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 5, 'bad_count' => 0, 'serials' => []],
        ]);

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertOk();

        $viewData = $response->viewData('adjustment');
        $this->assertIsArray($viewData, 'The versioned show view must receive a safe array projection, not the Eloquent model.');
        $this->assertArrayNotHasKey('count_draft', $viewData);
        $this->assertArrayNotHasKey('approval_result', $viewData);

        // No other view-data key may carry the raw model or its protected casts either.
        foreach ($response->original->getData() as $key => $value) {
            $this->assertNotInstanceOf(
                Adjustment::class,
                $value,
                "View data key '{$key}' must not be the raw Adjustment model."
            );
        }

        // The rendered HTML must never leak the raw count_draft JSON structure
        // (e.g. its schema_version marker or baseline token keys).
        $response->assertDontSee('schema_version');
        $response->assertDontSee('existing_good_total');
        $response->assertDontSee('existing_bad_total');
    }

    public function test_versioned_show_page_title_is_bahasa_indonesia()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location, goodTax: 5);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 5, 'bad_count' => 0, 'serials' => []],
        ]);

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertOk();
        $response->assertSee('Rincian Stock Opname');
        $response->assertDontSee('Adjustment Details', false);
    }

    // --- Correction 2 & 3: approved serialized evidence completeness ---

    public function test_approved_serialized_evidence_carries_full_good_bad_and_product_identity()
    {
        $product = $this->makeProduct(true, 'Unit');
        $stock = $this->makeStockRow($product, $this->location, goodTax: 1);
        $this->makeSerial($product, $this->location, 'SN-VIEW-1', isBroken: false, taxId: $this->tax->id);
        $product->update(['product_quantity' => 1]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-VIEW-1', 'condition' => 'bad'],
            ]],
        ]);

        app(StockOpnameApprovalService::class)->approve($adjustment, $this->user);

        $productResult = $adjustment->fresh()->approval_result['products'][0];

        $this->assertSame($product->product_code, $productResult['product_code']);
        $this->assertSame('UNIT', $productResult['base_unit']);

        // Entered good/bad, derived from applied serial conditions (one
        // serial entered as bad -> entered.good=0, entered.bad=1).
        $this->assertSame(0, $productResult['entered']['good']);
        $this->assertSame(1, $productResult['entered']['bad']);

        // Destination-location good/bad immediately before approval.
        $this->assertEquals(1, $productResult['before']['good']);
        $this->assertEquals(0, $productResult['before']['bad']);

        // Applied destination good/bad after approval.
        $this->assertEquals(0, $productResult['applied']['good']);
        $this->assertEquals(1, $productResult['applied']['bad']);
    }

    public function test_approved_serialized_counter_view_shows_entered_counts_not_zero()
    {
        Permission::findOrCreate('adjustments.view-system-stock', 'web');
        $this->user->revokePermissionTo('adjustments.view-system-stock');

        $product = $this->makeProduct(true, 'Unit');
        $this->makeStockRow($product, $this->location, goodTax: 1);
        $this->makeSerial($product, $this->location, 'SN-COUNTER-1', isBroken: false, taxId: $this->tax->id);
        $product->update(['product_quantity' => 1]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-COUNTER-1', 'condition' => 'good'],
            ]],
        ]);

        app(StockOpnameApprovalService::class)->approve($adjustment, $this->user);

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertOk();

        $vm = $response->viewData('stockOpnameViewModel');
        $this->assertSame(1, $vm['products'][0]['entered']['good']);
        $this->assertSame(0, $vm['products'][0]['entered']['bad']);
        $this->assertCount(1, $vm['products'][0]['serials']);
        $this->assertSame('SN-COUNTER-1', $vm['products'][0]['serials'][0]['serial_number']);
        $this->assertSame('good', $vm['products'][0]['serials'][0]['condition']);

        // No protected stock/tax/source facts anywhere in this projection.
        $this->assertArrayNotHasKey('before', $vm['products'][0]);
        $this->assertArrayNotHasKey('applied', $vm['products'][0]);

        $response->assertDontSee('Kena Pajak');
        $response->assertDontSee('Tidak Kena Pajak');
    }

    public function test_approved_serialized_reviewer_sees_before_entered_applied_after_live_stock_changes_again()
    {
        $product = $this->makeProduct(true, 'Unit');
        $stock = $this->makeStockRow($product, $this->location, goodTax: 1);
        $this->makeSerial($product, $this->location, 'SN-REVIEWER-1', isBroken: false, taxId: $this->tax->id);
        $product->update(['product_quantity' => 1]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-REVIEWER-1', 'condition' => 'good'],
            ]],
        ]);

        app(StockOpnameApprovalService::class)->approve($adjustment, $this->user);

        // Live stock changes again after approval.
        $stock->fresh()->update(['quantity_tax' => 99, 'quantity' => 99]);

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertOk();

        $vm = $response->viewData('stockOpnameViewModel');
        $productResult = $vm['products'][0];

        // Immutable evidence, not the now-changed (99) live stock.
        $this->assertEquals(99, $stock->fresh()->quantity_tax, 'sanity: live stock really did change after approval');
        $this->assertEquals(1, $productResult['before']['good']);
        $this->assertSame(1, $productResult['entered']['good']);
        $this->assertEquals(0, $productResult['applied']['bad']);
        $this->assertEquals(1, $productResult['applied']['good']);
        $response->assertDontSee('>99<', false);
    }

    public function test_approved_serialized_product_code_and_unit_survive_from_immutable_approval_result()
    {
        $product = $this->makeProduct(true, 'Meter');
        $this->makeStockRow($product, $this->location, goodTax: 1);
        $this->makeSerial($product, $this->location, 'SN-UNIT-1', isBroken: false, taxId: $this->tax->id);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-UNIT-1', 'condition' => 'good'],
            ]],
        ]);

        app(StockOpnameApprovalService::class)->approve($adjustment, $this->user);

        // Even after the product itself changes, the approval record's
        // product identity evidence must remain as it was at approval time.
        $product->update(['product_code' => 'CHANGED-CODE']);

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertOk();

        $vm = $response->viewData('stockOpnameViewModel');
        $this->assertSame('METER', $vm['products'][0]['base_unit']);
        $this->assertNotEquals('CHANGED-CODE', $vm['products'][0]['product_code']);
    }

    // --- Bahasa Indonesia labels on the versioned lifecycle ---

    public function test_versioned_lifecycle_labels_are_bahasa_indonesia_across_statuses()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location, goodTax: 5);

        $draft = Adjustment::create([
            'reference' => 'ADJ-LABEL-DRAFT',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
            'location_id' => $this->location->id,
            'count_draft' => ['schema_version' => 1, 'rows' => [
                ['product_id' => $product->id, 'good_count' => 5, 'bad_count' => 0, 'serials' => []],
            ]],
        ]);

        $response = $this->get(route('adjustments.show', $draft));
        $response->assertOk();
        $response->assertSee('Draf');
        $response->assertSee('Ajukan Persetujuan');
        $response->assertDontSee('Submit', false);
        $response->assertDontSee('Draft', false);
    }

    // --- Gap fill (section 6 audit): adjustments.view-system-stock and
    // adjustments.approval are independent permissions, proven at the
    // rendered-HTML level, not merely via a POST/PATCH route guard. ---

    public function test_reviewer_without_approval_permission_does_not_see_approve_or_reject_controls()
    {
        // The acting user keeps adjustments.view-system-stock (from setUp)
        // but never had adjustments.approval to begin with.
        $this->user->revokePermissionTo('adjustments.approval');

        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location, goodTax: 5);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 8, 'bad_count' => 0, 'serials' => []],
        ]);

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertOk();

        // Reviewer data is still visible (view-system-stock is intact) ...
        $response->assertSee('Ringkasan Peninjauan');
        $response->assertSee('Saat Ini');

        // ... but approve/reject controls are not, because adjustments.approval
        // is a separate, unheld permission.
        $this->assertFalse($response->viewData('canApprove'));
        $this->assertFalse($response->viewData('showApprove'));
        $this->assertFalse($response->viewData('showReject'));
        $response->assertDontSee('action="' . route('adjustments.approve', $adjustment), false);
        $response->assertDontSee('action="' . route('adjustments.reject', $adjustment), false);
        $response->assertDontSee('>Setuju<', false);
        $response->assertDontSee('>Tolak<', false);
    }

    public function test_counter_without_approval_or_view_system_stock_sees_neither_reviewer_data_nor_approve_reject_controls()
    {
        Permission::findOrCreate('adjustments.view-system-stock', 'web');
        $this->user->revokePermissionTo(['adjustments.view-system-stock', 'adjustments.approval']);

        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location, goodTax: 5);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 8, 'bad_count' => 0, 'serials' => []],
        ]);

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertOk();

        $response->assertDontSee('Ringkasan Peninjauan');
        $response->assertDontSee('Saat Ini');
        $response->assertDontSee('>Setuju<', false);
        $response->assertDontSee('>Tolak<', false);
    }

    // --- Gap fill: counter HTML/state omits warnings/conflicts/drift even
    // when the underlying document actually has one, not merely when there
    // happens to be nothing to warn about. ---

    public function test_counter_view_omits_warning_conflict_and_drift_data_when_document_actually_has_them()
    {
        Permission::findOrCreate('adjustments.view-system-stock', 'web');
        $this->user->revokePermissionTo('adjustments.view-system-stock');

        $product = $this->makeProduct();
        // Baseline captured at 5, but current stock has since drifted to 5
        // good while the entered count (20) exceeds the all-location total,
        // guaranteeing both a drift warning and an all-location-exceeded
        // warning are actually produced by the reconciliation service.
        $this->makeStockRow($product, $this->location, goodTax: 5);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            [
                'product_id' => $product->id,
                'good_count' => 20,
                'bad_count' => 0,
                'serials' => [],
                'baseline' => ['existing_good_total' => 2, 'existing_bad_total' => 0],
            ],
        ]);

        // Sanity: a reviewer would actually see warnings for this same document.
        $reviewerVm = app(\Modules\Adjustment\Services\StockOpnameReconciliationService::class)
            ->reconcile($adjustment, locked: false)
            ->toReviewerArray();
        $this->assertNotEmpty($reviewerVm['warnings'], 'Test setup must actually produce a warning to be a meaningful negative assertion.');

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertOk();

        $vm = $response->viewData('stockOpnameViewModel');
        $this->assertArrayNotHasKey('warnings', $vm);
        $this->assertArrayNotHasKey('conflicts', $vm);
        $this->assertArrayNotHasKey('drift', $vm['products'][0]);
        $this->assertArrayNotHasKey('all_location_current_total', $vm['products'][0]);
        $this->assertArrayNotHasKey('exceeds_all_location_total', $vm['products'][0]);

        $response->assertDontSee('Ringkasan Peninjauan');
        $response->assertDontSee('kenaikan stok global');
        $response->assertDontSee('drift');
    }

    // --- Gap fill: ownership/consignment restrictions at the route level for
    // show, update, and reject (edit/submit/approve/delete/create already
    // have route-level coverage in sibling lifecycle test files). ---

    private function makeOtherSettingLocation(): Location
    {
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'USD', 'code' => 'USD', 'symbol' => '$',
            'thousand_separator' => ',', 'decimal_separator' => '.', 'exchange_rate' => 1,
        ]);
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);

        return Location::create([
            'name' => 'Gudang Lintas Setting', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);
    }

    public function test_show_route_rejects_document_owned_by_another_setting()
    {
        $otherLocation = $this->makeOtherSettingLocation();

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SHOW-CROSS',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
            'location_id' => $otherLocation->id,
            'count_draft' => ['schema_version' => 1, 'rows' => []],
        ]);

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertForbidden();
    }

    public function test_update_route_rejects_document_owned_by_another_setting()
    {
        $otherLocation = $this->makeOtherSettingLocation();

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-UPDATE-CROSS',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
            'location_id' => $otherLocation->id,
            'count_draft' => ['schema_version' => 1, 'rows' => []],
        ]);

        $response = $this->put(route('adjustments.update', $adjustment), [
            'count_draft' => json_encode(['schema_version' => 1, 'rows' => []]),
            'reference' => 'ADJ-UPDATE-CROSS',
            'date' => now()->toDateString(),
            'location_id' => $otherLocation->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('adjustments', ['id' => $adjustment->id, 'status' => 'DRAFT']);
    }

    public function test_reject_route_rejects_document_owned_by_another_setting()
    {
        $otherLocation = $this->makeOtherSettingLocation();

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-REJECT-CROSS',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::WaitingApproval,
            'location_id' => $otherLocation->id,
            'submitted_at' => now(),
            'count_draft' => ['schema_version' => 1, 'rows' => [
                ['product_id' => 1, 'good_count' => 1, 'bad_count' => 0, 'serials' => []],
            ]],
        ]);

        $response = $this->patch(route('adjustments.reject', $adjustment), [
            'rejection_reason' => 'Alasan lintas pengaturan.',
        ]);

        // Consistent with submit/approve's cross-setting guard: the shared
        // AdjustmentOwnershipGuard throws inside the service, which the
        // controller surfaces as a redirect with session errors rather than
        // an HTTP 403 (only the permission Gate check aborts with 403).
        $response->assertSessionHasErrors('message');
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
        $this->assertNull($adjustment->fresh()->rejected_at);
    }

    // --- Gap fill: a destination location that becomes consignment between
    // draft creation and a subsequent lifecycle action must block that
    // action at the route level too (approval's equivalent race is already
    // covered in StockOpnameApprovalTest; submit/update were not). ---

    public function test_submit_route_rejects_when_destination_location_became_consignment_since_draft_creation()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location, goodTax: 5);

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SUBMIT-CONSIGN',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
            'location_id' => $this->location->id,
            'count_draft' => ['schema_version' => 1, 'rows' => [
                ['product_id' => $product->id, 'good_count' => 5, 'bad_count' => 0, 'serials' => []],
            ]],
        ]);

        $this->location->update(['is_consignment' => true]);

        // AdjustmentController::submit() checks assertAdjustmentOwned() (which
        // aborts 403 directly) before delegating to the lifecycle service, so
        // a consignment-flip caught at the controller boundary surfaces as a
        // 403, not a redirect with session errors.
        $response = $this->patch(route('adjustments.submit', $adjustment));
        $response->assertForbidden();
        $this->assertEquals(AdjustmentStatus::Draft, $adjustment->fresh()->status);
    }

    private function makeSerial(Product $product, Location $location, string $serialNumber, bool $isBroken = false, ?int $taxId = null): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $serialNumber,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => $isBroken,
            'tax_id' => $taxId,
        ]);
    }
}
