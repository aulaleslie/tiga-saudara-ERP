<?php

namespace Modules\Consignment\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentReceivingDetailSerialNumber;
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

    public function test_receival_valid_edit_updates_receival_and_lines_successfully()
    {
        $lifecycle = new ConsignmentReceivalLifecycleService();
        $receivalService = new ConsignmentReceivalService();

        $productA = Product::create([
            'product_name' => 'Product Alpha',
            'product_code' => 'PA-01',
            'product_quantity' => 0,
            'product_cost' => 50000,
            'product_price' => 70000,
            'stock_managed' => true,
            'setting_id' => $this->setting->id,
        ]);

        $productB = Product::create([
            'product_name' => 'Product Beta',
            'product_code' => 'PB-01',
            'product_quantity' => 0,
            'product_cost' => 80000,
            'product_price' => 120000,
            'stock_managed' => true,
            'setting_id' => $this->setting->id,
        ]);

        $receival = ConsignmentReferenceService::createReceivalWithReference([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'status' => ConsignmentReceival::STATUS_DRAFT,
            'note' => 'Original note',
        ]);

        ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $productA->id,
            'product_name' => $productA->product_name,
            'product_code' => $productA->product_code,
            'quantity' => 5,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'subtotal_cost' => 250000,
            'total_cost' => 250000,
        ]);

        $normalizedLines = $receivalService->normalizeLines($this->setting, [
            [
                'product_id' => $productB->id,
                'quantity' => 3,
                'unit_cost' => 80000,
            ]
        ]);

        $updatedReceival = $lifecycle->update($receival, [
            'supplier_id' => $this->supplier->id,
            'date' => now()->addDay()->toDateString(),
            'supplier_delivery_reference' => 'DEL-999',
            'note' => 'Updated note',
        ], $normalizedLines, $this->user->id);

        $this->assertEquals('UPDATED NOTE', $updatedReceival->note);
        $this->assertEquals('DEL-999', $updatedReceival->supplier_delivery_reference);
        $this->assertEquals(1, $updatedReceival->lines->count());
        $this->assertEquals($productB->id, $updatedReceival->lines->first()->product_id);
        $this->assertEquals(3, (float) $updatedReceival->lines->first()->quantity);
        $this->assertEquals(80000, (float) $updatedReceival->lines->first()->unit_cost);
    }

    public function test_receival_update_accepts_shared_supplier_but_rejects_inactive_without_mutating_header_or_lines()
    {
        $lifecycle = new ConsignmentReceivalLifecycleService();
        $receivalService = new ConsignmentReceivalService();

        $foreignSetting = Setting::create([
            'company_name' => 'Foreign Setting Vendor Test',
            'company_email' => 'foreign_vendor@example.com',
            'company_phone' => '08123450000',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'foreign_vendor@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
            'document_prefix' => 'FRG',
        ]);

        $foreignSupplier = Supplier::create([
            'setting_id' => $foreignSetting->id,
            'supplier_name' => 'Foreign Setting Supplier',
            'supplier_email' => 'vendor@foreignsetting.com',
            'supplier_phone' => '08777000111',
            'city' => 'Bandung',
            'country' => 'Indonesia',
            'address' => 'Foreign St 5',
        ]);

        $product = Product::create([
            'product_name' => 'Foreign Supplier Test Product',
            'product_code' => 'FSTP-01',
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
            'note' => 'Original note prior to foreign supplier attempt',
        ]);

        $line = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'subtotal_cost' => 250000,
            'total_cost' => 250000,
        ]);

        $normalizedLines = $receivalService->normalizeLines($this->setting, [
            [
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_cost' => 50000,
            ]
        ]);

        // Suppliers are shared master data: a supplier homed in another setting is a
        // valid choice, and the receival stays scoped to its own setting.
        $lifecycle->update($receival, [
            'supplier_id' => $foreignSupplier->id,
            'date' => now()->toDateString(),
            'note' => 'Shared supplier update',
        ], $normalizedLines, $this->user->id);

        $receival->refresh();
        $this->assertEquals($foreignSupplier->id, $receival->supplier_id);
        $this->assertEquals($this->setting->id, $receival->setting_id);
        $this->assertEquals(1, $receival->lines()->count());
        $this->assertEquals(10, (float) $receival->lines()->first()->quantity);

        // The active boundary still applies: an inactive supplier is rejected and the
        // document is left unmutated.
        $foreignSupplier->update(['is_active' => false]);

        try {
            $lifecycle->update($receival, [
                'supplier_id' => $foreignSupplier->id,
                'date' => now()->toDateString(),
                'note' => 'Attempted inactive supplier update',
            ], $normalizedLines, $this->user->id);
            $this->fail('Expected exception for inactive supplier was not thrown.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Supplier tidak valid atau tidak aktif', $e->getMessage());
        }

        $receival->refresh();
        $this->assertEquals('SHARED SUPPLIER UPDATE', $receival->note);
    }

    public function test_receival_update_and_delete_with_stale_in_memory_model_revalidate_db_status()
    {
        $lifecycle = new ConsignmentReceivalLifecycleService();
        $receivalService = new ConsignmentReceivalService();

        $product = Product::create([
            'product_name' => 'Sample Product for Stale Test',
            'product_code' => 'SMP-STALE-01',
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
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'subtotal_cost' => 250000,
            'total_cost' => 250000,
        ]);

        // Obtain two stale in-memory model instances holding STATUS_DRAFT
        $staleReceival1 = ConsignmentReceival::find($receival->id);
        $staleReceival2 = ConsignmentReceival::find($receival->id);

        $this->assertEquals(ConsignmentReceival::STATUS_DRAFT, $staleReceival1->status);
        $this->assertEquals(ConsignmentReceival::STATUS_DRAFT, $staleReceival2->status);

        // Mutate database status directly to WAITING_APPROVAL (simulating concurrent submission)
        ConsignmentReceival::where('id', $receival->id)->update(['status' => ConsignmentReceival::STATUS_WAITING_APPROVAL]);

        // 1. Attempting update with stale in-memory model holding DRAFT must re-read DB status under lock and fail
        try {
            $normalizedLines = $receivalService->normalizeLines($this->setting, [
                [
                    'product_id' => $product->id,
                    'quantity' => 10,
                    'unit_cost' => 50000,
                ]
            ]);
            $lifecycle->update($staleReceival1, [
                'supplier_id' => $this->supplier->id,
                'date' => now()->toDateString(),
                'note' => 'Stale update attempt',
            ], $normalizedLines, $this->user->id);
            $this->fail('Expected exception when updating stale model was not thrown.');
        } catch (\Exception $e) {
            $this->assertStringContainsString("Dokumen konsinyasi berstatus 'WAITING_APPROVAL' tidak dapat diubah", $e->getMessage());
        }

        // Verify lines were not mutated
        $this->assertEquals(5, (float) ConsignmentReceivalLine::where('consignment_receival_id', $receival->id)->value('quantity'));

        // 2. Attempting delete with stale in-memory model holding DRAFT must re-read DB status under lock and fail
        try {
            $lifecycle->delete($staleReceival2);
            $this->fail('Expected exception when deleting stale model was not thrown.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Hanya draf dokumen yang belum memiliki riwayat penerimaan yang dapat dihapus', $e->getMessage());
        }

        // Verify record was not deleted
        $this->assertDatabaseHas('consignment_receivals', ['id' => $receival->id]);
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
        $this->expectExceptionMessage('Lokasi penerimaan tidak valid, tidak aktif, atau bukan merupakan lokasi konsinyasi pada bisnis ini.');
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

    public function test_inactive_consignment_location_is_rejected_on_pending_creation_and_approval()
    {
        $receivingService = new ConsignmentReceivingService();

        $inactiveConsignmentLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Rak Konsinyasi Nonaktif',
            'is_consignment' => true,
            'is_active' => false,
        ]);

        $product = Product::create([
            'product_name' => 'Produk Tes Inaktif',
            'product_code' => 'INACT-01',
            'product_quantity' => 0,
            'product_cost' => 100000,
            'product_price' => 150000,
            'stock_managed' => true,
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
            'quantity' => 5,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 500000,
            'total_cost' => 500000,
        ]);

        // Attempt pending receiving to inactive consignment location
        try {
            $receivingService->createPendingReceiving($receival, [
                'location_id' => $inactiveConsignmentLocation->id,
                'date' => now()->toDateString(),
                'details' => [
                    $line->id => ['quantity_received' => 5]
                ]
            ], $this->user->id);
            $this->fail('Expected exception for inactive location on pending creation was not thrown.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('tidak aktif', $e->getMessage());
        }

        // Test deactivation between pending creation and approval
        $activeLoc = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Rak Konsinyasi Sementara Active',
            'is_consignment' => true,
            'is_active' => true,
        ]);

        $receiving = $receivingService->createPendingReceiving($receival, [
            'location_id' => $activeLoc->id,
            'date' => now()->toDateString(),
            'details' => [
                $line->id => ['quantity_received' => 5]
            ]
        ], $this->user->id);

        // Deactivate location before approval
        $activeLoc->update(['is_active' => false]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('tidak aktif');
        $receivingService->approveReceiving($receiving, $this->user->id);
    }

    public function test_location_classification_checker_blocks_reclassification_for_zero_stock_with_provenance()
    {
        $receivingService = new ConsignmentReceivingService();
        $dependencyChecker = new \Modules\Consignment\Services\ConsignmentLocationDependencyChecker();

        $loc = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Rak Konsinyasi Historical',
            'is_consignment' => true,
            'is_active' => true,
        ]);

        $product = Product::create([
            'product_name' => 'Produk Prove Test',
            'product_code' => 'PROV-01',
            'product_quantity' => 0,
            'product_cost' => 100000,
            'product_price' => 150000,
            'stock_managed' => true,
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
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 200000,
            'total_cost' => 200000,
        ]);

        $receiving = $receivingService->createPendingReceiving($receival, [
            'location_id' => $loc->id,
            'date' => now()->toDateString(),
            'details' => [
                $line->id => ['quantity_received' => 2]
            ]
        ], $this->user->id);

        $receivingService->approveReceiving($receiving, $this->user->id);
        $receivingService->reverseReceiving($receiving->fresh(), $this->user->id, 'Reversal test');

        // At this point, stock at $loc is 0, but historical receiving document provenance exists
        $stockQty = \Modules\Product\Entities\ProductStock::where('location_id', $loc->id)->value('quantity') ?? 0;
        $this->assertEquals(0, (float) $stockQty);

        $blockers = $dependencyChecker->getReclassificationBlockers($loc);
        $this->assertNotEmpty($blockers);
        $this->assertStringContainsString('riwayat dokumen penerimaan fisik konsinyasi', implode('; ', $blockers));
    }

    public function test_unique_constraint_enforces_one_line_per_product_at_database_level()
    {
        $product = Product::create([
            'product_name' => 'Produk Database Uniq Test',
            'product_code' => 'DBUNIQ-01',
            'product_quantity' => 0,
            'product_cost' => 100000,
            'product_price' => 150000,
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
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 500000,
            'total_cost' => 500000,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 1000000,
            'total_cost' => 1000000,
        ]);
    }

    public function test_serialized_line_requires_exact_serial_count_on_pending_creation()
    {
        $receivingService = new ConsignmentReceivingService();

        $product = Product::create([
            'product_name' => 'Laptop Seri',
            'product_code' => 'LPT-SER',
            'product_quantity' => 0,
            'product_cost' => 5000000,
            'product_price' => 6000000,
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
            'quantity' => 3,
            'unit_cost' => 5000000,
            'unit_dpp' => 5000000,
            'subtotal_cost' => 15000000,
            'total_cost' => 15000000,
            'is_serialized' => true,
        ]);

        // Too few serials
        try {
            $receivingService->createPendingReceiving($receival, [
                'location_id' => $this->consignmentLocation->id,
                'date' => now()->toDateString(),
                'details' => [
                    $line->id => [
                        'quantity_received' => 3,
                        'serial_numbers' => ['SN-LPT-1', 'SN-LPT-2'],
                    ]
                ]
            ], $this->user->id);
            $this->fail('Expected exception for too few serials was not thrown.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Jumlah nomor seri (2) harus persis sama', $e->getMessage());
        }

        // Duplicate serials within line
        try {
            $receivingService->createPendingReceiving($receival, [
                'location_id' => $this->consignmentLocation->id,
                'date' => now()->toDateString(),
                'details' => [
                    $line->id => [
                        'quantity_received' => 3,
                        'serial_numbers' => ['SN-LPT-1', 'SN-LPT-1', 'SN-LPT-2'],
                    ]
                ]
            ], $this->user->id);
            $this->fail('Expected exception for duplicate serials in line was not thrown.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('duplikasi nomor seri', $e->getMessage());
        }

        // Exact count succeeds
        $receiving = $receivingService->createPendingReceiving($receival, [
            'location_id' => $this->consignmentLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $line->id => [
                    'quantity_received' => 3,
                    'serial_numbers' => ['SN-LPT-1', 'SN-LPT-2', 'SN-LPT-3'],
                ]
            ]
        ], $this->user->id);

        $this->assertTrue($receiving->isPending());
        $detail = $receiving->details->first();
        $this->assertEquals(['SN-LPT-1', 'SN-LPT-2', 'SN-LPT-3'], $detail->pending_serial_numbers);
    }

    public function test_non_serialized_line_rejects_serial_payload()
    {
        $receivingService = new ConsignmentReceivingService();

        $product = Product::create([
            'product_name' => 'Flashdisk Non-Serial',
            'product_code' => 'FD-NONSER',
            'product_quantity' => 0,
            'product_cost' => 50000,
            'product_price' => 70000,
            'stock_managed' => true,
            'serial_number_required' => false,
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
            'quantity' => 5,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'subtotal_cost' => 250000,
            'total_cost' => 250000,
            'is_serialized' => false,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Payload nomor seri tidak diizinkan untuk produk non-serial');

        $receivingService->createPendingReceiving($receival, [
            'location_id' => $this->consignmentLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $line->id => [
                    'quantity_received' => 5,
                    'serial_numbers' => ['UNWANTED-SN-1'],
                ]
            ]
        ], $this->user->id);
    }

    public function test_composite_database_identity_permits_same_serial_text_for_different_products()
    {
        $receivingService = new ConsignmentReceivingService();

        $productA = Product::create([
            'product_name' => 'Product A Serial',
            'product_code' => 'PA-SER',
            'product_quantity' => 0,
            'product_cost' => 100000,
            'product_price' => 150000,
            'stock_managed' => true,
            'serial_number_required' => true,
            'setting_id' => $this->setting->id,
        ]);

        $productB = Product::create([
            'product_name' => 'Product B Serial',
            'product_code' => 'PB-SER',
            'product_quantity' => 0,
            'product_cost' => 200000,
            'product_price' => 250000,
            'stock_managed' => true,
            'serial_number_required' => true,
            'setting_id' => $this->setting->id,
        ]);

        // Pre-create active serial for Product A with serial number "SHARED-SN-100"
        ProductSerialNumber::create([
            'product_id' => $productA->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => 'SHARED-SN-100',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        $receivalB = ConsignmentReferenceService::createReceivalWithReference([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'status' => ConsignmentReceival::STATUS_APPROVED,
        ]);

        $lineB = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receivalB->id,
            'product_id' => $productB->id,
            'product_name' => $productB->product_name,
            'product_code' => $productB->product_code,
            'quantity' => 1,
            'unit_cost' => 200000,
            'unit_dpp' => 200000,
            'subtotal_cost' => 200000,
            'total_cost' => 200000,
            'is_serialized' => true,
        ]);

        // Receiving Product B with serial number "SHARED-SN-100" should succeed because composite identity (product_id, serial_number) is different
        $receiving = $receivingService->createPendingReceiving($receivalB, [
            'location_id' => $this->consignmentLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $lineB->id => [
                    'quantity_received' => 1,
                    'serial_numbers' => ['SHARED-SN-100'],
                ]
            ]
        ], $this->user->id);

        $approved = $receivingService->approveReceiving($receiving, $this->user->id);
        $this->assertTrue($approved->isApproved());

        $snB = ProductSerialNumber::where('product_id', $productB->id)->where('serial_number', 'SHARED-SN-100')->first();
        $this->assertNotNull($snB);
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $snB->status);
    }

    public function test_pending_receiving_produces_no_stock_serial_or_history_mutation()
    {
        $receivingService = new ConsignmentReceivingService();

        $product = Product::create([
            'product_name' => 'Zero Mutation Product',
            'product_code' => 'ZMUT-01',
            'product_quantity' => 0,
            'product_cost' => 100000,
            'product_price' => 150000,
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
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 200000,
            'total_cost' => 200000,
            'is_serialized' => true,
        ]);

        $receiving = $receivingService->createPendingReceiving($receival, [
            'location_id' => $this->consignmentLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $line->id => [
                    'quantity_received' => 2,
                    'serial_numbers' => ['SN-PENDING-1', 'SN-PENDING-2'],
                ]
            ]
        ], $this->user->id);

        $this->assertTrue($receiving->isPending());
        $this->assertEquals(0, ProductStock::where('product_id', $product->id)->count());
        $this->assertEquals(0, ProductSerialNumber::where('product_id', $product->id)->count());
        $this->assertEquals(0, SerialNumberHistory::count());
        $this->assertEquals(0, ConsignmentReceivingDetailSerialNumber::count());
    }

    public function test_stale_serial_status_change_before_approval_fails_and_rolls_back()
    {
        $receivingService = new ConsignmentReceivingService();

        $product = Product::create([
            'product_name' => 'Stale Serial Product',
            'product_code' => 'STALE-SER-01',
            'product_quantity' => 0,
            'product_cost' => 100000,
            'product_price' => 150000,
            'stock_managed' => true,
            'serial_number_required' => true,
            'setting_id' => $this->setting->id,
        ]);

        // Pre-existing serial in RETURNED status (reusable at pending creation time)
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => 'SN-REUSE-STALE',
            'status' => ProductSerialNumber::STATUS_RETURNED,
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
            'quantity' => 1,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 100000,
            'total_cost' => 100000,
            'is_serialized' => true,
        ]);

        $receiving = $receivingService->createPendingReceiving($receival, [
            'location_id' => $this->consignmentLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $line->id => [
                    'quantity_received' => 1,
                    'serial_numbers' => ['SN-REUSE-STALE'],
                ]
            ]
        ], $this->user->id);

        // Before approval, serial becomes ACTIVE via another process
        $serial->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        try {
            $receivingService->approveReceiving($receiving, $this->user->id);
            $this->fail('Expected exception for stale serial status change was not thrown.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('tidak lagi valid saat persetujuan', $e->getMessage());
        }

        // Verify full rollback: no stock increment, no histories created, receiving stays PENDING
        $this->assertEquals(0, ProductStock::where('product_id', $product->id)->value('quantity') ?? 0);
        $this->assertEquals(0, SerialNumberHistory::count());
        $this->assertEquals(0, ConsignmentReceivingDetailSerialNumber::count());
        $this->assertTrue($receiving->fresh()->isPending());
    }

    public function test_reversal_is_blocked_after_later_serial_activity()
    {
        $receivingService = new ConsignmentReceivingService();

        $product = Product::create([
            'product_name' => 'Serial Movement Product',
            'product_code' => 'SMP-MV-01',
            'product_quantity' => 0,
            'product_cost' => 100000,
            'product_price' => 150000,
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
            'quantity' => 1,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 100000,
            'total_cost' => 100000,
            'is_serialized' => true,
        ]);

        $receiving = $receivingService->createPendingReceiving($receival, [
            'location_id' => $this->consignmentLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $line->id => [
                    'quantity_received' => 1,
                    'serial_numbers' => ['SN-MOVE-1'],
                ]
            ]
        ], $this->user->id);

        $approved = $receivingService->approveReceiving($receiving, $this->user->id);

        $serial = ProductSerialNumber::where('product_id', $product->id)->where('serial_number', 'SN-MOVE-1')->first();

        // Add a later history event simulating serial movement/sale
        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_SOLD,
            'location_id' => $this->consignmentLocation->id,
            'user_id' => $this->user->id,
            'note' => 'Serial sold in POS',
        ]);

        $preview = $receivingService->previewReversal($approved);
        $this->assertFalse($preview['can_reverse']);
        $this->assertStringContainsString('riwayat transaksi lanjutan', implode('; ', $preview['blockers']));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pembalikan ditolak');
        $receivingService->reverseReceiving($approved, $this->user->id, 'Attempt reversal on sold serial');
    }
}
