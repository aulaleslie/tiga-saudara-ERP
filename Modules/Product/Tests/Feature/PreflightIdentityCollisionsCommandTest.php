<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Product\Entities\ProductPrice;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class PreflightIdentityCollisionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(array $attributes = [])
    {
        return Product::create(array_merge([
            'setting_id' => 1,
            'unit_id' => 1,
            'base_unit_id' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
            'stock_managed' => 1,
            'is_purchased' => 1,
            'is_sold' => 1,
        ], $attributes));
    }

    public function test_it_reports_no_collisions_when_all_products_are_unique_and_proves_no_mutations()
    {
        $this->createProduct(['product_name' => 'Apple', 'canonical_name' => 'apple', 'product_code' => 'P1']);
        $this->createProduct(['product_name' => 'Banana', 'canonical_name' => 'banana', 'product_code' => 'P2']);

        $beforeProducts = DB::table('products')->get();

        $this->artisan('product:identity-preflight')
            ->expectsOutput('Scanning products for canonical identity collisions...')
            ->expectsOutput('No canonical identity collisions found. All products are unique.')
            ->assertExitCode(0);

        $afterProducts = DB::table('products')->get();
        $this->assertEquals($beforeProducts, $afterProducts, 'Products table was mutated during preflight');
    }

    public function test_it_reports_collisions_and_reference_counts()
    {
        DB::table('settings')->insertOrIgnore(['id' => 1, 'company_name' => 'Setting 1']);
        DB::table('settings')->insertOrIgnore(['id' => 2, 'company_name' => 'Setting 2']);

        $p1 = $this->createProduct([
            'product_name' => 'Red Apple',
            'product_code' => 'APL-1',
            'canonical_name' => null
        ]);

        $p2 = $this->createProduct([
            'product_name' => 'red apple ',
            'product_code' => 'APL-2',
            'canonical_name' => 'red apple'
        ]);

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        
        DB::table('customers')->insert([
            'id' => 1,
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@test.com',
            'customer_phone' => '123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address'
        ]);

        DB::table('sales')->insert([
            'id' => 1,
            'date' => now(),
            'reference' => 'SALE-1',
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 10,
            'paid_amount' => 10,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash'
        ]);

        DB::table('sale_details')->insert([
            'product_id' => $p2->id,
            'product_name' => $p2->product_name,
            'product_code' => $p2->product_code,
            'sale_id' => 1,
            'quantity' => 1,
            'price' => 10,
            'unit_price' => 10,
            'sub_total' => 10,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $beforeProducts = DB::table('products')->get();
        $beforeSaleDetails = DB::table('sale_details')->get();

        $this->artisan('product:identity-preflight')
            ->expectsOutput('Scanning products for canonical identity collisions...')
            ->expectsOutput('Found 1 canonical identity collisions.')
            ->expectsOutput('Canonical Key: red apple')
            ->expectsTable(
                ['ID', 'Name', 'Code', 'DB Canonical', 'Ref Counts (Supported)', 'Ref Counts (Unsupported)'],
                [
                    [$p1->id, 'Red Apple', 'APL-1', 'NULL', 'None', 'None'],
                    [$p2->id, 'red apple ', 'APL-2', 'red apple', "sale_details: 1", 'None'],
                ]
            )
            ->assertExitCode(0);

        $afterProducts = DB::table('products')->get();
        $afterSaleDetails = DB::table('sale_details')->get();
        
        $this->assertEquals($beforeProducts, $afterProducts, 'Products table was mutated during preflight');
        $this->assertEquals($beforeSaleDetails, $afterSaleDetails, 'Sale Details table was mutated during preflight');
    }

    public function test_it_reports_invalid_stored_names_and_proves_no_mutations()
    {
        $p = $this->createProduct([
            'product_name' => '   ', // Will fail canonicalization
            'product_code' => 'INV-1'
        ]);

        $beforeProducts = DB::table('products')->get();

        $this->artisan('product:identity-preflight')
            ->expectsOutput('Scanning products for canonical identity collisions...')
            ->expectsOutput('Found 1 product identities that cannot be canonicalized.')
            ->expectsOutput('Un-canonicalizable Identities:')
            ->assertExitCode(0);

        $afterProducts = DB::table('products')->get();
        
        $this->assertEquals($beforeProducts, $afterProducts, 'Products table was mutated during preflight');
    }
}
