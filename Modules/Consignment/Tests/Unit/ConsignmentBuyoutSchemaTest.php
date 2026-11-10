<?php

namespace Modules\Consignment\Tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutAuditEvent;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentInventoryCoordination;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ConsignmentBuyoutSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Supplier $supplier;
    protected Product $product;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Buyout Schema Test',
            'company_email' => 'test@example.com',
            'company_phone' => '08123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
            'document_prefix' => 'CSG',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Beta',
            'supplier_email' => 'beta@example.com',
            'supplier_phone' => '081111112',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor St',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Rack B',
            'is_consignment' => true,
        ]);

        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pieces',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Widget Schema',
            'product_code' => 'W-BO',
            'product_unit' => $unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 10,
        ]);
    }

    private function makeReceipt(Supplier $supplier, string $ref, string $qty = '10.500'): ConsignmentReceivingDetail
    {
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id, 'supplier_id' => $supplier->id, 'reference' => $ref,
            'receival_number' => $ref, 'status' => 'APPROVED', 'date' => now(),
        ]);
        $receiving = ConsignmentReceiving::create([
            'setting_id' => $this->setting->id, 'location_id' => $this->location->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => $ref,
            'status' => ConsignmentReceiving::STATUS_APPROVED, 'date' => now(),
        ]);
        $line = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id, 'product_id' => $this->product->id,
            'product_name' => 'W', 'product_code' => 'W', 'quantity' => $qty, 'unit_cost' => 100,
            'unit_dpp' => 100, 'subtotal_cost' => 1050, 'subtotal_dpp' => 1050, 'total_cost' => 1050, 'total_dpp' => 1050,
        ]);

        return ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $this->product->id, 'quantity_received' => $qty, 'received_base_quantity' => $qty,
            'unit_cost' => 100, 'unit_dpp' => 100,
        ]);
    }

    private function makeBuyout(string $ref = 'BO-001'): ConsignmentBuyout
    {
        return ConsignmentBuyout::create([
            'setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id,
            'reference' => $ref, 'status' => ConsignmentBuyout::STATUS_DRAFT, 'date' => now(),
        ]);
    }

    private function lineAttrs(ConsignmentBuyout $b, ConsignmentReceivingDetail $d, string $qty): array
    {
        return [
            'consignment_buyout_id' => $b->id, 'line_type' => ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT,
            'consignment_receiving_detail_id' => $d->id, 'product_id' => $this->product->id,
            'source_location_id' => $this->location->id, 'base_quantity' => $qty, 'original_snapshot' => ['k' => 1],
        ];
    }

    /** @test */
    public function it_creates_all_buyout_tables_and_claim_owner_columns()
    {
        foreach (['consignment_buyouts', 'consignment_buyout_lines', 'consignment_buyout_serials', 'consignment_buyout_audit_events',
            'consignment_sold_receipt_attributions', 'consignment_buyout_reservations', 'consignment_buyout_purchase_lineages',
            'consignment_buyout_movements', 'consignment_buyout_cost_events', 'consignment_buyout_ownership_events',
            'consignment_buyout_reversals', 'consignment_inventory_coordinations'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumns('consignment_active_serial_claims', ['owner_type', 'consignment_buyout_id', 'consignment_buyout_serial_id']));
    }

    /** @test */
    public function it_stores_fractional_quantities_and_enforces_receipt_identity()
    {
        $buyout = $this->makeBuyout();
        $detail = $this->makeReceipt($this->supplier, 'CR-BO-1');

        $line = ConsignmentBuyoutLine::create($this->lineAttrs($buyout, $detail, '1.250'));
        $this->assertSame('1.250', $line->fresh()->base_quantity);

        $other = Supplier::create([
            'setting_id' => $this->setting->id, 'supplier_name' => 'Other', 'supplier_email' => 'o@example.com',
            'supplier_phone' => '082', 'city' => 'J', 'country' => 'ID', 'address' => 'A',
        ]);
        $foreign = $this->makeReceipt($other, 'CR-BO-2');
        $this->expectException(\DomainException::class);
        ConsignmentBuyoutLine::create($this->lineAttrs($buyout, $foreign, '1.000'));
    }

    /** @test */
    public function it_rejects_non_positive_quantity_and_sold_line_without_source()
    {
        $buyout = $this->makeBuyout();
        $detail = $this->makeReceipt($this->supplier, 'CR-BO-3');

        try {
            ConsignmentBuyoutLine::create($this->lineAttrs($buyout, $detail, '0.000'));
            $this->fail('zero quantity accepted');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('positive', $e->getMessage());
        }

        $this->expectException(\DomainException::class);
        ConsignmentBuyoutLine::create(['line_type' => ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED] + $this->lineAttrs($buyout, $detail, '1.000'));
    }

    /** @test */
    public function it_rejects_duplicate_reference()
    {
        $this->makeBuyout('BO-DUP');
        $this->expectException(QueryException::class);
        $this->makeBuyout('BO-DUP');
    }

    /** @test */
    public function database_rejects_duplicate_unsold_slice_despite_null_sold_source()
    {
        $buyout = $this->makeBuyout('BO-SLICE');
        $detail = $this->makeReceipt($this->supplier, 'CR-BO-5');
        ConsignmentBuyoutLine::create($this->lineAttrs($buyout, $detail, '1.000'));

        $this->expectException(QueryException::class);
        ConsignmentBuyoutLine::create($this->lineAttrs($buyout, $detail, '2.000'));
    }

    /** @test */
    public function database_rejects_duplicate_non_serialized_ownership_event()
    {
        $buyout = $this->makeBuyout('BO-OWN');
        $detail = $this->makeReceipt($this->supplier, 'CR-BO-6');
        $line = ConsignmentBuyoutLine::create($this->lineAttrs($buyout, $detail, '1.000'));
        $row = [
            'consignment_buyout_id' => $buyout->id, 'consignment_buyout_line_id' => $line->id,
            'consignment_receiving_detail_id' => $detail->id, 'kind' => 'ACQUISITION',
            'base_quantity' => '1.000', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
        \DB::table('consignment_buyout_ownership_events')->insert($row);

        try {
            \DB::table('consignment_buyout_ownership_events')->insert($row);
            $this->fail('duplicate non-serialized ownership event accepted');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }
        // A reversal event for the same slice is a different kind and stays allowed.
        \DB::table('consignment_buyout_ownership_events')->insert(['kind' => 'REVERSAL'] + $row);
        $this->assertSame(2, \DB::table('consignment_buyout_ownership_events')->count());
    }

    /** @test */
    public function it_keeps_audit_events_append_only()
    {
        $buyout = $this->makeBuyout('BO-AUD');
        $event = ConsignmentBuyoutAuditEvent::create(['consignment_buyout_id' => $buyout->id, 'action' => 'CREATE', 'to_status' => 'DRAFT']);

        $this->expectException(\DomainException::class);
        $event->update(['action' => 'TAMPER']);
    }

    private function makeSerial(string $text, ConsignmentReceivingDetail $detail): \Modules\Product\Entities\ProductSerialNumber
    {
        return \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $this->product->id, 'location_id' => $this->location->id, 'serial_number' => $text,
            'consignment_receiving_detail_id' => $detail->id,
        ]);
    }

    private function makeBuyoutSerial(ConsignmentBuyout $b, ConsignmentBuyoutLine $line, $serial, $detail)
    {
        return \Modules\Consignment\Entities\ConsignmentBuyoutSerial::create([
            'consignment_buyout_id' => $b->id, 'consignment_buyout_line_id' => $line->id,
            'product_serial_number_id' => $serial->id, 'consignment_receiving_detail_id' => $detail->id,
        ]);
    }

    /** Builds a complete legacy claim (confirmation + allocation) for the given serial. */
    private function makeLegacyClaim($serial, ConsignmentReceivingDetail $detail, string $ref = 'L1'): ConsignmentActiveSerialClaim
    {
        $fx = $this->legacyChain($serial, $detail, $ref);
        return ConsignmentActiveSerialClaim::create([
            'product_serial_number_id' => $serial->id,
            'consignment_billing_confirmation_id' => $fx['confirmation']->id,
            'consignment_serialized_allocation_id' => $fx['allocation']->id,
        ]);
    }

    private function legacyChain($serial, ConsignmentReceivingDetail $detail, string $ref): array
    {
        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting->id, 'customer_name' => 'C' . $ref, 'customer_email' => $ref . '@e.com',
            'customer_phone' => '081', 'city' => 'J', 'country' => 'ID', 'address' => 'A',
        ]);
        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(), 'customer_id' => $customer->id, 'customer_name' => 'C' . $ref, 'tax_percentage' => 0,
            'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0,
            'total_amount' => 1, 'paid_amount' => 1, 'due_amount' => 0, 'status' => 'Completed',
            'payment_status' => 'Paid', 'payment_method' => 'Cash', 'setting_id' => $this->setting->id,
        ]);
        $dispatch = \Modules\Sale\Entities\Dispatch::create(['sale_id' => $sale->id, 'status' => 'APPROVED']);
        $dd = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id, 'sale_id' => $sale->id, 'product_id' => $this->product->id,
            'location_id' => $this->location->id, 'dispatched_quantity' => 1,
        ]);
        $source = \Modules\Consignment\Entities\ConsignmentSoldSource::create([
            'setting_id' => $this->setting->id, 'dispatch_detail_id' => $dd->id, 'sale_id' => $sale->id,
            'product_id' => $this->product->id, 'location_id' => $this->location->id, 'original_base_quantity' => 1,
            'dispatched_at' => now(), 'source_hash' => 'h' . $ref, 'source_snapshot' => [],
        ]);
        $confirmation = \Modules\Consignment\Entities\ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'confirmation_number' => 'CBC-' . $ref,
            'status' => 'WAITING_APPROVAL', 'date' => now(),
        ]);
        $line = \Modules\Consignment\Entities\ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confirmation->id, 'consignment_sold_source_id' => $source->id,
            'product_id' => $this->product->id, 'location_id' => $this->location->id, 'allocated_base_quantity' => 1,
        ]);
        $allocation = \Modules\Consignment\Entities\ConsignmentSerializedAllocation::create([
            'consignment_billing_confirmation_id' => $confirmation->id, 'consignment_billing_confirmation_line_id' => $line->id,
            'consignment_sold_source_id' => $source->id, 'product_serial_number_id' => $serial->id,
            'consignment_receiving_detail_id' => $detail->id, 'status' => 'RESERVED',
        ]);

        return compact('confirmation', 'allocation');
    }

    /** @test */
    public function serial_claims_require_exactly_one_complete_consistent_owner()
    {
        $detail = $this->makeReceipt($this->supplier, 'CR-BO-7');
        $serial = $this->makeSerial('SN-1', $detail);
        $other = $this->makeSerial('SN-2', $detail);
        $buyout = $this->makeBuyout('BO-CL');
        $line = ConsignmentBuyoutLine::create($this->lineAttrs($buyout, $detail, '2.000'));
        $bSerial = $this->makeBuyoutSerial($buyout, $line, $serial, $detail);
        $legacyChain = $this->legacyChain($other, $detail, 'X');

        $invalid = [
            'ownerless' => ['product_serial_number_id' => $serial->id],
            'legacy missing allocation' => ['product_serial_number_id' => $serial->id, 'consignment_billing_confirmation_id' => $legacyChain['confirmation']->id],
            'both owners' => [
                'product_serial_number_id' => $other->id,
                'consignment_billing_confirmation_id' => $legacyChain['confirmation']->id,
                'consignment_serialized_allocation_id' => $legacyChain['allocation']->id,
                'consignment_buyout_id' => $buyout->id, 'consignment_buyout_serial_id' => $bSerial->id,
            ],
            'buyout half owner' => ['owner_type' => 'BUYOUT', 'product_serial_number_id' => $serial->id, 'consignment_buyout_id' => $buyout->id],
            'buyout serial of another serial' => [
                'owner_type' => 'BUYOUT', 'product_serial_number_id' => $other->id,
                'consignment_buyout_id' => $buyout->id, 'consignment_buyout_serial_id' => $bSerial->id,
            ],
            'legacy allocation of another serial' => [
                'product_serial_number_id' => $serial->id,
                'consignment_billing_confirmation_id' => $legacyChain['confirmation']->id,
                'consignment_serialized_allocation_id' => $legacyChain['allocation']->id,
            ],
        ];
        foreach ($invalid as $label => $attrs) {
            try {
                ConsignmentActiveSerialClaim::create($attrs);
                $this->fail("Invalid claim accepted: {$label}");
            } catch (\DomainException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, ConsignmentActiveSerialClaim::count());

        // The database itself rejects bad shapes even when Eloquent is bypassed.
        foreach ([
            ['owner_type' => 'LEGACY'],
            ['owner_type' => 'BUYOUT', 'consignment_buyout_id' => $buyout->id, 'consignment_buyout_serial_id' => $bSerial->id,
                'consignment_billing_confirmation_id' => $legacyChain['confirmation']->id, 'consignment_serialized_allocation_id' => $legacyChain['allocation']->id],
            ['owner_type' => 'OTHER'],
        ] as $raw) {
            try {
                \DB::table('consignment_active_serial_claims')->insert(['product_serial_number_id' => $serial->id, 'created_at' => now(), 'updated_at' => now()] + $raw);
                $this->fail('database accepted invalid claim shape');
            } catch (QueryException $e) {
                $this->assertTrue(true);
            }
        }
    }

    /** @test */
    public function competing_claims_for_one_serial_are_rejected_across_owner_types()
    {
        $detail = $this->makeReceipt($this->supplier, 'CR-BO-8');
        $serial = $this->makeSerial('SN-9', $detail);
        $buyout = $this->makeBuyout('BO-CMP');
        $line = ConsignmentBuyoutLine::create($this->lineAttrs($buyout, $detail, '1.000'));
        $bSerial = $this->makeBuyoutSerial($buyout, $line, $serial, $detail);

        $this->makeLegacyClaim($serial, $detail, 'CMP');

        $this->expectException(QueryException::class);
        ConsignmentActiveSerialClaim::create([
            'owner_type' => 'BUYOUT', 'product_serial_number_id' => $serial->id,
            'consignment_buyout_id' => $buyout->id, 'consignment_buyout_serial_id' => $bSerial->id,
        ]);
    }

    /** @test */
    public function duplicate_buyout_claims_for_one_buyout_serial_are_rejected()
    {
        $detail = $this->makeReceipt($this->supplier, 'CR-BO-9');
        $serial = $this->makeSerial('SN-10', $detail);
        $buyout = $this->makeBuyout('BO-DC');
        $line = ConsignmentBuyoutLine::create($this->lineAttrs($buyout, $detail, '1.000'));
        $bSerial = $this->makeBuyoutSerial($buyout, $line, $serial, $detail);
        $attrs = ['owner_type' => 'BUYOUT', 'product_serial_number_id' => $serial->id,
            'consignment_buyout_id' => $buyout->id, 'consignment_buyout_serial_id' => $bSerial->id];
        ConsignmentActiveSerialClaim::create($attrs);

        $this->expectException(QueryException::class);
        ConsignmentActiveSerialClaim::create($attrs);
    }

    /** @test */
    public function legacy_claims_survive_the_claims_upgrade()
    {
        $detail = $this->makeReceipt($this->supplier, 'CR-BO-10');
        $serial = $this->makeSerial('SN-UP', $detail);
        $claim = $this->makeLegacyClaim($serial, $detail, 'UP');
        $original = $claim->only(['product_serial_number_id', 'consignment_billing_confirmation_id', 'consignment_serialized_allocation_id']);

        // Roll the posting-evidence migration back (empty buyout evidence) and replay it over the legacy row.
        $m2 = require base_path('Modules/Consignment/Database/Migrations/2026_11_10_000002_create_consignment_buyout_posting_evidence_tables.php');
        $m2->down();
        $this->assertFalse(Schema::hasColumn('consignment_active_serial_claims', 'owner_type'));
        $this->assertSame(1, \DB::table('consignment_active_serial_claims')->count());
        $m2->up();

        $upgraded = ConsignmentActiveSerialClaim::firstOrFail();
        $this->assertSame('LEGACY', $upgraded->owner_type);
        $this->assertSame($original, $upgraded->only(array_keys($original)));
        $this->assertNull($upgraded->consignment_buyout_id);

        // The upgraded table still rejects a competing buyout claim on the same serial.
        $buyout = $this->makeBuyout('BO-UP');
        $line = ConsignmentBuyoutLine::create($this->lineAttrs($buyout, $detail, '1.000'));
        $bSerial = $this->makeBuyoutSerial($buyout, $line, $serial, $detail);
        $this->expectException(QueryException::class);
        ConsignmentActiveSerialClaim::create(['owner_type' => 'BUYOUT', 'product_serial_number_id' => $serial->id,
            'consignment_buyout_id' => $buyout->id, 'consignment_buyout_serial_id' => $bSerial->id]);
    }

    /** @test */
    public function coordination_lock_requires_an_active_transaction()
    {
        // RefreshDatabase wraps tests in a transaction; unwrap temporarily to prove the guard.
        $level = \DB::transactionLevel();
        for ($i = 0; $i < $level; $i++) {
            \DB::rollBack();
        }
        try {
            ConsignmentInventoryCoordination::lockFor($this->setting->id, $this->product->id);
            $this->fail('lock outside transaction accepted');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('transaction', $e->getMessage());
        } finally {
            for ($i = 0; $i < $level; $i++) {
                \DB::beginTransaction();
            }
        }
    }

    /** @test */
    public function coordination_rows_are_created_once_and_locked_in_sorted_order()
    {
        $second = Product::create([
            'setting_id' => $this->setting->id, 'product_name' => 'W2', 'product_code' => 'W-BO2',
            'product_unit' => $this->product->product_unit, 'product_price' => 1, 'product_cost' => 1, 'product_quantity' => 1,
        ]);
        \DB::transaction(function () use ($second) {
            $a = ConsignmentInventoryCoordination::lockFor($this->setting->id, $this->product->id);
            $b = ConsignmentInventoryCoordination::lockFor($this->setting->id, $this->product->id);
            $this->assertSame($a->id, $b->id);

            $rows = ConsignmentInventoryCoordination::lockMany([
                [$this->setting->id, $second->id],
                [$this->setting->id, $this->product->id],
                [$this->setting->id, $this->product->id],
            ]);
            $this->assertCount(2, $rows);
            $this->assertSame(min($this->product->id, $second->id), $rows[0]->product_id);
        });
        $this->assertSame(2, ConsignmentInventoryCoordination::count());
    }

    /** @test */
    public function rollback_is_refused_when_buyout_evidence_exists()
    {
        $this->makeBuyout('BO-RB');

        $m2 = require base_path('Modules/Consignment/Database/Migrations/2026_11_10_000002_create_consignment_buyout_posting_evidence_tables.php');
        try {
            $m2->down();
            $this->fail('rollback should be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to roll back', $e->getMessage());
        }

        $m1 = require base_path('Modules/Consignment/Database/Migrations/2026_11_10_000001_create_consignment_buyout_tables.php');
        try {
            $m1->down();
            $this->fail('rollback should be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to roll back', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('consignment_buyouts'));
        $this->assertTrue(Schema::hasTable('consignment_inventory_coordinations'));
    }

    private function makePurchase(string $ref): \Modules\Purchase\Entities\Purchase
    {
        return \Modules\Purchase\Entities\Purchase::create([
            'setting_id' => $this->setting->id, 'date' => now(), 'due_date' => now()->addDays(30), 'reference' => $ref,
            'supplier_id' => $this->supplier->id, 'total_amount' => 1000, 'paid_amount' => 0, 'due_amount' => 1000,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED,
            'payment_status' => \Modules\Purchase\Entities\Purchase::PAYMENT_STATUS_UNPAID, 'payment_method' => 'Cash',
        ]);
    }

    /** @test */
    public function purchase_link_is_unique_and_reversal_is_once_per_buyout()
    {
        $purchase = $this->makePurchase('PO-BO-1');
        $a = $this->makeBuyout('BO-P1');
        $a->update(['purchase_id' => $purchase->id]);
        $b = $this->makeBuyout('BO-P2');
        try {
            $b->update(['purchase_id' => $purchase->id]);
            $this->fail('one Purchase linked to two buyouts');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }

        $row = ['consignment_buyout_id' => $a->id, 'purchase_id' => $purchase->id, 'reason' => 'x', 'reversed_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        \DB::table('consignment_buyout_reversals')->insert($row);
        $this->expectException(QueryException::class);
        \DB::table('consignment_buyout_reversals')->insert($row);
    }

    /** @test */
    public function posting_legs_and_lineage_are_unique_per_slice_and_mixed_receipts_stay_lossless()
    {
        $buyout = $this->makeBuyout('BO-MIX');
        $d1 = $this->makeReceipt($this->supplier, 'CR-MIX-1', '5.000');
        $d2 = $this->makeReceipt($this->supplier, 'CR-MIX-2', '3.000');
        $l1 = ConsignmentBuyoutLine::create($this->lineAttrs($buyout, $d1, '5.000'));
        $l2 = ConsignmentBuyoutLine::create($this->lineAttrs($buyout, $d2, '3.000'));
        $this->assertNotSame($l1->id, $l2->id);
        $this->assertEquals([$d1->id, $d2->id], $buyout->lines()->orderBy('id')->pluck('consignment_receiving_detail_id')->all());

        $purchase = $this->makePurchase('PO-MIX');
        $pd = \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id, 'product_id' => $this->product->id, 'quantity' => 8, 'unit_price' => 100,
            'sub_total' => 800, 'product_name' => 'W', 'product_code' => 'W', 'price' => 100,
            'product_discount_type' => 'fixed', 'product_discount_amount' => 0, 'product_tax_amount' => 0,
        ]);
        $lin = ['consignment_buyout_id' => $buyout->id, 'consignment_buyout_line_id' => $l1->id, 'purchase_id' => $purchase->id,
            'purchase_detail_id' => $pd->id, 'consignment_receiving_detail_id' => $d1->id, 'product_id' => $this->product->id,
            'base_quantity' => '5.000', 'created_at' => now(), 'updated_at' => now()];
        \DB::table('consignment_buyout_purchase_lineages')->insert($lin);
        \DB::table('consignment_buyout_purchase_lineages')->insert(['consignment_buyout_line_id' => $l2->id, 'consignment_receiving_detail_id' => $d2->id, 'base_quantity' => '3.000'] + $lin);
        try {
            \DB::table('consignment_buyout_purchase_lineages')->insert($lin);
            $this->fail('duplicate lineage accepted');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }

        $leg = ['consignment_buyout_id' => $buyout->id, 'consignment_buyout_line_id' => $l1->id, 'direction' => 'OUT', 'kind' => 'ACQUISITION',
            'location_id' => $this->location->id, 'product_id' => $this->product->id, 'base_quantity' => '-5.000',
            'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        \DB::table('consignment_buyout_movements')->insert($leg);
        $this->expectException(QueryException::class);
        \DB::table('consignment_buyout_movements')->insert($leg);
    }

    /** @test */
    public function one_receipt_may_be_split_across_destinations_but_not_duplicated_for_the_same_destination(): void
    {
        $buyout = $this->makeBuyout('BO-DEST');
        $detail = $this->makeReceipt($this->supplier, 'CR-DEST');
        $d1 = Location::create(['setting_id' => $this->setting->id, 'name' => 'D1']);
        $d2 = Location::create(['setting_id' => $this->setting->id, 'name' => 'D2']);
        ConsignmentBuyoutLine::create(['destination_location_id' => $d1->id] + $this->lineAttrs($buyout, $detail, '1.000'));
        ConsignmentBuyoutLine::create(['destination_location_id' => $d2->id] + $this->lineAttrs($buyout, $detail, '2.000'));
        $this->assertSame(2, $buyout->lines()->count());

        $this->expectException(QueryException::class);
        ConsignmentBuyoutLine::create(['destination_location_id' => $d1->id] + $this->lineAttrs($buyout, $detail, '0.500'));
    }
}
