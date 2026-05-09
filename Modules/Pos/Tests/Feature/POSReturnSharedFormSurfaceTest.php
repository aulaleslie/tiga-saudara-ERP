<?php

namespace Modules\Pos\Tests\Feature;

use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Livewire\PosReturn\PosReturnCreateForm;
use Modules\Pos\Livewire\PosReturn\PosReturnEditForm;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Spatie\Permission\Models\Permission;

class POSReturnSharedFormSurfaceTest extends PosTransactionFeatureTestCase
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
        $this->setting = $this->createSetting('POS Return Shared Form Surface');

        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.edit', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Shared Form User', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.edit',
        ]);

        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function edit_preloads_draft_selections_and_shares_core_controls_with_create(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Shared Surface Product',
            'product_code' => 'SSF-001',
            'sale_price' => 100,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SHARED-SURFACE',
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
            'grand_total' => 1000,
            'receipt_number' => 'RCP-SHARED-SURFACE',
            'idempotency_key' => 'IDEM-SHARED-SURFACE',
            'payload_hash' => 'HASH-SHARED-SURFACE',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SHARED-SURFACE',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000,
            'split_key' => 'SPLIT-SHARED-SURFACE',
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [[
                'sale_id' => $sale->id,
                'sale_detail_id' => $saleDetail->id,
                'quantity' => 2,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ]],
        ]);

        $lineKey = (string) $saleDetail->id;

        Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', $transaction->code)
            ->call('lookup')
            ->assertHasNoErrors()
            ->assertSee('Informasi Tambahan')
            ->assertSee('Total Retur Tunai')
            ->assertSee('Tidak')
            ->assertSee('Tunai');

        Livewire::test(PosReturnEditForm::class, ['return' => $posReturn])
            ->assertHasNoErrors()
            ->assertSee('Informasi Tambahan')
            ->assertSee('Total Retur Tunai')
            ->assertSee('Tidak')
            ->assertSee('Tunai')
            ->assertSet("lineSelections.{$lineKey}.resolution", PosReturnLine::RESOLUTION_CASH_RETURN)
            ->assertSet("lineSelections.{$lineKey}.quantity", 2.0);
    }

    /** @test */
    public function rejected_returns_can_open_the_shared_edit_surface_with_existing_selections(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Rejected Surface Product',
            'product_code' => 'RSF-001',
            'sale_price' => 100,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-REJECTED-SURFACE',
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
            'grand_total' => 1000,
            'receipt_number' => 'RCP-REJECTED-SURFACE',
            'idempotency_key' => 'IDEM-REJECTED-SURFACE',
            'payload_hash' => 'HASH-REJECTED-SURFACE',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-REJECTED-SURFACE',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000,
            'split_key' => 'SPLIT-REJECTED-SURFACE',
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [[
                'sale_id' => $sale->id,
                'sale_detail_id' => $saleDetail->id,
                'quantity' => 2,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ]],
        ]);

        $posReturn->update([
            'status' => \Modules\Pos\Entities\PosReturn::STATUS_REJECTED,
            'approval_status' => \Modules\Pos\Entities\PosReturn::APPROVAL_STATUS_REJECTED,
            'rejected_by' => $this->user->id,
            'rejected_at' => now(),
            'rejection_reason' => 'Need correction',
        ]);

        $lineKey = (string) $saleDetail->id;

        Livewire::test(PosReturnEditForm::class, ['return' => $posReturn->fresh()])
            ->assertHasNoErrors()
            ->assertSee('Informasi Tambahan')
            ->assertSet("lineSelections.{$lineKey}.resolution", PosReturnLine::RESOLUTION_CASH_RETURN)
            ->assertSet("lineSelections.{$lineKey}.quantity", 2.0);
    }

    /** @test */
    public function edit_submit_preserves_explicit_serial_none_instead_of_reverting_to_header_return_option(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Shared Serial Product',
            'product_code' => 'SSF-SERIAL-01',
            'sale_price' => 250000,
            'serial_number_required' => true,
        ]);

        $serialA = $this->createSerialNumber($product, $this->location, 'SSF-SERIAL-A');
        $serialB = $this->createSerialNumber($product, $this->location, 'SSF-SERIAL-B');

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SHARED-SERIAL-SURFACE',
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
            'receipt_number' => 'RCP-SHARED-SERIAL-SURFACE',
            'idempotency_key' => 'IDEM-SHARED-SERIAL-SURFACE',
            'payload_hash' => 'HASH-SHARED-SERIAL-SURFACE',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SHARED-SERIAL-SURFACE',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 500000,
            'split_key' => 'SPLIT-SHARED-SERIAL-SURFACE',
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 250000,
            'unit_price' => 250000,
            'sub_total' => 500000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'serial_number_ids' => [$serialA->id, $serialB->id],
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => 'cash_return',
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $saleDetail->id,
                    'pos_transaction_line_id' => $snapshot['lines'][0]['pos_transaction_line_id'] ?? null,
                    'returned_serial_id' => $serialA->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
                [
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $saleDetail->id,
                    'pos_transaction_line_id' => $snapshot['lines'][1]['pos_transaction_line_id'] ?? null,
                    'returned_serial_id' => $serialB->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);

        $lineKeyA = ($snapshot['lines'][0]['pos_transaction_line_id'] ?? $saleDetail->id) . '-' . $serialA->id;

        Livewire::test(PosReturnEditForm::class, ['return' => $posReturn])
            ->assertHasNoErrors()
            ->set("lineSelections.{$lineKeyA}.resolution", PosReturnLine::RESOLUTION_NONE)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('pos.returns.show', $posReturn->id));

        $posReturn->refresh();
        $posReturn->load('lines');

        $this->assertSame(
            PosReturnLine::RESOLUTION_NONE,
            $posReturn->lines->firstWhere('returned_serial_id', $serialA->id)?->resolution
        );
        $this->assertSame(
            PosReturnLine::RESOLUTION_CASH_RETURN,
            $posReturn->lines->firstWhere('returned_serial_id', $serialB->id)?->resolution
        );
        $this->assertSame(250000.0, (float) $posReturn->total_amount);
    }

    /** @test */
    public function create_blocks_scanning_a_duplicate_replacement_serial_across_rows(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $fixture = $this->makeTrackedSerialFixture('CREATE');
        $replacement = $this->createSerialNumber($fixture['product'], $this->location, 'SSF-CREATE-REP-001');
        $lineKeyA = ($fixture['snapshot']['lines'][0]['pos_transaction_line_id'] ?? $fixture['saleDetail']->id) . '-' . $fixture['serialA']->id;
        $lineKeyB = ($fixture['snapshot']['lines'][1]['pos_transaction_line_id'] ?? $fixture['saleDetail']->id) . '-' . $fixture['serialB']->id;

        Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', $fixture['transaction']->code)
            ->call('lookup')
            ->set("lineSelections.{$lineKeyA}.resolution", PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
            ->set("lineSelections.{$lineKeyA}.replacement_serial_input", $replacement->serial_number)
            ->call('scanReplacementSerial', $lineKeyA)
            ->set("lineSelections.{$lineKeyB}.resolution", PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
            ->set("lineSelections.{$lineKeyB}.replacement_serial_input", $replacement->serial_number)
            ->call('scanReplacementSerial', $lineKeyB)
            ->assertHasErrors(["lineSelections.{$lineKeyB}.replacement_serial_input"])
            ->assertSee('Serial pengganti tidak boleh digunakan lebih dari satu kali');
    }

    /** @test */
    public function edit_blocks_scanning_a_duplicate_replacement_serial_across_rows(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $fixture = $this->makeTrackedSerialFixture('EDIT');
        $replacement = $this->createSerialNumber($fixture['product'], $this->location, 'SSF-EDIT-REP-001');

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'source_snapshot' => $fixture['snapshot'],
            'source_snapshot_hash' => $fixture['snapshot']['hash'],
            'lines' => [[
                'sale_id' => $fixture['sale']->id,
                'sale_detail_id' => $fixture['saleDetail']->id,
                'pos_transaction_line_id' => $fixture['snapshot']['lines'][0]['pos_transaction_line_id'] ?? null,
                'returned_serial_id' => $fixture['serialA']->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ]],
        ]);

        $lineKeyA = ($fixture['snapshot']['lines'][0]['pos_transaction_line_id'] ?? $fixture['saleDetail']->id) . '-' . $fixture['serialA']->id;
        $lineKeyB = ($fixture['snapshot']['lines'][1]['pos_transaction_line_id'] ?? $fixture['saleDetail']->id) . '-' . $fixture['serialB']->id;

        Livewire::test(PosReturnEditForm::class, ['return' => $posReturn])
            ->set("lineSelections.{$lineKeyA}.resolution", PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
            ->set("lineSelections.{$lineKeyA}.replacement_serial_input", $replacement->serial_number)
            ->call('scanReplacementSerial', $lineKeyA)
            ->set("lineSelections.{$lineKeyB}.resolution", PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
            ->set("lineSelections.{$lineKeyB}.replacement_serial_input", $replacement->serial_number)
            ->call('scanReplacementSerial', $lineKeyB)
            ->assertHasErrors(["lineSelections.{$lineKeyB}.replacement_serial_input"])
            ->assertSee('Serial pengganti tidak boleh digunakan lebih dari satu kali');
    }

    protected function makeTrackedSerialFixture(string $prefix): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Shared Serial Product ' . $prefix,
            'product_code' => 'SSF-' . $prefix . '-01',
            'sale_price' => 250000,
            'serial_number_required' => true,
        ]);

        $serialA = $this->createSerialNumber($product, $this->location, 'SSF-' . $prefix . '-A');
        $serialB = $this->createSerialNumber($product, $this->location, 'SSF-' . $prefix . '-B');

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SHARED-' . $prefix . '-SURFACE',
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
            'receipt_number' => 'RCP-SHARED-' . $prefix . '-SURFACE',
            'idempotency_key' => 'IDEM-SHARED-' . $prefix . '-SURFACE',
            'payload_hash' => 'HASH-SHARED-' . $prefix . '-SURFACE',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SHARED-' . $prefix . '-SURFACE',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 500000,
            'split_key' => 'SPLIT-SHARED-' . $prefix . '-SURFACE',
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 250000,
            'unit_price' => 250000,
            'sub_total' => 500000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'serial_number_ids' => [$serialA->id, $serialB->id],
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        return compact('product', 'serialA', 'serialB', 'transaction', 'sale', 'saleDetail', 'snapshot');
    }
}