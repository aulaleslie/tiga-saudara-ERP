<?php

namespace Modules\Pos\Tests\Feature\Support;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\ReturnSerialRegistration;
use Spatie\Permission\Models\Permission;

/** Shared POS unknown-serial registration fixtures (untracked 3-unit sale that now requires serials). */
trait BuildsPosSerialRegistrationFixture
{
    protected $setting;
    protected $location;
    protected $terminal;
    protected $session;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('POS Serial Registration');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        $perms = ['pos.returns.create', 'pos.returns.edit', 'pos.returns.approve', 'pos.returns.view', 'pos.returns.receive'];
        foreach ($perms as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->user = $this->createUserForSetting($this->setting, 'POS Registration User', array_merge(['pos.access'], $perms));
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
        $this->actingAsInSetting($this->user, $this->setting);
    }

    /** Untracked 3-unit POS sale; the product requires serials only AFTER the sale. */
    protected function fixture(bool $serializedHistory = false, bool $trackedNow = true): array
    {
        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id, 'code' => 'TXN-' . uniqid(), 'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id, 'owner_user_id' => $this->user->id, 'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);
        $checkout = PosCheckout::query()->create([
            'setting_id' => $this->setting->id, 'pos_transaction_id' => $transaction->id, 'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id, 'cashier_user_id' => $this->user->id, 'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 1800, 'receipt_number' => 'RCP-' . uniqid(), 'idempotency_key' => 'IDEM-' . uniqid(), 'payload_hash' => 'HASH-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'REG-' . uniqid(), 'product_name' => 'Registration Product', 'sale_price' => 600,
            'serial_number_required' => false, 'stock_qty' => 5,
        ]);
        $sale = Sale::query()->create([
            'setting_id' => $this->setting->id, 'customer_id' => null, 'customer_name' => 'Walk-in Customer', 'total_amount' => 1800,
            'paid_amount' => 1800, 'due_amount' => 0, 'date' => now()->toDateString(), 'status' => 'DISPATCHED',
            'payment_status' => 'PAID', 'payment_method' => 'CASH', 'reference' => 'SO-' . uniqid(),
        ]);
        PosCheckoutSale::query()->create([
            'pos_checkout_id' => $checkout->id, 'sale_id' => $sale->id, 'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id, 'grand_total' => 1800, 'split_key' => 'SPLIT-' . uniqid(), 'tax_bucket' => 'NON_TAX',
        ]);
        $saleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 3, 'price' => 600, 'unit_price' => 600, 'sub_total' => 1800,
            'product_name' => $product->product_name, 'product_code' => $product->product_code,
            'product_discount_amount' => 0, 'product_tax_amount' => 0,
        ]);
        $dispatch = Dispatch::query()->create(['sale_id' => $sale->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        $dispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'dispatched_quantity' => 3, 'location_id' => $this->location->id,
        ]);

        $serials = [];
        if ($serializedHistory) {
            foreach (['ORIG-1', 'ORIG-2', 'ORIG-3'] as $text) {
                $serial = $this->createSerialNumber($product, $this->location, $text);
                $serial->update(['status' => ProductSerialNumber::STATUS_SOLD, 'dispatch_detail_id' => $dispatchDetail->id]);
                $serials[] = $serial;
            }
            $saleDetail->update(['serial_number_ids' => collect($serials)->pluck('id')->all()]);
        }

        if ($trackedNow) {
            $product->update(['serial_number_required' => true]);
        }

        return compact('transaction', 'checkout', 'product', 'sale', 'saleDetail', 'dispatchDetail', 'serials');
    }

    protected function snapshot(array $f): array
    {
        return app(PosReturnSnapshotService::class)->build($f['transaction']->id);
    }

    protected function pendingLine(array $f, string $serial, string $resolution = PosReturnLine::RESOLUTION_CASH_RETURN, array $extra = []): array
    {
        return array_merge([
            'sale_detail_id' => $f['saleDetail']->id, 'sale_id' => $f['sale']->id, 'returned_serial_id' => null,
            'pending_serial' => $serial, 'resolution' => $resolution, 'quantity' => 1,
        ], $extra);
    }

    protected function store(array $f, array $lines)
    {
        $snapshot = $this->snapshot($f);

        return app(PosReturnSubmissionService::class)->store([
            'pos_transaction_id' => $f['transaction']->id, 'source_snapshot' => $snapshot, 'source_snapshot_hash' => $snapshot['hash'], 'lines' => $lines,
        ]);
    }

    protected function submitted(array $f, array $lines): PosReturn
    {
        return app(PosReturnSubmissionService::class)->submitDraftForApproval($this->store($f, $lines));
    }

    protected function approve(PosReturn $return): PosReturn
    {
        $this->get(route('pos.returns.approval-preview', $return))->assertOk();
        $this->post(route('pos.returns.approve', $return));

        return $return->fresh();
    }
}
