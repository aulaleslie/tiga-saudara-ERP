<?php

namespace Modules\Pos\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;

class POSReturnModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_pos_return_and_access_relationships()
    {
        $posReturn = PosReturn::create([
            'reference' => 'PR-001',
            'setting_id' => 1,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TRX001',
            'receipt_number' => 'RCP001',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_DRAFT,
            'approval_status' => PosReturn::APPROVAL_STATUS_DRAFT,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash',
            'total_amount' => 100.00,
        ]);

        $line = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'sale_id' => 1,
            'sale_detail_id' => 1,
            'source_setting_id' => 1,
            'source_location_id' => 1,
            'product_id' => 1,
            'product_name' => 'Product 1',
            'product_code' => 'P001',
            'quantity' => 1,
            'unit_price' => 100,
            'line_total' => 100,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $this->assertCount(1, $posReturn->lines);
        $this->assertEquals($posReturn->id, $line->posReturn->id);
    }

    /** @test */
    public function it_can_link_to_sale_returns()
    {
        \Illuminate\Support\Facades\DB::table('customers')->insert([
            'id' => 1,
            'customer_name' => 'Customer 1',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '123456',
            'address' => 'Address',
            'city' => 'City',
            'country' => 'Country',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $posReturn = PosReturn::create([
            'reference' => 'PR-001',
            'setting_id' => 1,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TRX001',
            'receipt_number' => 'RCP001',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_DRAFT,
            'approval_status' => PosReturn::APPROVAL_STATUS_DRAFT,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash',
            'total_amount' => 100.00,
        ]);

        \Illuminate\Support\Facades\DB::table('sale_returns')->insert([
            'pos_return_id' => $posReturn->id,
            'sale_id' => 1,
            'location_id' => 1,
            'setting_id' => 1,
            'customer_id' => 1,
            'customer_name' => 'Customer 1',
            'date' => now(),
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'return_type' => 'Return',
            'approval_status' => 'pending',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'reference' => 'REF-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleReturn = SaleReturn::where('pos_return_id', $posReturn->id)->first();

        $this->assertCount(1, $posReturn->saleReturns);
        $this->assertEquals($posReturn->id, $saleReturn->posReturn->id);
    }
}
