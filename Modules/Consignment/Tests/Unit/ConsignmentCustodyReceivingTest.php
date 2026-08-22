<?php

namespace Modules\Consignment\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentReceivalLifecycleService;
use Modules\Consignment\Services\ConsignmentReceivalService;
use Modules\Consignment\Services\ConsignmentReceivingService;
use Modules\Consignment\Services\ConsignmentReferenceService;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class ConsignmentCustodyReceivingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Setting $pkpSetting;
    protected Supplier $supplier;
    protected Location $consignmentLocation;
    protected Location $standardLocation;
    protected Tax $tax11;
    protected Unit $unitPcs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $currency = Currency::create([
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Non-PKP Store',
            'company_email' => 'store@example.com',
            'company_phone' => '08123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'store@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
            'document_prefix' => 'TGA',
        ]);

        $this->pkpSetting = Setting::create([
            'company_name' => 'PKP Store',
            'company_email' => 'pkp@example.com',
            'company_phone' => '08123456780',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'pkp@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
            'document_prefix' => 'PKP',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Consignor Vendor Ltd',
            'supplier_email' => 'vendor@example.com',
            'supplier_phone' => '0811112222',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor Street 1',
        ]);

        $this->consignmentLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Rak Titipan Konsinyasi',
            'is_consignment' => true,
        ]);

        $this->standardLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Gudang Utama Milik Sendiri',
            'is_consignment' => false,
        ]);

        $this->tax11 = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11.0,
        ]);

        $this->unitPcs = Unit::create([
            'name' => 'Pieces',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);
    }

    public function test_receival_lines_normalization_for_non_pkp_and_pkp()
    {
        $service = new ConsignmentReceivalService();

        $product = Product::create([
            'product_name' => 'Keyboard Mechanical',
            'product_code' => 'KB-001',
            'product_quantity' => 0,
            'product_cost' => 100000,
            'product_price' => 150000,
            'product_unit' => $this->unitPcs->id,
            'unit_id' => $this->unitPcs->id,
            'stock_managed' => true,
            'serial_number_required' => false,
            'setting_id' => $this->setting->id,
        ]);

        // Non-PKP setting ignores tax and unit_dpp = unit_cost
        $linesNonPkp = $service->normalizeLines($this->setting, [
            [
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_cost' => 100000,
            ]
        ]);

        $this->assertCount(1, $linesNonPkp);
        $this->assertEquals(10, $linesNonPkp[0]['quantity']);
        $this->assertEquals(100000, $linesNonPkp[0]['unit_cost']);
        $this->assertEquals(100000, $linesNonPkp[0]['unit_dpp']);
        $this->assertEquals(1000000, $linesNonPkp[0]['subtotal_cost']);
        $this->assertEquals(0, $linesNonPkp[0]['tax_amount']);
        $this->assertNull($linesNonPkp[0]['tax_id']);

        // PKP setting requires tax
        $linesPkp = $service->normalizeLines($this->pkpSetting, [
            [
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_cost' => 100000,
                'tax_id' => $this->tax11->id,
            ]
        ]);

        $this->assertEquals(11.0, $linesPkp[0]['tax_rate']);
        $this->assertEquals(110000, $linesPkp[0]['tax_amount']);
        $this->assertEquals(1110000, $linesPkp[0]['total_cost']);
    }

    public function test_receival_lifecycle_transitions_draft_submit_approve_reject()
    {
        $lifecycle = new ConsignmentReceivalLifecycleService();

        $product = Product::create([
            'product_name' => 'Sample Product',
            'product_code' => 'SMP-01',
            'product_quantity' => 0,
            'product_cost' => 50000,
            'product_price' => 70000,
            'stock_managed' => true,
            'setting_id' => $this->setting->id,
        ]);

        $receival = ConsignmentReferenceService::createReceivalWithReference([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'status' => ConsignmentReceival::STATUS_DRAFT,
        ]);

        ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $product->id,
            'product_name' => 'Sample Product',
            'product_code' => 'SMP-01',
            'quantity' => 5,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'subtotal_cost' => 250000,
            'total_cost' => 250000,
        ]);

        $this->assertTrue($receival->isDraft());

        // Submit
        $submitted = $lifecycle->submit($receival, $this->user->id);
        $this->assertTrue($submitted->isWaitingApproval());

        // Reject
        $rejected = $lifecycle->reject($submitted, $this->user->id, 'Kuantitas tidak sesuai PO');
        $this->assertTrue($rejected->isRejected());
        $this->assertEquals('KUANTITAS TIDAK SESUAI PO', $rejected->rejection_reason);

        // Re-submit
        $resubmitted = $lifecycle->submit($rejected, $this->user->id);
        $this->assertTrue($resubmitted->isWaitingApproval());

        // Approve
        $approved = $lifecycle->approve($resubmitted, $this->user->id);
        $this->assertTrue($approved->isApproved());
    }

    public function test_receiving_to_non_consignment_location_is_rejected()
    {
        $receivingService = new ConsignmentReceivingService();

        $product = Product::create([
            'product_name' => 'Monitor Gaming 144Hz',
            'product_code' => 'MNT-144',
            'product_quantity' => 0,
            'product_cost' => 2000000,
            'product_price' => 2500000,
            'stock_managed' => true,
            'serial_number_required' => true,
            'setting_id' => $this->setting->id,
        ]);

        $receival = ConsignmentReferenceService::createReceivalWithReference([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'status' => ConsignmentReceival::STATUS_APPROVED,
        ]);

        $line = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'unit_cost' => 2000000,
            'unit_dpp' => 2000000,
            'subtotal_cost' => 4000000,
            'total_cost' => 4000000,
            'is_serialized' => true,
        ]);

        // Attempting to receive to non-consignment location must fail
        $this->expectExceptionMessage('Lokasi penerimaan tidak valid atau bukan merupakan lokasi konsinyasi pada bisnis ini.');
        $receivingService->createPendingReceiving($receival, [
            'location_id' => $this->standardLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $line->id => [
                    'quantity_received' => 2,
                    'serial_numbers' => ['SN-MNT-001', 'SN-MNT-002'],
                ]
            ]
        ], $this->user->id);
    }

    public function test_receiving_to_consignment_location_and_full_reversal()
    {
        $receivingService = new ConsignmentReceivingService();

        $product = Product::create([
            'product_name' => 'VGA Card RTX 4070',
            'product_code' => 'VGA-4070',
            'product_quantity' => 0,
            'product_cost' => 10000000,
            'product_price' => 12000000,
            'stock_managed' => true,
            'serial_number_required' => true,
            'setting_id' => $this->setting->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'average_purchase_price' => 9000000,
            'last_purchase_price' => 9000000,
        ]);

        $receival = ConsignmentReferenceService::createReceivalWithReference([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'status' => ConsignmentReceival::STATUS_APPROVED,
        ]);

        $line = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'unit_cost' => 10000000,
            'unit_dpp' => 10000000,
            'subtotal_cost' => 20000000,
            'total_cost' => 20000000,
            'is_serialized' => true,
        ]);

        // Create pending receiving note
        $receiving = $receivingService->createPendingReceiving($receival, [
            'location_id' => $this->consignmentLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $line->id => [
                    'quantity_received' => 2,
                    'serial_numbers' => ['SN-RTX-101', 'SN-RTX-102'],
                ]
            ]
        ], $this->user->id);

        $this->assertTrue($receiving->isPending());
        $this->assertEquals(0, ProductStock::where('product_id', $product->id)->where('location_id', $this->consignmentLocation->id)->value('quantity') ?? 0);

        // Approve physical receipt
        $approvedReceiving = $receivingService->approveReceiving($receiving, $this->user->id);
        $this->assertTrue($approvedReceiving->isApproved());

        // Check stock mutation
        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $this->consignmentLocation->id)->first();
        $this->assertNotNull($stock);
        $this->assertEquals(2, (float) $stock->quantity);
        $this->assertEquals(2, (float) $stock->quantity_non_tax);
        $this->assertEquals(2, (float) $product->fresh()->product_quantity);

        // Check transaction record
        $trx = Transaction::where('product_id', $product->id)
            ->where('location_id', $this->consignmentLocation->id)
            ->where('type', 'CONSIGNMENT_RECEIPT')
            ->first();
        $this->assertNotNull($trx);
        $this->assertEquals(2, (float) $trx->quantity);
        $this->assertEquals(2, (float) $trx->current_quantity);

        // Check active serial numbers and history
        $serials = ProductSerialNumber::where('product_id', $product->id)
            ->where('location_id', $this->consignmentLocation->id)
            ->where('status', ProductSerialNumber::STATUS_ACTIVE)
            ->get();
        $this->assertCount(2, $serials);
        $this->assertEquals(['SN-RTX-101', 'SN-RTX-102'], $serials->pluck('serial_number')->toArray());

        $histories = SerialNumberHistory::whereIn('product_serial_number_id', $serials->pluck('id'))
            ->where('event_type', SerialNumberHistory::EVENT_RECEIVED)
            ->get();
        $this->assertCount(2, $histories);

        // Check reversal preview
        $preview = $receivingService->previewReversal($approvedReceiving);
        $this->assertTrue($preview['can_reverse']);
        $this->assertEmpty($preview['blockers']);

        // Execute Reversal
        $reversedReceiving = $receivingService->reverseReceiving($approvedReceiving, $this->user->id, 'Salah alokasi titipan');
        $this->assertTrue($reversedReceiving->isReversed());

        // Check stock decremented back to 0
        $stock->refresh();
        $this->assertEquals(0, (float) $stock->quantity);
        $this->assertEquals(0, (float) $product->fresh()->product_quantity);

        // Check serial numbers marked as RETURNED
        $serialsAfter = ProductSerialNumber::where('product_id', $product->id)->get();
        foreach ($serialsAfter as $sn) {
            $this->assertEquals(ProductSerialNumber::STATUS_RETURNED, $sn->status);
        }

        // Check CONSIGNMENT_RECEIPT_REVERSAL transaction created
        $revTrx = Transaction::where('product_id', $product->id)
            ->where('type', 'CONSIGNMENT_RECEIPT_REVERSAL')
            ->first();
        $this->assertNotNull($revTrx);
        $this->assertEquals(2, (float) $revTrx->quantity);
        $this->assertEquals(0, (float) $revTrx->current_quantity);
    }

    public function test_later_price_or_stock_movement_blocks_reversal()
    {
        $receivingService = new ConsignmentReceivingService();

        $product = Product::create([
            'product_name' => 'Keyboard Mechanical RGB',
            'product_code' => 'KB-RGB-01',
            'product_quantity' => 0,
            'product_cost' => 500000,
            'product_price' => 750000,
            'stock_managed' => true,
            'serial_number_required' => false,
            'setting_id' => $this->setting->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'average_purchase_price' => 500000,
            'last_purchase_price' => 500000,
        ]);

        $receival = ConsignmentReferenceService::createReceivalWithReference([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'status' => ConsignmentReceival::STATUS_APPROVED,
        ]);

        $line = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'unit_cost' => 500000,
            'unit_dpp' => 500000,
            'subtotal_cost' => 2500000,
            'total_cost' => 2500000,
            'is_serialized' => false,
        ]);

        $receiving = $receivingService->createPendingReceiving($receival, [
            'location_id' => $this->consignmentLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $line->id => [
                    'quantity_received' => 5,
                ]
            ]
        ], $this->user->id);

        $approvedReceiving = $receivingService->approveReceiving($receiving, $this->user->id);

        // Simulate subsequent stock change (e.g. sale or adjustment)
        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $this->consignmentLocation->id)->first();
        $stock->update(['quantity' => 3, 'quantity_non_tax' => 3]);

        $preview = $receivingService->previewReversal($approvedReceiving);
        $this->assertFalse($preview['can_reverse']);
        $this->assertStringContainsString('tidak sama dengan kondisi setelah persetujuan', $preview['blockers'][0]);

        // Attempting to reverse must throw exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pembalikan ditolak');
        $receivingService->reverseReceiving($approvedReceiving, $this->user->id, 'Coba pembalikan saat stok berubah');
    }
}
