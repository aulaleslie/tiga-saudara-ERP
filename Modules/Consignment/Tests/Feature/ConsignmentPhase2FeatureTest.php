<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Entities\ConsignmentSoldSourceSerial;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService;
use Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConsignmentPhase2FeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Supplier $supplier;
    protected Location $consignmentLocation;
    protected Product $serializedProduct;

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
            'company_name' => 'Consignment ERP Unit Test',
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

        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $permissions = [
            'consignments.access',
            'consignments.allocations.access',
            'consignments.allocations.create',
            'consignments.allocations.edit',
        ];

        foreach ($permissions as $perm) {
            $p = Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            $role->givePermissionTo($p);
            $this->user->givePermissionTo($p);
        }

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Alpha',
            'supplier_email' => 'alpha@example.com',
            'supplier_phone' => '081111111',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor St',
        ]);

        $this->consignmentLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Consignment Rack A',
            'is_consignment' => true,
        ]);

        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pieces',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'CAT-001',
            'category_name' => 'General Category',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $this->serializedProduct = Product::create([
            'product_name' => 'Serialized Consignment Widget',
            'product_code' => 'SCW-001',
            'product_price' => 200000,
            'product_cost' => 150000,
            'product_quantity' => 0,
            'product_unit' => $unit->id,
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'stock_managed' => true,
            'serial_number_required' => true,
            'setting_id' => $this->setting->id,
        ]);
    }

    protected function createSerializedSetup(): array
    {
        // 1. Receiving
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-001',
            'receival_number' => 'CR-001',
            'date' => date('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->serializedProduct->id,
            'product_name' => $this->serializedProduct->product_name,
            'product_code' => $this->serializedProduct->product_code,
            'quantity' => 2,
            'unit_cost' => 150000,
            'unit_dpp' => 150000,
            'subtotal_cost' => 300000,
            'subtotal_dpp' => 300000,
            'total_cost' => 300000,
            'total_dpp' => 300000,
            'is_serialized' => true,
        ]);
        $receiving = ConsignmentReceiving::create([
            'consignment_receival_id' => $receival->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->consignmentLocation->id,
            'receiving_number' => 'RCV-001',
            'date' => date('Y-m-d'),
            'status' => ConsignmentReceiving::STATUS_APPROVED,
        ]);
        $receivingDetail = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $receivalLine->id,
            'product_id' => $this->serializedProduct->id,
            'quantity_received' => 2,
            'unit_cost' => 150000,
            'unit_dpp' => 150000,
            'is_serialized' => true,
        ]);
        
        $psn1 = ProductSerialNumber::create([
            'product_id' => $this->serializedProduct->id,
            'setting_id' => $this->setting->id,
            'serial_number' => 'SN-C1',
            'status' => 'Available',
            'location_id' => $this->consignmentLocation->id,
            'consignment_receiving_detail_id' => $receivingDetail->id,
        ]);
        $psn2 = ProductSerialNumber::create([
            'product_id' => $this->serializedProduct->id,
            'setting_id' => $this->setting->id,
            'serial_number' => 'SN-C2',
            'status' => 'Available',
            'location_id' => $this->consignmentLocation->id,
            'consignment_receiving_detail_id' => $receivingDetail->id,
        ]);

        // 2. Sale
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'General Customer',
            'reference' => 'SL-SERIAL-01',
            'date' => date('Y-m-d'),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 400000,
            'paid_amount' => 400000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);
        
        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->serializedProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 2,
            'is_inventory_managed' => true,
        ]);
        
        app(ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($this->setting->id);
        
        $source = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();
        
        ConsignmentSoldSourceSerial::create([
            'consignment_sold_source_id' => $source->id,
            'product_serial_number_id' => $psn1->id,
        ]);
        ConsignmentSoldSourceSerial::create([
            'consignment_sold_source_id' => $source->id,
            'product_serial_number_id' => $psn2->id,
        ]);

        return [
            'sale' => $sale,
            'dispatchDetail' => $dd,
            'source' => $source,
            'psn1' => $psn1,
            'psn2' => $psn2,
            'receivingDetail' => $receivingDetail,
        ];
    }

    public function test_confirmation_create_renders_with_serialized_sources()
    {
        $setup = $this->createSerializedSetup();

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('consignments.confirmations.create', ['supplier_id' => $this->supplier->id]));

        $response->assertOk();
        $response->assertViewHas('eligibleSources');
        $sources = $response->viewData('eligibleSources');
        $this->assertCount(1, $sources);
        
        $source = $sources[0];
        $this->assertCount(2, $source->resolved_serials);
        $this->assertEquals('SN-C1', $source->resolved_serials[0]['serial_number']);
        $this->assertEquals('SN-C2', $source->resolved_serials[1]['serial_number']);
    }

    public function test_confirmation_edit_renders_with_serialized_sources()
    {
        $setup = $this->createSerializedSetup();
        
        // Draft a confirmation programmatically
        $lifecycleService = app(ConsignmentBillingConfirmationLifecycleService::class);
        $confirmation = $lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'selected' => true,
                    'consignment_sold_source_id' => $setup['source']->id,
                    'allocated_base_quantity' => 2,
                    'receipt_allocations' => [
                        [
                            'consignment_receiving_detail_id' => $setup['receivingDetail']->id,
                            'allocated_base_quantity' => 2,
                        ]
                    ],
                    'serialized_allocations' => [
                        [
                            'selected' => true,
                            'product_serial_number_id' => $setup['psn1']->id,
                            'consignment_receiving_detail_id' => $setup['receivingDetail']->id,
                        ],
                        [
                            'selected' => true,
                            'product_serial_number_id' => $setup['psn2']->id,
                            'consignment_receiving_detail_id' => $setup['receivingDetail']->id,
                        ]
                    ]
                ]
            ],
            'Test Draft',
            $this->user->id
        );

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('consignments.confirmations.edit', $confirmation->id));

        $response->assertOk();
        $response->assertViewHas('eligibleSources');
        $sources = $response->viewData('eligibleSources');
        $this->assertCount(1, $sources);
        
        $source = $sources[0];
        $this->assertCount(2, $source->resolved_serials);
    }

    public function test_reconciliation_filtering_by_sale_reference()
    {
        $setup = $this->createSerializedSetup();
        
        // Create an allocation to make it appear in reconciliation
        $lifecycleService = app(ConsignmentBillingConfirmationLifecycleService::class);
        $confirmation = $lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'selected' => true,
                    'consignment_sold_source_id' => $setup['source']->id,
                    'allocated_base_quantity' => 1,
                    'receipt_allocations' => [
                        [
                            'consignment_receiving_detail_id' => $setup['receivingDetail']->id,
                            'allocated_base_quantity' => 1,
                        ]
                    ],
                    'serialized_allocations' => [
                        [
                            'selected' => true,
                            'product_serial_number_id' => $setup['psn1']->id,
                            'consignment_receiving_detail_id' => $setup['receivingDetail']->id,
                        ]
                    ]
                ]
            ],
            'Test Draft',
            $this->user->id
        );
        $lifecycleService->submitConfirmation($confirmation, $this->user->id);
        $lifecycleService->approveConfirmation($confirmation, $this->user->id);

        // Fetch without filter
        $response1 = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('consignments.reconciliation.index'));
        $response1->assertOk();
        $response1->assertSee("<span class='badge badge-light border'>{$setup['sale']->reference} <span class='text-info ml-1'>(Sold: ", false);

        // Fetch with Sale reference filter
        $response2 = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('consignments.reconciliation.index', ['transaction_reference' => 'SL-SERIAL-01']));
        $response2->assertOk();
        $response2->assertSee("<span class='badge badge-light border'>{$setup['sale']->reference} <span class='text-info ml-1'>(Sold: ", false);

        // Fetch with mismatch filter
        $response3 = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('consignments.reconciliation.index', ['transaction_reference' => 'NONEXISTENT']));
        $response3->assertOk();
        $response3->assertDontSee($setup['sale']->reference);
    }
    
    public function test_reconciliation_filtering_by_pos_reference()
    {
        $setup = $this->createSerializedSetup();
        
        $posSession = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $this->setting->id,
            'user_id' => $this->user->id,
            'cashier_user_id' => $this->user->id,
            'location_id' => $this->consignmentLocation->id,
            'opened_at' => now(),
            'status' => 'OPEN'
        ]);

        $posTx = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'POS-12345',
            'status' => 'COMPLETED',
            'date' => date('Y-m-d'),
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $posSession->id,
        ]);
        $terminal = \Modules\Pos\Entities\PosTerminal::create([
            'setting_id' => $this->setting->id,
            'code' => 'T01',
            'name' => 'Terminal 01',
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $posTx->id,
            'pos_session_id' => $posSession->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'idempotency_key' => 'idemp_key_12345',
            'payload_hash' => 'hash123',
            'status' => 'POSTED',
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $setup['sale']->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->consignmentLocation->id,
            'split_key' => 'none',
            'tax_bucket' => 'NON_TAX',
        ]);
        
        \Modules\Consignment\Entities\ConsignmentSoldSource::query()->delete();
        app(\Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($this->setting->id);
        $setup['source'] = \Modules\Consignment\Entities\ConsignmentSoldSource::first();
        
        $lifecycleService = app(ConsignmentBillingConfirmationLifecycleService::class);
        
        // Create an allocation to make it appear in reconciliation
        $confirmation = $lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'selected' => true,
                    'consignment_sold_source_id' => $setup['source']->id,
                    'allocated_base_quantity' => 1,
                    'receipt_allocations' => [
                        [
                            'consignment_receiving_detail_id' => $setup['receivingDetail']->id,
                            'allocated_base_quantity' => 1,
                        ]
                    ],
                    'serialized_allocations' => [
                        [
                            'selected' => true,
                            'product_serial_number_id' => $setup['psn2']->id,
                            'consignment_receiving_detail_id' => $setup['receivingDetail']->id,
                        ]
                    ]
                ]
            ],
            'Test Draft',
            $this->user->id
        );
        $lifecycleService->submitConfirmation($confirmation, $this->user->id);
        $lifecycleService->approveConfirmation($confirmation, $this->user->id);

        // Fetch with POS reference filter
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('consignments.reconciliation.index', ['transaction_reference' => 'POS-12345']));
        
        $response->assertOk();
        $response->assertSee("<span class='badge badge-light border'>POS-12345 <span class='text-info ml-1'>(Sold: ", false);
    }
}
