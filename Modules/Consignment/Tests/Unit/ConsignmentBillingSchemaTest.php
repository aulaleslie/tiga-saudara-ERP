<?php

namespace Modules\Consignment\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentReceiptAllocation;
use Modules\Consignment\Entities\ConsignmentSerializedAllocation;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ConsignmentBillingSchemaTest extends TestCase
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
            'company_name' => 'Consignment Schema Test',
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
            'product_code' => 'W-SCH',
            'product_unit' => $unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 10,
        ]);
    }

    /** @test */
    public function it_provides_a_dedicated_tax_snapshot_version_column_on_receipt_allocations()
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('consignment_receipt_allocations', 'tax_snapshot_version')
        );
    }

    /** @test */
    public function it_refuses_migration_rollback_while_proportional_v2_allocations_exist()
    {
        $migration = require base_path('Modules/Consignment/Database/Migrations/2026_08_28_000001_add_consignment_supplier_billing_conversion_tables.php');

        // A v2 allocation exists, so rollback must be refused before anything is dropped.
        // (The permitted-rollback path is not exercised here: the rest of down() drops
        // foreign keys, which SQLite cannot do.)
        $this->makeProportionalReceiptAllocation();

        try {
            $migration->down();
            $this->fail('Rollback should be refused while proportional (v2) allocations exist.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('proportional (v2) tax semantics', $e->getMessage());
        }

        // Guard runs before any destructive step: lineage table and column both survive.
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('consignment_receipt_allocations', 'tax_snapshot_version')
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('consignment_purchase_detail_lineages')
        );
    }

    /** @test */
    public function it_sets_ordinary_as_purchase_source_default_and_supports_consignment_billing_source()
    {
        $purchaseOrdinary = Purchase::create([
            'setting_id' => $this->setting->id,
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO/2026/001',
            'supplier_id' => $this->supplier->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
        ]);

        $this->assertEquals(Purchase::SOURCE_ORDINARY, $purchaseOrdinary->source_type);
        $this->assertTrue($purchaseOrdinary->isOrdinary());
        $this->assertFalse($purchaseOrdinary->isConsignmentBilling());

        $purchaseConsignment = Purchase::create([
            'setting_id' => $this->setting->id,
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO/2026/002',
            'supplier_id' => $this->supplier->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Purchase::STATUS_RECEIVED,
            'source_type' => Purchase::SOURCE_CONSIGNMENT_BILLING,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
        ]);

        $this->assertEquals(Purchase::SOURCE_CONSIGNMENT_BILLING, $purchaseConsignment->source_type);
        $this->assertTrue($purchaseConsignment->isConsignmentBilling());
        $this->assertFalse($purchaseConsignment->isOrdinary());
    }

    /** @test */
    public function it_prevents_duplicate_purchase_link_on_consignment_billing_confirmations()
    {
        $purchase1 = Purchase::create([
            'setting_id' => $this->setting->id,
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO/2026/003',
            'supplier_id' => $this->supplier->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Purchase::STATUS_RECEIVED,
            'source_type' => Purchase::SOURCE_CONSIGNMENT_BILLING,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
        ]);

        $confirmation1 = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-001',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'purchase_id' => $purchase1->id,
            'is_ready_for_billing' => false,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-002',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'purchase_id' => $purchase1->id,
            'is_ready_for_billing' => false,
        ]);
    }

    /** @test */
    public function it_enforces_unique_and_restrictive_purchase_detail_lineage()
    {
        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO/2026/004',
            'supplier_id' => $this->supplier->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Purchase::STATUS_RECEIVED,
            'source_type' => Purchase::SOURCE_CONSIGNMENT_BILLING,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 50000,
            'sub_total' => 100000,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'price' => 50000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $confirmation = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-003',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'purchase_id' => $purchase->id,
            'is_ready_for_billing' => false,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Cust A',
            'customer_email' => 'cust@example.com',
            'customer_phone' => '08123123',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Addr',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => 'Cust A',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale->id,
            'status' => 'APPROVED',
        ]);

        $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'dispatched_quantity' => 2,
        ]);

        $soldSource = ConsignmentSoldSource::create([
            'setting_id' => $this->setting->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 2,
            'dispatched_at' => now(),
            'source_hash' => 'hash123',
            'source_snapshot' => [],
        ]);

        $confLine = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_sold_source_id' => $soldSource->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 2,
        ]);

        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-001',
            'receival_number' => 'CR-001',
            'status' => 'APPROVED',
            'date' => now(),
        ]);

        $receiving = ConsignmentReceiving::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-001',
            'status' => ConsignmentReceiving::STATUS_APPROVED,
            'date' => now(),
        ]);

        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'subtotal_cost' => 500000,
            'subtotal_dpp' => 500000,
            'total_cost' => 500000,
            'total_dpp' => 500000,
        ]);

        $receivingDetail = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $receivalLine->id,
            'product_id' => $this->product->id,
            'quantity_received' => 10,
            'received_base_quantity' => 10,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
        ]);

        $receiptAlloc = ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_receiving_detail_id' => $receivingDetail->id,
            'allocated_base_quantity' => 2,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
        ]);

        $lineage1 = ConsignmentPurchaseDetailLineage::create([
            'setting_id' => $this->setting->id,
            'purchase_id' => $purchase->id,
            'purchase_detail_id' => $purchaseDetail->id,
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_receipt_allocation_id' => $receiptAlloc->id,
            'product_id' => $this->product->id,
            'consignment_receiving_detail_id' => $receivingDetail->id,
            'billed_base_quantity' => 2,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
        ]);

        $this->assertNotNull($lineage1->id);

        // Foreign key restrict verification on deleting purchase or confirmation line
        $this->expectException(\Illuminate\Database\QueryException::class);
        $purchase->delete();
    }

    /**
     * Minimal approved receival/receiving chain carrying one proportional (v2) receipt
     * allocation — enough to exercise the migration rollback guard.
     */
    private function makeProportionalReceiptAllocation(): ConsignmentReceiptAllocation
    {
        $confirmation = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-ROLLBACK-001',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Cust Rollback',
            'customer_email' => 'rollback@example.com',
            'customer_phone' => '08123124',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Addr',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => 'Cust Rollback',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale->id,
            'status' => 'APPROVED',
        ]);

        $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'dispatched_quantity' => 1,
        ]);

        $soldSource = ConsignmentSoldSource::create([
            'setting_id' => $this->setting->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 1,
            'dispatched_at' => now(),
            'source_hash' => 'hash_rollback_1',
            'source_snapshot' => [],
        ]);

        $confLine = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_sold_source_id' => $soldSource->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 1,
        ]);

        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-ROLLBACK-001',
            'receival_number' => 'CR-ROLLBACK-001',
            'status' => 'APPROVED',
            'date' => now(),
        ]);

        $receiving = ConsignmentReceiving::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-ROLLBACK-001',
            'status' => ConsignmentReceiving::STATUS_APPROVED,
            'date' => now(),
        ]);

        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'subtotal_cost' => 50000,
            'subtotal_dpp' => 50000,
            'total_cost' => 50000,
            'total_dpp' => 50000,
        ]);

        $receivingDetail = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $receivalLine->id,
            'product_id' => $this->product->id,
            'quantity_received' => 1,
            'received_base_quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
        ]);

        return ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_receiving_detail_id' => $receivingDetail->id,
            'allocated_base_quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);
    }

    /** @test */
    public function it_verifies_purchase_details_quantity_column_type_is_decimal_and_enforces_rollback_guard()
    {
        // 1. Assert column exists and column type is decimal with precision 15 and scale 3
        $driver = \Illuminate\Support\Facades\DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $column = \Illuminate\Support\Facades\DB::table('information_schema.columns')
                ->where('table_schema', \Illuminate\Support\Facades\DB::getDatabaseName())
                ->where('table_name', 'purchase_details')
                ->where('column_name', 'quantity')
                ->first();

            $this->assertNotNull($column);
            $this->assertEquals('decimal', strtolower($column->DATA_TYPE ?? $column->data_type));
            $this->assertEquals(15, (int) ($column->NUMERIC_PRECISION ?? $column->numeric_precision));
            $this->assertEquals(3, (int) ($column->NUMERIC_SCALE ?? $column->numeric_scale));
        } else {
            $type = \Illuminate\Support\Facades\Schema::getColumnType('purchase_details', 'quantity');
            $this->assertEquals('decimal', $type);
        }

        // 2. Assert rollback guard blocks rollback when fractional purchase details exist
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => '2026-09-28',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => 'Supplier Beta',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 150000,
            'paid_amount' => 0,
            'due_amount' => 150000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'source_type' => Purchase::SOURCE_ORDINARY,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1.5,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 150000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $migration = include module_path('Consignment', 'Database/Migrations/2026_08_28_000001_add_consignment_supplier_billing_conversion_tables.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('purchase detail(s) contain fractional quantities');

        $migration->down();
    }

    /** @test */
    public function it_refuses_migration_rollback_when_legacy_v1_converted_billing_records_exist()
    {
        // Setup an integral, legacy-v1 converted confirmation
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => '2026-09-28',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => 'Supplier Beta',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'source_type' => Purchase::SOURCE_CONSIGNMENT_BILLING,
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $confirmation = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-LEGACY-INTEGRAL-001',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
            'billed_at' => now(),
            'purchase_id' => $purchase->id,
        ]);

        $migration = include module_path('Consignment', 'Database/Migrations/2026_08_28_000001_add_consignment_supplier_billing_conversion_tables.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Phase 3 consignment billing records exist');

        $migration->down();
    }
}
