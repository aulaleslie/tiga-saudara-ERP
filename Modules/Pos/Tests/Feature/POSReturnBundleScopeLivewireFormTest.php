<?php

namespace Modules\Pos\Tests\Feature;

use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Livewire\PosReturn\PosReturnCreateForm;
use Modules\Pos\Livewire\PosReturn\PosReturnEditForm;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Spatie\Permission\Models\Permission;

class POSReturnBundleScopeLivewireFormTest extends PosTransactionFeatureTestCase
{
    protected $submissionService;
    protected $snapshotService;
    protected $setting;
    protected $sourceSetting;
    protected $user;
    protected $terminal;
    protected $session;
    protected $location;
    protected $sourceLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);

        $this->setting = $this->createSetting('Terminal Setting');
        $this->sourceSetting = $this->createSetting('Source Setting');

        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.edit', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'Bundle Form User', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.edit',
        ]);

        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        [$sourceTerminal, $this->sourceLocation] = $this->createTerminalWithLocation($this->sourceSetting);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /**
     * Build an incident-shaped split-owner non-serial bundle fixture:
     * - Parent camera: 630,000 allocated price, sold on terminal sale.
     * - Component microSD: 260,000 allocated price, sold on source setting sale via carrier row.
     * - Total bundle price: 890,000.
     * - Total purchased quantity: 3 units.
     */
    protected function createBundleFixture(int $quantity = 3): array
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $camera = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Action Camera 4K',
            'product_code' => 'CAM-4K',
            'sale_price' => 630000,
            'serial_number_required' => false,
        ]);

        $microSd = $this->createStockedProduct($this->sourceSetting, $this->sourceLocation, [
            'product_name' => 'MicroSD 64GB Extreme',
            'product_code' => 'SD-64GB',
            'sale_price' => 260000,
            'serial_number_required' => false,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $camera->id,
            'setting_id' => $this->setting->id,
            'name' => 'Camera Adventure Bundle',
            'bundle_sale_price' => 890000,
            'price' => 0,
        ]);

        $bundleItem = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $microSd->id,
            'quantity' => 1,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-BUNDLE-' . uniqid(),
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
            'grand_total' => 890000 * $quantity,
            'receipt_number' => 'RCP-BUNDLE-' . uniqid(),
            'idempotency_key' => 'IDEM-BUNDLE-' . uniqid(),
            'payload_hash' => 'HASH-BUNDLE-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        // Sale 1: Terminal Owner (Camera parent)
        $sale1 = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 630000 * $quantity,
            'paid_amount' => 630000 * $quantity,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-CAM-' . uniqid(),
        ]);

        $checkoutSale1 = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale1->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 630000 * $quantity,
            'split_key' => 'SPLIT-CAM',
            'tax_bucket' => 'NON_TAX',
        ]);

        $cameraDetail = SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $camera->id,
            'quantity' => $quantity,
            'price' => 630000,
            'unit_price' => 630000,
            'sub_total' => 630000 * $quantity,
            'product_name' => $camera->product_name,
            'product_code' => $camera->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch1 = Dispatch::create(['sale_id' => $sale1->id, 'status' => 'APPROVED']);
        $cameraDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch1->id,
            'sale_id' => $sale1->id,
            'sale_detail_id' => $cameraDetail->id,
            'product_id' => $camera->id,
            'dispatched_quantity' => $quantity,
            'location_id' => $this->location->id,
        ]);

        // Sale 2: Source Owner (MicroSD carrier row + SaleBundleItem)
        $sale2 = Sale::create([
            'setting_id' => $this->sourceSetting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 260000 * $quantity,
            'paid_amount' => 260000 * $quantity,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SD-' . uniqid(),
        ]);

        $checkoutSale2 = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale2->id,
            'source_setting_id' => $this->sourceSetting->id,
            'source_location_id' => $this->sourceLocation->id,
            'grand_total' => 260000 * $quantity,
            'split_key' => 'SPLIT-SD',
            'tax_bucket' => 'NON_TAX',
        ]);

        $carrierDetail = SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $camera->id,
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $camera->product_name,
            'product_code' => $camera->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $saleBundleItem = SaleBundleItem::create([
            'sale_id' => $sale2->id,
            'sale_detail_id' => $carrierDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $microSd->id,
            'name' => $microSd->product_name,
            'quantity' => $quantity,
            'price' => 260000,
            'sub_total' => 260000 * $quantity,
        ]);

        $dispatch2 = Dispatch::create(['sale_id' => $sale2->id, 'status' => 'APPROVED']);
        $sdDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch2->id,
            'sale_id' => $sale2->id,
            'sale_detail_id' => $carrierDetail->id,
            'product_id' => $microSd->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => $quantity,
            'location_id' => $this->sourceLocation->id,
        ]);

        // PosTransactionLine linking bundle metadata
        $ptl = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'line_no' => 1,
            'product_id' => $camera->id,
            'product_name_snapshot' => $camera->product_name,
            'product_code_snapshot' => $camera->product_code,
            'qty' => $quantity,
            'unit_price' => 890000,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_name' => $bundle->name,
                'bundle_items' => [
                    [
                        'product_id' => $microSd->id,
                        'product_name' => $microSd->product_name,
                        'product_code' => $microSd->product_code,
                        'quantity' => 1,
                        'bundle_item_id' => $bundleItem->id,
                    ],
                ],
            ],
        ]);

        $cameraDetail->update(['pos_transaction_line_id' => $ptl->id]);

        return compact(
            'transaction',
            'checkout',
            'camera',
            'microSd',
            'bundle',
            'sale1',
            'sale2',
            'cameraDetail',
            'carrierDetail',
            'saleBundleItem',
            'ptl'
        );
    }

    /** @test */
    public function create_form_defaults_bundle_scope_to_whole_bundle_and_reflects_full_bundle_refund(): void
    {
        $fixture = $this->createBundleFixture(3);

        $test = Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', $fixture['transaction']->code)
            ->call('lookup');

        $lineKey = PosReturnCreateForm::buildLineKey($test->get('snapshot.lines.0'));

        // Sanity: selection is initialized with whole_bundle default
        $this->assertEquals(
            PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
            $test->get("lineSelections.{$lineKey}.bundle_return_scope")
        );

        // Select 1 unit cash return
        $test->call('updateResolution', $lineKey, PosReturnLine::RESOLUTION_CASH_RETURN)
            ->set("lineSelections.{$lineKey}.quantity", 1);

        // Scope remains whole_bundle and shows full bundle price Rp890,000 refund with zero retained
        $test->assertSee('Paket Lengkap')
            ->assertSee('Termasuk seluruh komponen')
            ->assertDontSee('Tagihan komponen ditahan')
            ->assertSee('890.000');
    }

    /** @test */
    public function create_form_explicit_parent_only_choice_shows_retained_component_charges(): void
    {
        $fixture = $this->createBundleFixture(3);

        $test = Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', $fixture['transaction']->code)
            ->call('lookup');

        $lineKey = PosReturnCreateForm::buildLineKey($test->get('snapshot.lines.0'));

        // Select 2 units cash return, then toggle to parent_only
        $test->call('updateResolution', $lineKey, PosReturnLine::RESOLUTION_CASH_RETURN)
            ->set("lineSelections.{$lineKey}.quantity", 2)
            ->call('updateBundleScope', $lineKey, PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY);

        $this->assertEquals(
            PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
            $test->get("lineSelections.{$lineKey}.bundle_return_scope")
        );

        // 2 units * Rp260,000 component allocation = Rp520,000 retained
        // 2 units * Rp630,000 camera allocation = Rp1,260,000 refund
        $test->assertSee('Hanya Produk Utama')
            ->assertSee('Tagihan komponen ditahan')
            ->assertSee('520.000')
            ->assertSee('1.260.000');
    }

    /** @test */
    public function roundtrip_persistence_saves_scope_and_edit_form_hydrates_and_preserves_it(): void
    {
        $fixture = $this->createBundleFixture(3);

        $snapshot = $this->snapshotService->build($fixture['transaction']->id);
        $parentSnapshotLine = $snapshot['lines'][0];

        // 1. Store draft with explicit parent_only scope
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_id' => $parentSnapshotLine['sale_id'],
                    'sale_detail_id' => $parentSnapshotLine['sale_detail_id'],
                    'pos_transaction_line_id' => $parentSnapshotLine['pos_transaction_line_id'],
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
                ],
            ],
        ]);

        $this->assertSame(
            PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
            $posReturn->lines()->first()->bundle_return_scope
        );

        // 2. Open in PosReturnEditForm: verify hydration from existing draft
        $editTest = Livewire::test(PosReturnEditForm::class, ['return' => $posReturn]);
        $lineKey = PosReturnCreateForm::buildLineKey($parentSnapshotLine);

        $this->assertEquals(
            PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
            $editTest->get("lineSelections.{$lineKey}.bundle_return_scope")
        );

        // Verify view displays parent_only and retained component charges (Rp260,000 for 1 qty)
        $editTest->assertSee('Hanya Produk Utama')
            ->assertSee('Tagihan komponen ditahan')
            ->assertSee('260.000')
            ->assertSee('630.000');

        // 3. User switches back to whole_bundle and saves
        $editTest->call('updateBundleScope', $lineKey, PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE)
            ->call('submit');

        $posReturn->refresh();
        $this->assertSame(
            PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
            $posReturn->lines()->first()->bundle_return_scope
        );
    }

    /** @test */
    public function unselected_bundle_lines_have_no_action_even_if_scope_is_present(): void
    {
        $fixture = $this->createBundleFixture(3);

        $test = Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', $fixture['transaction']->code)
            ->call('lookup');

        $lineKey = PosReturnCreateForm::buildLineKey($test->get('snapshot.lines.0'));

        // Resolution remains 'none'
        $this->assertEquals(PosReturnLine::RESOLUTION_NONE, $test->get("lineSelections.{$lineKey}.resolution"));

        // Attempting to save without selecting actionable lines fails with error
        $test->call('submit');

        $test->assertHasErrors(['lineSelections']);
        $this->assertEquals(0, PosReturn::count());
    }
}
