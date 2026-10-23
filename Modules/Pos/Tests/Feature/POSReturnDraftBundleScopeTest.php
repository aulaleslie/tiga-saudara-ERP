<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Spatie\Permission\Models\Permission;

class POSReturnDraftBundleScopeTest extends PosTransactionFeatureTestCase
{
    protected $submissionService;
    protected $snapshotService;
    protected $user;
    protected $setting;
    protected $location;
    protected $terminal;
    protected $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->setting = $this->createSetting('Bundle Scope Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.edit', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'Scope Clerk', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.edit',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_has_nullable_bundle_return_scope_column_on_pos_return_lines_table(): void
    {
        $this->assertTrue(
            Schema::hasColumn('pos_return_lines', 'bundle_return_scope'),
            'pos_return_lines table must have bundle_return_scope column.'
        );

        // Historical rows without scope remain null without backfill
        $rawLine = new PosReturnLine();
        $rawLine->pos_return_id = 999999;
        $rawLine->product_id = 1;
        $rawLine->product_name = 'Historical Product';
        $rawLine->product_code = 'HIST';
        $rawLine->quantity = 1;
        $rawLine->unit_price = 1000;
        $rawLine->line_total = 1000;
        $rawLine->resolution = PosReturnLine::RESOLUTION_CASH_RETURN;
        $rawLine->bundle_return_scope = null;
        $this->assertNull($rawLine->bundle_return_scope);
    }

    /** @test */
    public function it_defaults_scope_to_whole_bundle_when_omitted_for_bundle_parent_cash_return(): void
    {
        [$transaction, $parentDetail, $comp1Detail, $comp2Detail, $sale] = $this->createBundlePurchase('DEF');

        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentDetail->id,
                    'sale_id' => $sale->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    // bundle_return_scope omitted
                ],
            ],
        ]);

        $parentLine = $posReturn->lines()->where('sale_detail_id', $parentDetail->id)->first();
        $this->assertNotNull($parentLine);
        $this->assertSame(
            PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
            $parentLine->bundle_return_scope,
            'Bundle parent cash return with omitted scope must default to whole_bundle.'
        );
    }

    /** @test */
    public function it_persists_valid_explicit_whole_bundle_and_parent_only_scope(): void
    {
        [$transaction, $parentDetail, $comp1Detail, $comp2Detail, $sale] = $this->createBundlePurchase('EXP');
        $snapshot = $this->snapshotService->build($transaction->id);

        // Test explicit whole_bundle
        $posReturnWhole = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentDetail->id,
                    'sale_id' => $sale->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                ],
            ],
        ]);

        $parentLineWhole = $posReturnWhole->lines()->where('sale_detail_id', $parentDetail->id)->first();
        $this->assertSame(
            PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
            $parentLineWhole->bundle_return_scope
        );

        // Test explicit parent_only
        $posReturnParent = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentDetail->id,
                    'sale_id' => $sale->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
                ],
            ],
        ]);

        $parentLineParent = $posReturnParent->lines()->where('sale_detail_id', $parentDetail->id)->first();
        $this->assertSame(
            PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
            $parentLineParent->bundle_return_scope
        );
    }

    /** @test */
    public function it_rejects_invalid_bundle_return_scope(): void
    {
        [$transaction, $parentDetail, $comp1Detail, $comp2Detail, $sale] = $this->createBundlePurchase('INV');
        $snapshot = $this->snapshotService->build($transaction->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Scope retur bundle tidak valid.');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentDetail->id,
                    'sale_id' => $sale->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => 'invalid_scope_value',
                ],
            ],
        ]);
    }

    /** @test */
    public function it_leaves_bundle_return_scope_null_for_non_bundle_lines(): void
    {
        $product = $this->createStockedProduct($this->setting, $this->location);
        [$transaction, $saleDetail, $sale] = $this->createSimplePurchase($product, 'NONB');
        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'sale_id' => $sale->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                ],
            ],
        ]);

        $line = $posReturn->lines()->where('sale_detail_id', $saleDetail->id)->first();
        $this->assertNotNull($line);
        $this->assertNull(
            $line->bundle_return_scope,
            'Non-bundle return lines must have null bundle_return_scope.'
        );
    }

    /** @test */
    public function it_synthesizes_proportional_components_for_whole_bundle(): void
    {
        [$transaction, $parentDetail, $comp1Detail, $comp2Detail, $sale] = $this->createBundlePurchase('SYN');
        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentDetail->id,
                    'sale_id' => $sale->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                ],
            ],
        ]);

        $lines = $posReturn->lines()->get();
        // 1 parent line + 2 synthesized component lines
        $this->assertCount(3, $lines);

        $parentLine = $lines->firstWhere('sale_detail_id', $parentDetail->id);
        $this->assertNotNull($parentLine);
        $this->assertSame(PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE, $parentLine->bundle_return_scope);
        $this->assertEquals(500000, $parentLine->expected_cash_amount);

        $compLines = $lines->filter(fn ($l) => $l->bundle_parent_sale_detail_id === $parentDetail->id && $l->id !== $parentLine->id);
        $this->assertCount(2, $compLines);
        foreach ($compLines as $compLine) {
            $this->assertSame(PosReturnLine::RESOLUTION_CASH_RETURN, $compLine->resolution);
            $this->assertEquals(0, $compLine->expected_cash_amount);
            $this->assertEquals(1.0, (float) $compLine->quantity);
            $this->assertNull($compLine->bundle_return_scope);
        }
    }

    /** @test */
    public function it_excludes_component_synthesis_and_records_parent_allocation_for_parent_only(): void
    {
        [$transaction, $parentDetail, $comp1Detail, $comp2Detail, $sale] = $this->createBundlePurchase('PAR');
        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentDetail->id,
                    'sale_id' => $sale->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
                ],
            ],
        ]);

        $lines = $posReturn->lines()->get();
        // Only 1 parent line, no synthesized components
        $this->assertCount(1, $lines);

        $parentLine = $lines->first();
        $this->assertSame($parentDetail->id, $parentLine->sale_detail_id);
        $this->assertSame(PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY, $parentLine->bundle_return_scope);
        $this->assertEquals(500000, $parentLine->expected_cash_amount);
    }

    /** @test */
    public function it_rejects_individual_component_cash_return_requests(): void
    {
        [$transaction, $parentDetail, $comp1Detail, $comp2Detail, $sale] = $this->createBundlePurchase('REJ');
        $snapshot = $this->snapshotService->build($transaction->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Retur uang kembali untuk paket bundel harus mencakup seluruh unit bundel');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $comp1Detail->id,
                    'sale_id' => $sale->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_synthesizes_split_owner_components_for_whole_bundle_and_handles_parent_only_reduction(): void
    {
        $comp = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Split MicroSD',
            'product_code' => 'SPL-SD',
            'stock_qty' => 10,
        ]);
        $parent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Split Camera',
            'product_code' => 'SPL-CAM',
            'sale_price' => 890000,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'name' => 'Split Bundle',
            'is_active' => true,
        ]);
        $bi = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp->id, 'quantity' => 1]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SPLIT-TEST',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $ptl = \Modules\Pos\Entities\PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $parent->id,
            'product_name_snapshot' => $parent->product_name,
            'product_code_snapshot' => $parent->product_code,
            'qty' => 1,
            'unit_price' => 890000,
            'line_meta' => [
                'bundle_id' => $bundle->id,
                'is_bundle' => true,
                'bundle_items' => [
                    [
                        'product_id' => $comp->id,
                        'product_name' => $comp->product_name,
                        'product_code' => $comp->product_code,
                        'quantity' => 1,
                        'bundle_item_id' => $bi->id,
                    ],
                ],
            ],
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 890000,
            'receipt_number' => 'RCP-SPLIT',
            'idempotency_key' => 'IDEM-SPLIT',
            'payload_hash' => 'HASH-SPLIT',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        // Sale 1: Camera (allocated Rp 630,000)
        $sale1 = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 630000,
            'paid_amount' => 630000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SPLIT-1',
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale1->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 630000,
            'split_key' => 'SPLIT-1',
            'tax_bucket' => 'NON_TAX',
        ]);
        $parentDetail = SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $parent->id,
            'quantity' => 1,
            'price' => 630000,
            'unit_price' => 630000,
            'sub_total' => 630000,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        $dispatch1 = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale1->id,
            'status' => 'APPROVED',
        ]);
        \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch1->id,
            'sale_id' => $sale1->id,
            'product_id' => $parent->id,
            'dispatched_quantity' => 1,
            'serial_numbers' => json_encode([]),
        ]);

        // Sale 2: MicroSD (carrier detail qty=0 price=0, component detail qty=1 price=260,000)
        $sale2 = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 260000,
            'paid_amount' => 260000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SPLIT-2',
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale2->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 260000,
            'split_key' => 'SPLIT-2',
            'tax_bucket' => 'NON_TAX',
        ]);
        $carrierDetail = SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $parent->id,
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        $compDetail = SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $comp->id,
            'quantity' => 1,
            'price' => 260000,
            'unit_price' => 260000,
            'sub_total' => 260000,
            'product_name' => $comp->product_name,
            'product_code' => $comp->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        SaleBundleItem::create([
            'sale_id' => $sale2->id,
            'sale_detail_id' => $carrierDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bi->id,
            'product_id' => $comp->id,
            'name' => $comp->product_name,
            'quantity' => 1,
            'price' => 260000,
            'sub_total' => 260000,
        ]);
        $dispatch2 = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale2->id,
            'status' => 'APPROVED',
        ]);
        \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch2->id,
            'sale_id' => $sale2->id,
            'product_id' => $comp->id,
            'dispatched_quantity' => 1,
            'serial_numbers' => json_encode([]),
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        // 1. Whole bundle test: should synthesize component across owners and have 890,000 refund
        $posReturnWhole = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentDetail->id,
                    'sale_id' => $sale1->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'pos_transaction_line_id' => $ptl->id,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                ],
            ],
        ]);

        $wholeLines = $posReturnWhole->lines()->get();
        $this->assertCount(2, $wholeLines);
        $wholeParent = $wholeLines->firstWhere('sale_detail_id', $parentDetail->id);
        $this->assertEquals(890000, $wholeParent->expected_cash_amount);
        $this->assertSame(PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE, $wholeParent->bundle_return_scope);

        $wholeComp = $wholeLines->firstWhere('sale_detail_id', $carrierDetail->id);
        $this->assertNotNull($wholeComp);
        $this->assertEquals(0, $wholeComp->expected_cash_amount);
        $this->assertSame($sale2->id, $wholeComp->sale_id);

        // 2. Parent-only test: should only return parent with 630,000 refund, no component synthesis
        $posReturnParent = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentDetail->id,
                    'sale_id' => $sale1->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'pos_transaction_line_id' => $ptl->id,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
                ],
            ],
        ]);

        $parentLines = $posReturnParent->lines()->get();
        $this->assertCount(1, $parentLines);
        $singleParent = $parentLines->first();
        $this->assertEquals(630000, $singleParent->expected_cash_amount);
        $this->assertSame(PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY, $singleParent->bundle_return_scope);

        // 3. Standalone component cash return rejection
        try {
            $this->submissionService->store([
                'pos_transaction_id' => $transaction->id,
                'source_snapshot_hash' => $snapshot['hash'],
                'lines' => [
                    [
                        'sale_detail_id' => $compDetail->id,
                        'sale_id' => $sale2->id,
                        'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                        'quantity' => 1,
                    ],
                ],
            ]);
            $this->fail('Expected standalone component cash return to be rejected.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Retur uang kembali untuk paket bundel harus mencakup seluruh unit bundel', $e->getMessage());
        }
    }

    /** @test */
    public function it_accepts_whole_bundle_return_of_captured_composition_after_catalog_adds_a_component(): void
    {
        [$transaction, $parentDetail, $comp1Detail, $comp2Detail, $sale] = $this->createBundlePurchase('CATPTL');
        $bundle = ProductBundle::where('parent_product_id', $parentDetail->product_id)->firstOrFail();

        // Composition captured at checkout: the two original components
        \Modules\Pos\Entities\PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $parentDetail->product_id,
            'product_name_snapshot' => $parentDetail->product_name,
            'product_code_snapshot' => $parentDetail->product_code,
            'qty' => 1,
            'unit_price' => 500000,
            'line_meta' => [
                'bundle_id' => $bundle->id,
                'is_bundle' => true,
                'bundle_items' => [
                    ['product_id' => $comp1Detail->product_id, 'quantity' => 1],
                    ['product_id' => $comp2Detail->product_id, 'quantity' => 1],
                ],
            ],
        ]);

        $this->assertWholeBundleReturnSurvivesCatalogComponentAddition($transaction, $parentDetail, $sale, $bundle, 'CATPTL');
    }

    /** @test */
    public function it_accepts_whole_bundle_return_of_persisted_composition_after_catalog_adds_a_component(): void
    {
        [$transaction, $parentDetail, , , $sale] = $this->createBundlePurchase('CATSBI');
        $bundle = ProductBundle::where('parent_product_id', $parentDetail->product_id)->firstOrFail();

        $this->assertWholeBundleReturnSurvivesCatalogComponentAddition($transaction, $parentDetail, $sale, $bundle, 'CATSBI');
    }

    private function assertWholeBundleReturnSurvivesCatalogComponentAddition($transaction, $parentDetail, $sale, ProductBundle $bundle, string $suffix): void
    {
        $snapshot = $this->snapshotService->build($transaction->id);

        // Catalog edited after checkout: a third component is added
        $addedComponent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Added Later ' . $suffix,
            'product_code' => 'ADD' . $suffix,
            'stock_qty' => 5,
        ]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $addedComponent->id, 'quantity' => 1]);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [[
                'sale_detail_id' => $parentDetail->id,
                'sale_id' => $sale->id,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'quantity' => 1,
                'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
            ]],
        ]);

        $lines = $posReturn->lines()->get();
        $this->assertCount(3, $lines, 'Parent plus the two originally sold components only.');
        $this->assertFalse($lines->contains('product_id', $addedComponent->id));
    }

    protected function createBundlePurchase(string $suffix = ''): array
    {
        $comp1 = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Comp 1' . $suffix,
            'product_code' => 'C1' . $suffix,
            'stock_qty' => 20,
        ]);
        $comp2 = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Comp 2' . $suffix,
            'product_code' => 'C2' . $suffix,
            'stock_qty' => 20,
        ]);
        $parent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Parent' . $suffix,
            'product_code' => 'P' . $suffix,
            'sale_price' => 500000,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'name' => 'Header' . $suffix,
            'is_active' => true,
        ]);

        $bi1 = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp1->id, 'quantity' => 1]);
        $bi2 = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp2->id, 'quantity' => 1]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SCP' . $suffix,
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 500000,
            'receipt_number' => 'RCP-SCP' . $suffix,
            'idempotency_key' => 'IDEM-SCP' . $suffix,
            'payload_hash' => 'HASH-SCP' . $suffix,
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SCP' . $suffix,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 500000,
            'split_key' => 'SPLIT-SCP' . $suffix,
            'tax_bucket' => 'NON_TAX',
        ]);

        $parentDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'quantity' => 1,
            'price' => 500000,
            'unit_price' => 500000,
            'sub_total' => 500000,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bi1->id,
            'product_id' => $comp1->id,
            'name' => $comp1->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bi2->id,
            'product_id' => $comp2->id,
            'name' => $comp2->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);

        $comp1Detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $comp1->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $comp1->product_name,
            'product_code' => $comp1->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $comp2Detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $comp2->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $comp2->product_name,
            'product_code' => $comp2->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        return [$transaction, $parentDetail, $comp1Detail, $comp2Detail, $sale];
    }

    protected function createSimplePurchase($product, string $suffix = ''): array
    {
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SMP' . $suffix,
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 100000,
            'receipt_number' => 'RCP-SMP' . $suffix,
            'idempotency_key' => 'IDEM-SMP' . $suffix,
            'payload_hash' => 'HASH-SMP' . $suffix,
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SMP' . $suffix,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 100000,
            'split_key' => 'SPLIT-SMP' . $suffix,
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        return [$transaction, $saleDetail, $sale];
    }
}
