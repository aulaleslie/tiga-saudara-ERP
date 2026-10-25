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
use Modules\Pos\Services\PosReturnLifecycleService;
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

class PosReturnFormRoundingValuationLivewireTest extends PosTransactionFeatureTestCase
{
    protected $submissionService;

    protected $snapshotService;

    protected $setting;

    protected $user;

    protected $terminal;

    protected $session;

    protected $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->setting = $this->createSetting('POS Return Rounding Form Setting');
        $this->setting->update([
            'row_total_rounding_increment' => 100,
        ]);

        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.edit', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'Form Rounding User', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.edit',
        ]);

        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /**
     * Create a transaction matching the 8611 shape:
     * 12 markers at unit price 7083.33, total charged 85000, setting increment 100.
     */
    protected function create8611ShapeFixture(): array
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'SPIDOL SNOWMAN WHITE BOARD',
            'product_code' => 'SPIDOL-01',
            'sale_price' => 7083.33,
            'serial_number_required' => false,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-8611-SHAPE',
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
            'grand_total' => 85000,
            'receipt_number' => 'RCP-8611-SHAPE',
            'idempotency_key' => 'IDEM-8611-SHAPE',
            'payload_hash' => 'HASH-8611-SHAPE',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 85000,
            'paid_amount' => 85000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-8611-SHAPE',
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 85000,
            'split_key' => 'SPLIT-8611-SHAPE',
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 12,
            'price' => 7083.33,
            'unit_price' => 7083.33,
            'sub_total' => 85000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $ptl = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'line_no' => 1,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'product_code_snapshot' => $product->product_code,
            'qty' => 12,
            'unit_price' => 7083.33,
            'subtotal' => 85000,
            'line_meta' => [
                'charged_total' => 85000,
            ],
        ]);

        $saleDetail->update(['pos_transaction_line_id' => $ptl->id]);

        return compact(
            'transaction',
            'checkout',
            'product',
            'sale',
            'saleDetail',
            'ptl'
        );
    }

    /** @test */
    public function create_form_displays_rounded_line_and_total_and_updates_on_quantity_change_matching_saved_record(): void
    {
        $fixture = $this->create8611ShapeFixture();
        $this->actingAsInSetting($this->user, $this->setting);

        $test = Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', $fixture['transaction']->code)
            ->call('lookup');

        $lineKey = PosReturnCreateForm::buildLineKey($test->get('snapshot.lines.0'));

        // Unit price in card header remains unrounded
        $test->assertSee('7.083,33');

        // Select 1 unit cash return: cumulative rounding valuation should produce Rp7.100 (not Rp7.083,33)
        $test->call('updateResolution', $lineKey, PosReturnLine::RESOLUTION_CASH_RETURN)
            ->set("lineSelections.{$lineKey}.quantity", 1);

        $test->assertSee('7.100')
            ->assertDontSee('Total Retur Tunai: Rp7.083,33');

        // Updating quantity to 2 should update both selected line amount and Total Retur Tunai to Rp14.200
        $test->set("lineSelections.{$lineKey}.quantity", 2);
        $test->assertSee('14.200');

        // Submit form
        $test->call('submit');
        $test->assertHasNoErrors();

        // Saved PosReturn and PosReturnLine must exactly match displayed Rp14.200
        $posReturn = PosReturn::first();
        $this->assertNotNull($posReturn);
        $this->assertEquals(14200.0, (float) $posReturn->total_amount);

        $savedLine = $posReturn->lines()->first();
        $this->assertNotNull($savedLine);
        $this->assertEquals(2.0, (float) $savedLine->quantity);
        $this->assertEquals(14200.0, (float) $savedLine->line_total);
        $this->assertEquals(14200.0, (float) $savedLine->expected_cash_amount);
    }

    /** @test */
    public function edit_form_displays_rounded_amount_and_updates_on_quantity_change_matching_saved_record(): void
    {
        $fixture = $this->create8611ShapeFixture();
        $this->actingAsInSetting($this->user, $this->setting);

        $snapshot = $this->snapshotService->build($fixture['transaction']->id);
        $lineKey = PosReturnCreateForm::buildLineKey($snapshot['lines'][0]);

        // Save initial draft with 1 unit (saved amount: 7.100)
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [[
                'sale_id' => $snapshot['lines'][0]['sale_id'],
                'sale_detail_id' => $snapshot['lines'][0]['sale_detail_id'],
                'pos_transaction_line_id' => $snapshot['lines'][0]['pos_transaction_line_id'],
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'quantity' => 1,
            ]],
        ]);

        $this->assertEquals(7100.0, (float) $posReturn->total_amount);

        // Open in PosReturnEditForm
        $editTest = Livewire::test(PosReturnEditForm::class, ['return' => $posReturn]);

        // Unit price remains unrounded
        $editTest->assertSee('7.083,33');

        // Displays rounded amount Rp7.100 for line and Total Retur Tunai
        $editTest->assertSee('7.100');

        // Change quantity to 2
        $editTest->set("lineSelections.{$lineKey}.quantity", 2);
        $editTest->assertSee('14.200');

        // Submit edit
        $editTest->call('submit');
        $editTest->assertHasNoErrors();

        $posReturn->refresh();
        $this->assertEquals(14200.0, (float) $posReturn->total_amount);

        $updatedLine = $posReturn->lines()->first();
        $this->assertEquals(2.0, (float) $updatedLine->quantity);
        $this->assertEquals(14200.0, (float) $updatedLine->line_total);
        $this->assertEquals(14200.0, (float) $updatedLine->expected_cash_amount);
    }

    /** @test */
    public function create_and_edit_forms_respect_prior_committed_returns_and_final_residual_exhaustion(): void
    {
        $fixture = $this->create8611ShapeFixture();
        $this->actingAsInSetting($this->user, $this->setting);

        // Seed prior committed return on Sale: 1 unit returned for 7100
        $commKey = 'ptl_' . $fixture['ptl']->id;
        $fixture['sale']->update([
            'return_rounding_context' => [
                'policy_version' => 1,
                'pos_business_id' => $this->setting->id,
                'frozen_increment' => 100.0,
                'commercial_lines' => [
                    $commKey => [
                        'commercial_line_id' => $fixture['ptl']->id,
                        'baseline_quantity' => 12.0,
                        'baseline_returnable_amount' => 85000.0,
                        'historical_returned_quantity' => 0.0,
                        'historical_returned_amount' => 0.0,
                        'committed_quantity' => 1.0,
                        'committed_amount' => 7100.0,
                        'last_allocation_reference' => 'POSR-PREV-01',
                    ],
                ],
            ],
        ]);
        // Update sale detail subtotal residual
        $fixture['saleDetail']->update([
            'quantity' => 11,
            'sub_total' => 77900.0,
        ]);

        // 1. Create form: select 1 unit. Cumulative = 2 units (14200 total), delta = 7100.
        $createTest = Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', $fixture['transaction']->code)
            ->call('lookup');

        $lineKey = PosReturnCreateForm::buildLineKey($createTest->get('snapshot.lines.0'));

        $createTest->call('updateResolution', $lineKey, PosReturnLine::RESOLUTION_CASH_RETURN)
            ->set("lineSelections.{$lineKey}.quantity", 1);

        $createTest->assertSee('7.100');

        // Now select remaining 11 units: cumulative = 12 (exhaustion of 85000), delta = 85000 - 7100 = 77900.
        $createTest->set("lineSelections.{$lineKey}.quantity", 11);
        $createTest->assertSee('77.900');

        // Submit full residual exhaustion
        $createTest->call('submit');
        $createTest->assertHasNoErrors();

        $savedReturn = PosReturn::first();
        $this->assertEquals(77900.0, (float) $savedReturn->total_amount);
        $this->assertEquals(77900.0, (float) $savedReturn->lines()->first()->line_total);

        // 2. Open that draft in PosReturnEditForm: should display Rp77.900 (excluding current draft from prior totals)
        $editTest = Livewire::test(PosReturnEditForm::class, ['return' => $savedReturn]);
        $editTest->assertSee('77.900');

        // Change quantity to 1 in edit form: should display Rp7.100
        $editTest->set("lineSelections.{$lineKey}.quantity", 1);
        $editTest->assertSee('7.100');

        $editTest->call('submit');
        $editTest->assertHasNoErrors();

        $savedReturn->refresh();
        $this->assertEquals(7100.0, (float) $savedReturn->total_amount);
        $this->assertEquals(7100.0, (float) $savedReturn->lines()->first()->line_total);
    }

    /** @test */
    public function bundle_scope_in_create_and_edit_forms_calculates_and_saves_matching_amounts(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $camera = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Camera Pro 4K',
            'product_code' => 'CAM-PRO',
            'sale_price' => 630000,
            'serial_number_required' => false,
        ]);

        $microSd = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'MicroSD 64GB',
            'product_code' => 'SD-PRO',
            'sale_price' => 260000,
            'serial_number_required' => false,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $camera->id,
            'setting_id' => $this->setting->id,
            'name' => 'Camera Pro Bundle',
            'bundle_sale_price' => 890000,
            'price' => 0,
        ]);

        $bundleItem = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $microSd->id,
            'quantity' => 1,
            'price' => 260000,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-BUNDLE-VAL',
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
            'grand_total' => 890000 * 3,
            'receipt_number' => 'RCP-BUNDLE-VAL',
            'idempotency_key' => 'IDEM-BUNDLE-VAL',
            'payload_hash' => 'HASH-BUNDLE-VAL',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 890000 * 3,
            'paid_amount' => 890000 * 3,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-BUNDLE-VAL',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 890000 * 3,
            'split_key' => 'SPLIT-BUNDLE-VAL',
            'tax_bucket' => 'NON_TAX',
        ]);

        $cameraDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $camera->id,
            'quantity' => 3,
            'price' => 630000,
            'unit_price' => 630000,
            'sub_total' => 630000 * 3,
            'product_name' => $camera->product_name,
            'product_code' => $camera->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $saleBundleItem = SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $cameraDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $microSd->id,
            'name' => $microSd->product_name,
            'quantity' => 3,
            'price' => 260000,
            'sub_total' => 260000 * 3,
        ]);

        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => 'APPROVED']);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $cameraDetail->id,
            'product_id' => $camera->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 3,
            'location_id' => $this->location->id,
        ]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $cameraDetail->id,
            'product_id' => $microSd->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 3,
            'location_id' => $this->location->id,
        ]);

        $ptl = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'line_no' => 1,
            'product_id' => $camera->id,
            'product_name_snapshot' => $camera->product_name,
            'product_code_snapshot' => $camera->product_code,
            'qty' => 3,
            'unit_price' => 890000,
            'subtotal' => 890000 * 3,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_name' => $bundle->name,
                'charged_total' => 890000 * 3,
                'bundle_items' => [
                    [
                        'product_id' => $microSd->id,
                        'product_name' => $microSd->product_name,
                        'product_code' => $microSd->product_code,
                        'quantity' => 1,
                        'allocated_price' => 260000,
                        'bundle_item_id' => $bundleItem->id,
                    ],
                ],
            ],
        ]);

        $cameraDetail->update(['pos_transaction_line_id' => $ptl->id]);

        // 1. Create form: test whole_bundle vs parent_only
        $createTest = Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', $transaction->code)
            ->call('lookup');

        $lineKey = PosReturnCreateForm::buildLineKey($createTest->get('snapshot.lines.0'));

        // Select 1 unit with parent_only
        $createTest->call('updateResolution', $lineKey, PosReturnLine::RESOLUTION_CASH_RETURN)
            ->set("lineSelections.{$lineKey}.quantity", 1)
            ->call('updateBundleScope', $lineKey, PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY);

        // Refund: 630.000, Retained: 260.000
        $createTest->assertSee('630.000')
            ->assertSee('260.000');

        $createTest->call('submit');
        $createTest->assertHasNoErrors();

        $savedReturn = PosReturn::first();
        $this->assertEquals(630000.0, (float) $savedReturn->total_amount);
        $this->assertEquals(630000.0, (float) $savedReturn->lines()->first()->line_total);

        // 2. Edit form: open draft
        $editTest = Livewire::test(PosReturnEditForm::class, ['return' => $savedReturn]);
        $editTest->assertSee('630.000')
            ->assertSee('260.000');

        // Update quantity to 2
        $editTest->set("lineSelections.{$lineKey}.quantity", 2);
        // Refund: 1.260.000, Retained: 520.000
        $editTest->assertSee('1.260.000')
            ->assertSee('520.000');

        $editTest->call('submit');
        $editTest->assertHasNoErrors();

        $savedReturn->refresh();
        $this->assertEquals(1260000.0, (float) $savedReturn->total_amount);
        $this->assertEquals(1260000.0, (float) $savedReturn->lines()->first()->line_total);
    }

    /** @test */
    public function whole_bundle_refund_calculates_and_saves_full_value_including_components(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $camera = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Camera Pro 4K',
            'product_code' => 'CAM-PRO-WB',
            'sale_price' => 630000,
            'serial_number_required' => false,
        ]);

        $microSd = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'MicroSD 64GB',
            'product_code' => 'SD-PRO-WB',
            'sale_price' => 260000,
            'serial_number_required' => false,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $camera->id,
            'setting_id' => $this->setting->id,
            'name' => 'CAMERA PRO BUNDLE WB',
            'bundle_sale_price' => 890000,
            'price' => 0,
        ]);

        $bundleItem = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $microSd->id,
            'quantity' => 1,
            'price' => 260000,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-BUNDLE-WB',
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
            'grand_total' => 890000 * 3,
            'receipt_number' => 'RCP-BUNDLE-WB',
            'idempotency_key' => 'IDEM-BUNDLE-WB',
            'payload_hash' => 'HASH-BUNDLE-WB',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 890000 * 3,
            'paid_amount' => 890000 * 3,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-BUNDLE-WB',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 890000 * 3,
            'split_key' => 'SPLIT-BUNDLE-WB',
            'tax_bucket' => 'NON_TAX',
        ]);

        $cameraDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $camera->id,
            'quantity' => 3,
            'price' => 630000,
            'unit_price' => 630000,
            'sub_total' => 630000 * 3,
            'product_name' => $camera->product_name,
            'product_code' => $camera->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $saleBundleItem = SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $cameraDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $microSd->id,
            'name' => $microSd->product_name,
            'quantity' => 3,
            'price' => 260000,
            'sub_total' => 260000 * 3,
        ]);

        $microSdDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $microSd->id,
            'quantity' => 3,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $microSd->product_name,
            'product_code' => $microSd->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => 'APPROVED']);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $cameraDetail->id,
            'product_id' => $camera->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 3,
            'location_id' => $this->location->id,
        ]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $microSdDetail->id,
            'product_id' => $microSd->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 3,
            'location_id' => $this->location->id,
        ]);

        $ptl = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'line_no' => 1,
            'product_id' => $camera->id,
            'product_name_snapshot' => $camera->product_name,
            'product_code_snapshot' => $camera->product_code,
            'qty' => 3,
            'unit_price' => 890000,
            'subtotal' => 890000 * 3,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_name' => $bundle->name,
                'charged_total' => 890000 * 3,
                'bundle_items' => [
                    [
                        'product_id' => $microSd->id,
                        'product_name' => $microSd->product_name,
                        'product_code' => $microSd->product_code,
                        'quantity' => 1,
                        'allocated_price' => 260000,
                        'bundle_item_id' => $bundleItem->id,
                    ],
                ],
            ],
        ]);

        $cameraDetail->update(['pos_transaction_line_id' => $ptl->id]);

        // 1. Create form: select all 3 whole bundles
        $createTest = Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', $transaction->code)
            ->call('lookup');

        $lineKey = PosReturnCreateForm::buildLineKey($createTest->get('snapshot.lines.0'));

        $createTest->call('updateResolution', $lineKey, PosReturnLine::RESOLUTION_CASH_RETURN)
            ->set("lineSelections.{$lineKey}.quantity", 3);

        // Expected: Rp2.670.000 — 3 x Rp890.000
        $createTest->assertSee('2.670.000');
        $this->assertEquals(2670000.0, $createTest->instance()->getLineEstimatedRefund($lineKey));
        $this->assertEquals(2670000.0, $createTest->instance()->valuation['total_cash_return']);

        $createTest->call('submit');
        $this->assertNull($createTest->get('error'), 'Submit failed with error: ' . $createTest->get('error'));
        $createTest->assertHasNoErrors();

        $savedReturn = PosReturn::latest('id')->first();
        $this->assertEquals(2670000.0, (float) $savedReturn->total_amount);

        // 2. Edit form: open draft and verify display and update
        $editTest = Livewire::test(PosReturnEditForm::class, ['return' => $savedReturn]);
        $editTest->assertSee('2.670.000');
        $this->assertEquals(2670000.0, $editTest->instance()->getLineEstimatedRefund($lineKey));
        $this->assertEquals(2670000.0, $editTest->instance()->valuation['total_cash_return']);

        // 3. Approval preview planning: planned cash amount covers all 3 whole bundles (2.670.000)
        $planner = app(\Modules\Pos\Services\PosReturnApprovalPreviewPlannerService::class);
        $preview = $planner->plan($savedReturn);
        $this->assertEquals(2670000.0, (float) $preview['actual_refund']);
    }
}
