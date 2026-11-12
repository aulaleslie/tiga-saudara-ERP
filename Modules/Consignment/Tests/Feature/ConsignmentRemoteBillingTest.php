<?php

namespace Modules\Consignment\Tests\Feature;

use Modules\Consignment\Entities\ConsignmentObligationBinding;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService;
use Modules\Consignment\Services\ConsignmentBillingConversionService;
use Modules\Consignment\Tests\Support\BuildsRemoteProvenanceFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4: the original receipt business bills the supplier for a remote (transferred) sale.
 * The Purchase, sequence and invoice policy stay original-business scoped; the remote sale is untouched.
 */
class ConsignmentRemoteBillingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsRemoteProvenanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteFixtures();
    }

    private function remoteSoldSerial(): ConsignmentSoldSource
    {
        $this->moveSerial($this->sn[0], $this->ca, $this->cb);
        $dd = $this->sellAt($this->cb, [$this->sn[0]]);
        app(\Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($this->businessA->id);

        return ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();
    }

    private function lines(ConsignmentSoldSource $src): array
    {
        return [[
            'consignment_sold_source_id' => $src->id,
            'allocated_base_quantity' => 1,
            'receipt_allocations' => [['consignment_receiving_detail_id' => $this->receipt->id, 'allocated_base_quantity' => 1]],
            'serialized_allocations' => [['product_serial_number_id' => $this->sn[0]->id, 'consignment_receiving_detail_id' => $this->receipt->id]],
        ]];
    }

    /** @test */
    public function origin_bills_a_remote_sale_into_one_original_business_purchase(): void
    {
        $src = $this->remoteSoldSerial();
        $sale = \DB::table('sales')->where('id', $src->sale_id)->first();
        $life = app(ConsignmentBillingConfirmationLifecycleService::class);

        $c = $life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), $this->lines($src), null, $this->actor->id);
        $c = $life->submitConfirmation($c, $this->actor->id);
        $c = $life->approveConfirmation($c->fresh(), $this->actor->id);
        $this->assertSame('APPROVED', $c->fresh()->status);

        $purchase = app(ConsignmentBillingConversionService::class)->convert($c->id, $this->businessA->id, $this->actor->id,
            ['supplier_invoice_number' => 'CANDRA-1', 'invoice_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString()]);

        $this->assertSame((int) $this->businessA->id, (int) $purchase->setting_id, 'supplier Purchase belongs to the original receipt business');
        $this->assertSame((int) $this->supplier->id, (int) $purchase->supplier_id);
        $this->assertEquals($sale, \DB::table('sales')->where('id', $src->sale_id)->first(), 'remote sale unchanged');
        $this->assertSame((int) $this->businessB->id, (int) $src->fresh()->setting_id);

        // The same remote share cannot be billed twice.
        $this->expectException(\Throwable::class);
        $c2 = $life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), $this->lines($src), null, $this->actor->id);
        $life->submitConfirmation($c2, $this->actor->id);
    }

    /** @test */
    public function the_remote_business_cannot_bill_the_origin_receipt_and_an_unbound_source_is_refused(): void
    {
        $src = $this->remoteSoldSerial();
        $life = app(ConsignmentBillingConfirmationLifecycleService::class);

        try {
            $life->submitConfirmation($life->createDraft($this->businessB->id, $this->supplier->id, now()->toDateString(), $this->lines($src), null, $this->actor->id), $this->actor->id);
            $this->fail('remote business billed the origin receipt');
        } catch (\Throwable $e) {
        }

        ConsignmentObligationBinding::query()->update(['status' => 'SUPERSEDED']);
        $this->expectException(\InvalidArgumentException::class);
        $life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), $this->lines($src), null, $this->actor->id);
    }

    private function billMixed(): array
    {
        $remote = $this->remoteSoldSerial();
        $ddLocal = $this->sellAt($this->ca, [$this->sn[2]]);
        app(\Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($this->businessA->id);
        $local = ConsignmentSoldSource::where('dispatch_detail_id', $ddLocal->id)->firstOrFail();
        $line = fn ($src, $sn) => ['consignment_sold_source_id' => $src->id, 'allocated_base_quantity' => 1,
            'receipt_allocations' => [['consignment_receiving_detail_id' => $this->receipt->id, 'allocated_base_quantity' => 1]],
            'serialized_allocations' => [['product_serial_number_id' => $sn->id, 'consignment_receiving_detail_id' => $this->receipt->id]]];
        $life = app(ConsignmentBillingConfirmationLifecycleService::class);
        $c = $life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [$line($remote, $this->sn[0]), $line($local, $this->sn[2])], null, $this->actor->id);
        $c = $life->approveConfirmation($life->submitConfirmation($c, $this->actor->id)->fresh(), $this->actor->id);
        $purchase = app(ConsignmentBillingConversionService::class)->convert($c->id, $this->businessA->id, $this->actor->id,
            ['supplier_invoice_number' => 'CANDRA-MIX', 'invoice_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString()]);

        return [$purchase, $remote, $local];
    }

    /** @test */
    public function mixed_local_and_remote_sales_make_one_purchase_without_duplicate_charge(): void
    {
        [$purchase] = $this->billMixed();
        $this->assertSame(1, \DB::table('purchases')->count());
        $this->assertEquals(2.0, (float) \DB::table('purchase_details')->where('purchase_id', $purchase->id)->sum('quantity'));
        $this->assertSame((int) $this->businessA->id, (int) $purchase->setting_id);
        $this->assertSame(0, \DB::table('purchase_payments')->count(), 'no internal payment');
        $this->assertSame(0.0, app(\Modules\Consignment\Services\ConsignmentBuyoutCapacityService::class)->receiptCapacity($this->receipt->id)['financial_available'] - 1.0,
            'receipt 3 - 2 billed = 1 left once');
    }

    /** @test */
    public function supplier_facing_outputs_identify_the_origin_business_only(): void
    {
        [$purchase, $remote] = $this->billMixed();
        $saleRef = \DB::table('sales')->where('id', $remote->sale_id)->value('reference');
        $customer = \DB::table('sales')->where('id', $remote->sale_id)->value('customer_name');

        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);
        foreach (collect(require base_path('app/Config/Permissions.php'))->flatMap(fn ($g) => array_keys($g)) as $perm) {
            \Spatie\Permission\Models\Permission::findOrCreate($perm, 'web');
        }
        foreach (['purchases.show', 'purchases.access', 'purchase_payments.access'] as $perm) {
            \Spatie\Permission\Models\Permission::findOrCreate($perm, 'web');
            $this->actor->givePermissionTo($perm);
        }
        $this->actor->settings()->syncWithoutDetaching([$this->businessA->id => ['role_id' => \Spatie\Permission\Models\Role::findOrCreate('PV', 'web')->id]]);

        $pages = [];
        foreach ([route('purchases.show', $purchase->id), route('purchase-payments.index', $purchase->id)] as $url) {
            $r = $this->actingAs($this->actor)->withSession(['setting_id' => $this->businessA->id])->get($url);
            if ($r->status() === 200) {
                $pages[$url] = $r->getContent();
            }
        }
        $this->assertNotEmpty($pages, 'at least the purchase show page renders');
        foreach ($pages as $url => $html) {
            foreach ([$this->businessB->company_name, $this->cb->name, $saleRef, $customer] as $foreign) {
                $this->assertStringNotContainsString((string) $foreign, $html, "{$url} leaks {$foreign}");
            }
        }
        $this->assertStringContainsString($this->receipt->receiving->receiving_number, $pages[route('purchases.show', $purchase->id)] ?? '');
    }

    private function staff(array $perms, array $settings): \App\Models\User
    {
        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);
        foreach (collect(require base_path('app/Config/Permissions.php'))->flatMap(fn ($g) => array_keys($g)) as $perm) {
            \Spatie\Permission\Models\Permission::findOrCreate($perm, 'web');
        }
        $u = \App\Models\User::factory()->create();
        $u->givePermissionTo($perms);
        foreach ($settings as $s) {
            $u->settings()->attach($s->id, ['role_id' => \Spatie\Permission\Models\Role::findOrCreate('ST', 'web')->id]);
        }

        return $u;
    }

    /** @test */
    public function origin_reconciliation_staff_see_relevant_remote_provenance_but_not_the_remote_sale(): void
    {
        $src = $this->remoteSoldSerial();
        $saleRef = \DB::table('sales')->where('id', $src->sale_id)->value('reference');

        $reconciler = $this->staff(['consignments.allocations.access', 'consignments.provenance.reconcile', 'sales.show'], [$this->businessA]);
        $html = $this->actingAs($reconciler)->withSession(['setting_id' => $this->businessA->id])
            ->get(route('consignments.sold-sources.index'))->assertOk()->getContent();
        $this->assertStringContainsString($saleRef, $html);
        $this->assertStringContainsString('Penjualan bisnis lain (transfer)', $html);

        // No general remote sales/customer access is granted by the provenance view.
        $status = $this->actingAs($reconciler)->withSession(['setting_id' => $this->businessA->id])
            ->get(route('sales.show', $src->sale_id))->status();
        $this->assertContains($status, [403, 404]);

        $plain = $this->staff(['consignments.allocations.access'], [$this->businessA]);
        $html = $this->actingAs($plain)->withSession(['setting_id' => $this->businessA->id])
            ->get(route('consignments.sold-sources.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString($saleRef, $html, 'without the reconcile permission no foreign envelope is listed');
    }

    /** @test */
    public function web_discovery_reaches_remote_custody_only_with_the_reconcile_permission(): void
    {
        $this->moveSerial($this->sn[0], $this->ca, $this->cb);
        $dd = $this->sellAt($this->cb, [$this->sn[0]]);

        $plain = $this->staff(['consignments.allocations.access', 'consignments.allocations.create'], [$this->businessA]);
        $this->actingAs($plain)->withSession(['setting_id' => $this->businessA->id])->post(route('consignments.sold-sources.discover'));
        $this->assertFalse(ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->exists());

        $rec = $this->staff(['consignments.allocations.access', 'consignments.allocations.create', 'consignments.provenance.reconcile'], [$this->businessA]);
        $this->actingAs($rec)->withSession(['setting_id' => $this->businessA->id])->post(route('consignments.sold-sources.discover'));
        $this->assertTrue(ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->exists());
    }

    /** @test */
    public function an_unacquired_remote_customer_return_reduces_origin_eligibility_once_and_restores_remote_custody(): void
    {
        $src = $this->remoteSoldSerial();
        $sale = \DB::table('sales')->where('id', $src->sale_id)->first();
        $return = \Modules\SalesReturn\Entities\SaleReturn::create(['date' => now(), 'sale_id' => $sale->id, 'sale_reference' => $sale->reference,
            'customer_id' => $sale->customer_id, 'customer_name' => $sale->customer_name, 'setting_id' => $this->businessB->id, 'location_id' => $this->cb->id,
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0, 'total_amount' => 1,
            'paid_amount' => 0, 'due_amount' => 1, 'status' => 'Awaiting Settlement', 'approval_status' => 'approved', 'payment_status' => 'Unpaid', 'payment_method' => 'Pending']);
        \Modules\SalesReturn\Entities\SaleReturnDetail::create(['sale_return_id' => $return->id, 'dispatch_detail_id' => $src->dispatch_detail_id,
            'location_id' => $this->cb->id, 'product_id' => $this->serialized->id, 'product_name' => 'H', 'product_code' => 'H', 'quantity' => 1,
            'unit_price' => 1, 'price' => 1, 'sub_total' => 1, 'product_discount_amount' => 0, 'product_tax_amount' => 0, 'serial_number_ids' => [$this->sn[0]->id]]);

        $cap = app(\Modules\Consignment\Services\ConsignmentBuyoutCapacityService::class);
        $this->assertSame([], $cap->soldAttribution($src->fresh())['per_receipt'], 'returned before billing: nothing left to bill');
        $this->assertEqualsWithDelta(0.0, $cap->soldReceiptResidual($src->fresh(), $this->receipt->id), 0.001);
        $proj = app(\Modules\Consignment\Services\ConsignmentTransferProvenanceService::class)->project($this->receipt->fresh());
        $this->assertEqualsWithDelta(0.0, $proj['sold'], 0.001);
        $this->assertTrue($proj['conserved']);
        $this->assertEqualsWithDelta(1.0, (new \Modules\Consignment\Services\ConsignmentCustodyProjection)->quantityAt($proj, 'SELLABLE', $this->cb->id), 0.001,
            'back in supplier custody at the real return location (held/inspection policy still applies to selection)');
        $this->assertSame((int) $this->businessB->id, (int) $return->fresh()->setting_id, 'the return stays with the selling business');
    }
}
