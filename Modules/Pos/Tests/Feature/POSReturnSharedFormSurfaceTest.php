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
}