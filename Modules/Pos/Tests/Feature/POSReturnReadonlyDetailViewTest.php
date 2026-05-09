<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Spatie\Permission\Models\Permission;

class POSReturnReadonlyDetailViewTest extends PosTransactionFeatureTestCase
{
    protected $setting;
    protected $user;
    protected $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('Readonly Detail Test');

        foreach ([
            'pos.returns.view',
            'pos.returns.edit',
            'pos.returns.approve',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = $this->createUserForSetting(
            $this->setting,
            'Readonly Detail Admin',
            ['pos.access', 'pos.returns.view', 'pos.returns.edit', 'pos.returns.approve']
        );

        [, $this->location] = $this->createTerminalWithLocation($this->setting);
    }

    /** @test */
    public function it_renders_the_detail_page_as_a_grouped_readonly_surface(): void
    {
        $fixture = $this->makeReadonlyReturnFixture();

        $this->actingAsInSetting($this->user, $this->setting);

        $response = $this->get(route('pos.returns.show', $fixture['pos_return']));

        $response->assertOk()
            ->assertSee('Informasi Transaksi:')
            ->assertSee('Pembayaran Asli:')
            ->assertSee('Informasi Tambahan:')
            ->assertSee('Linked Sales Returns')
            ->assertSee('Komponen Trace')
            ->assertSee('Tunai')
            ->assertSee('Ganti Produk')
            ->assertSee('SN-RETURN-001')
            ->assertSee('SN-REPLACE-001')
            ->assertSee('SR-READONLY-001')
            ->assertSee('Eksekusi Sales Return')
            ->assertSee('Audit &amp; Sumber Teknis', false)
            ->assertSee('Snapshot Transaksi Asli')
            ->assertSee('Edit')
            ->assertSee('Ajukan Persetujuan')
            ->assertDontSee('Scan SN pengganti...')
            ->assertDontSee('Simpan Draft Retur POS')
            ->assertDontSee('wire:click', false)
            ->assertDontSee('wire:model', false);
    }

    /** @test */
    public function it_marks_non_returned_snapshot_lines_inside_the_collapsed_snapshot_context(): void
    {
        $fixture = $this->makeReadonlyReturnFixture();

        $this->actingAsInSetting($this->user, $this->setting);

        $response = $this->get(route('pos.returns.show', $fixture['pos_return']));

        $response->assertOk()
            ->assertSee('MOUSE EXTRA')
            ->assertSee('Diretur')
            ->assertSee('Tidak Diretur');
    }

    protected function makeReadonlyReturnFixture(): array
    {
        $bundleProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'BUNDLE KIT',
            'product_code' => 'BND-01',
            'sale_price' => 2000000,
        ]);
        $serialProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'PHONE SERIAL',
            'product_code' => 'SER-01',
            'sale_price' => 5000000,
            'serial_number_required' => true,
        ]);
        $extraProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'MOUSE EXTRA',
            'product_code' => 'MSE-01',
            'sale_price' => 150000,
        ]);

        $returnedSerial = $this->createSerialNumber($serialProduct, $this->location, 'SN-RETURN-001');
        $replacementSerial = $this->createSerialNumber($serialProduct, $this->location, 'SN-REPLACE-001');

        $snapshot = [
            'header' => [
                'transaction_id' => 9001,
                'transaction_code' => 'TXN-READONLY-001',
                'checkout_id' => 7001,
                'receipt_number' => 'RCP-READONLY-001',
                'customer_name' => 'Readonly Customer',
                'date' => now()->toIso8601String(),
                'grand_total' => 9150000,
            ],
            'payments' => [
                ['method_name' => 'Tunai', 'amount' => 9150000],
            ],
            'lines' => [
                [
                    'checkout_sale_id' => 11,
                    'sale_id' => 501,
                    'sale_detail_id' => 701,
                    'dispatch_detail_id' => 801,
                    'pos_transaction_line_id' => 1101,
                    'product_id' => $bundleProduct->id,
                    'product_name' => $bundleProduct->product_name,
                    'product_code' => $bundleProduct->product_code,
                    'original_quantity' => 3,
                    'returned_quantity' => 0,
                    'returnable_quantity' => 3,
                    'unit_price' => 2000000,
                    'line_total' => 6000000,
                    'tax_id' => null,
                    'is_tracked' => false,
                    'serial_number_ids' => [],
                    'serial_numbers' => [],
                    'is_bundle' => true,
                    'bundle_items' => [
                        ['product_id' => 90001, 'product_name' => 'ADAPTOR KIT', 'product_code' => 'ADP-01', 'quantity' => 1],
                        ['product_id' => 90002, 'product_name' => 'USB CABLE', 'product_code' => 'USB-01', 'quantity' => 1],
                    ],
                    'is_zero_qty_component' => false,
                ],
                [
                    'checkout_sale_id' => 11,
                    'sale_id' => 502,
                    'sale_detail_id' => 702,
                    'dispatch_detail_id' => 802,
                    'pos_transaction_line_id' => 1102,
                    'product_id' => $serialProduct->id,
                    'product_name' => $serialProduct->product_name,
                    'product_code' => $serialProduct->product_code,
                    'original_quantity' => 1,
                    'returned_quantity' => 0,
                    'returnable_quantity' => 1,
                    'unit_price' => 5000000,
                    'line_total' => 5000000,
                    'tax_id' => null,
                    'is_tracked' => true,
                    'serial_number_ids' => [$returnedSerial->id],
                    'serial_numbers' => [
                        ['id' => $returnedSerial->id, 'serial_number' => $returnedSerial->serial_number],
                    ],
                    'is_bundle' => false,
                    'bundle_items' => [],
                    'is_zero_qty_component' => false,
                ],
                [
                    'checkout_sale_id' => 11,
                    'sale_id' => 503,
                    'sale_detail_id' => 703,
                    'dispatch_detail_id' => 803,
                    'pos_transaction_line_id' => null,
                    'product_id' => $extraProduct->id,
                    'product_name' => $extraProduct->product_name,
                    'product_code' => $extraProduct->product_code,
                    'original_quantity' => 2,
                    'returned_quantity' => 0,
                    'returnable_quantity' => 2,
                    'unit_price' => 150000,
                    'line_total' => 300000,
                    'tax_id' => null,
                    'is_tracked' => false,
                    'serial_number_ids' => [],
                    'serial_numbers' => [],
                    'is_bundle' => false,
                    'bundle_items' => [],
                    'is_zero_qty_component' => false,
                ],
            ],
        ];
        $snapshot['hash'] = 'readonly-hash-001';

        $posReturn = PosReturn::create([
            'reference' => 'PR-READONLY-001',
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 9001,
            'pos_checkout_id' => 7001,
            'transaction_code' => 'TXN-READONLY-001',
            'receipt_number' => 'RCP-READONLY-001',
            'customer_name' => 'Readonly Customer',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_DRAFT,
            'approval_status' => PosReturn::APPROVAL_STATUS_DRAFT,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'total_amount' => 4000000,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 11,
            'sale_id' => 501,
            'sale_detail_id' => 701,
            'dispatch_detail_id' => 801,
            'pos_transaction_line_id' => 1101,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'product_id' => $bundleProduct->id,
            'product_name' => $bundleProduct->product_name,
            'product_code' => $bundleProduct->product_code,
            'quantity' => 2,
            'unit_price' => 2000000,
            'line_total' => 4000000,
            'expected_cash_amount' => 4000000,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'line_meta' => [
                'bundle_trace' => [
                    ['product_id' => 90001, 'quantity_per_bundle' => 1, 'total_component_quantity' => 2],
                    ['product_id' => 90002, 'quantity_per_bundle' => 1, 'total_component_quantity' => 2],
                ],
            ],
        ]);

        $replacementLine = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 11,
            'sale_id' => 502,
            'sale_detail_id' => 702,
            'dispatch_detail_id' => 802,
            'pos_transaction_line_id' => 1102,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'product_id' => $serialProduct->id,
            'product_name' => $serialProduct->product_name,
            'product_code' => $serialProduct->product_code,
            'quantity' => 1,
            'unit_price' => 5000000,
            'line_total' => 5000000,
            'expected_cash_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'returned_serial_id' => $returnedSerial->id,
            'replacement_serial_id' => $replacementSerial->id,
        ]);

        $saleReturn = SaleReturn::create([
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => 502,
            'sale_reference' => 'SO-READONLY-001',
            'return_type' => 'Replacement',
            'customer_id' => null,
            'customer_name' => 'Readonly Customer',
            'reference' => 'SR-READONLY-001',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 5000000,
            'paid_amount' => 0,
            'due_amount' => 5000000,
            'status' => 'AWAITING DISPATCH',
            'approval_status' => 'approved',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $saleReturnDetail = SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $replacementLine->id,
            'product_id' => $serialProduct->id,
            'product_name' => $serialProduct->product_name,
            'product_code' => $serialProduct->product_code,
            'location_id' => $this->location->id,
            'quantity' => 1,
            'price' => 5000000,
            'unit_price' => 5000000,
            'sub_total' => 5000000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $replacementLine->update([
            'sale_return_id' => $saleReturn->id,
            'sale_return_detail_id' => $saleReturnDetail->id,
        ]);

        return [
            'pos_return' => $posReturn,
        ];
    }
}