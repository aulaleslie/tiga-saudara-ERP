<?php

namespace Modules\Consignment\Tests\Feature\Concurrency;

use App\Support\SalesLocationResolver;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Tests\Support\RunsBuyoutRaceWorkers;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The real POS checkout finalization request versus buyout approval over the same consignment bucket, as real OS processes
 * on the isolated MySQL database. Each POS worker builds its OWN session and cart before the synchronization barrier, so only
 * the finalize request itself is raced. Data is committed (the parent's RefreshDatabase transaction is replaced by truncation).
 *
 * @group mysql
 */
class ConsignmentBuyoutPosRaceMysqlTest extends PosTransactionFeatureTestCase
{
    use RunsBuyoutRaceWorkers;

    protected $user;       // the cashier, who also holds the buyout permissions
    protected $setting;
    private $terminal;
    private $standard;
    private $consignment;
    private $cash;
    private $customer;
    private $product;
    private $supplier;
    private ConsignmentReceivingDetail $receipt;

    /** Replaces RefreshDatabase's migrate+transaction: workers must see committed rows. */
    protected function refreshTestDatabase()
    {
        if (config('database.default') !== 'mysql_test') {
            $this->markTestSkipped('Requires the isolated MySQL test database (phpunit.mysql.xml / scripts/run-mysql-sequence-tests.sh).');
        }
        $this->truncateAll();
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql_test') {
            $this->truncateAll();
        }
        parent::tearDown();
    }

    private function arrange(): void
    {
        $this->truncateAll();
        $this->setUp2();
    }

    /** Parent::setUp already created currency + permissions for the first round; rebuild them after later truncations. */
    private function setUp2(): void
    {
        if (! \Modules\Currency\Entities\Currency::query()->exists()) {
            \Modules\Currency\Entities\Currency::create(['currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'Rp', 'thousand_separator' => '.',
                'decimal_separator' => ',', 'exchange_rate' => 1]);
        }
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['pos.access', 'pos.sell', 'pos.checkout.payment', 'pos.sessions.open', 'pos.sessions.view', 'pos.cart.clear', 'pos.cart.line.remove',
            'pos.cart.line.reduce', 'pos.transactions.view', 'pos.transactions.save', 'pos.transactions.load', 'pos.void'] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        $this->setting = $this->createSetting('Buyout POS Biz');
        $this->user = $this->createUserForSetting($this->setting, 'buyout pos cashier', ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        [$this->terminal, $this->standard] = $this->createTerminalWithLocation($this->setting);
        $this->consignment = Location::create(['name' => 'Konsinyasi', 'setting_id' => $this->setting->id, 'is_active' => true, 'is_consignment' => true]);
        SalesLocationResolver::forget($this->setting->id);

        $this->product = $this->createStockedProduct($this->setting, $this->standard, ['product_code' => 'BO-POS-1', 'stock_qty' => 2, 'sale_price' => 10000]);
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->consignment->id, 'quantity' => 5,
            'quantity_non_tax' => 5, 'quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity' => 0, 'tax_id' => null]);

        $coaId = DB::table('chart_of_accounts')->insertGetId(['name' => 'COA BO', 'account_number' => 'BO-CASH', 'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->cash = PaymentMethod::create(['name' => 'CASH BO', 'coa_id' => $coaId, 'is_cash' => true, 'requires_reference' => false]);
        DB::table('setting_pos_payment_methods')->insert(['setting_id' => $this->setting->id, 'payment_method_id' => $this->cash->id,
            'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        $this->setting->update(['pos_walk_in_customer_id' => $this->customer->id]);
        $this->openSession($this->setting, $this->terminal, $this->user);

        $this->supplier = Supplier::create(['setting_id' => $this->setting->id, 'supplier_name' => 'Sup', 'supplier_email' => 's@e.com',
            'supplier_phone' => '0811', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
        $receival = ConsignmentReceival::create(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'reference' => 'CR-P',
            'receival_number' => 'CR-P', 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => $this->setting->id, 'location_id' => $this->consignment->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => 'CR-P', 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $this->product->id,
            'product_name' => 'P', 'product_code' => 'P', 'quantity' => 5, 'unit_cost' => 100, 'unit_dpp' => 100,
            'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);
        $this->receipt = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $this->product->id, 'quantity_received' => 5, 'received_base_quantity' => 5, 'unit_cost' => 100, 'unit_dpp' => 100]);

        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.cancel',
            'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve'] as $p) {
            Permission::findOrCreate($p, 'web');
            $this->user->givePermissionTo($p);
        }
    }

    private function cfg(int $qty): string
    {
        return json_encode(['product_id' => $this->product->id, 'qty' => $qty, 'customer_id' => $this->customer->id,
            'payment_method_id' => $this->cash->id, 'amount' => 10000 * $qty]);
    }

    private function stockOf(Location $l): float
    {
        return (float) ProductStock::where('product_id', $this->product->id)->where('location_id', $l->id)->value('quantity');
    }

    private function submittedBuyout(string $qty)
    {
        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id, 'destination_location_id' => $this->standard->id,
            'base_quantity' => $qty, 'buckets' => ['NON_TAXED' => $qty]]], $this->user);

        return $svc->submit($b, $this->user);
    }

    /** @test negative control: the POS worker (own session + cart) really finalizes a checkout and moves stock */
    public function pos_checkout_alone_finalizes_in_a_worker(): void
    {
        $this->arrange();
        $results = $this->race([['pos', $this->cfg(4)]]);

        $this->assertSame(1, $this->ok($results), implode(' | ', $results));
        $this->assertSame(1, Dispatch::count(), 'checkout created its dispatch: ' . implode(' | ', $results));
        $this->assertSame(0.0, $this->stockOf($this->standard));
        $this->assertSame(3.0, $this->stockOf($this->consignment));
    }

    /** @test POS checkout versus buyout approval */
    public function pos_checkout_versus_buyout_approval_cannot_both_take_the_consignment_goods(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            $this->arrange();
            $b = $this->submittedBuyout('4');     // 5 on hand at consignment, 4 reserved => 1 sellable
            $results = $this->race([['approve', $b->id], ['pos', $this->cfg(4)]], $round % 2 ? [300, 0] : [0, 300]);

            $approved = $b->fresh()->status === 'APPROVED';
            $sold = Dispatch::count() > 0;
            $this->assertFalse($approved && $this->stockOf($this->consignment) < 4, 'reserved consignment goods were sold: ' . implode(' | ', $results));
            $this->assertTrue($approved || $sold, 'one of the two must have won: ' . implode(' | ', $results));
            if ($approved) {
                $this->assertSame(0, Dispatch::count(), 'a refused checkout leaves no dispatch');
                $this->assertSame(2.0, $this->stockOf($this->standard), 'refused checkout rolled back completely');
                $this->assertSame(5.0, $this->stockOf($this->consignment));
            } else {
                $this->assertSame(0, DB::table('consignment_buyout_reservations')->where('status', 'ACTIVE')->count(), 'the refused approval reserved nothing');
                $this->assertSame(3.0, $this->stockOf($this->consignment));
            }
            $outcomes[] = $approved ? 'approval-won' : 'checkout-won';
        }
        fwrite(STDERR, "\n[pos-vs-approval outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }
}
