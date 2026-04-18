<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalesDispatchTaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createSetup()
    {
        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
        ]);

        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create(['setting_id' => $setting->id, 'payment_term_id' => $paymentTerm->id]);
        
        Permission::firstOrCreate(['name' => 'sales.dispatch']);
        Permission::firstOrCreate(['name' => 'salesDispatches.approval']);
        
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.dispatch', 'salesDispatches.approval']);

        $category = \Modules\Product\Entities\Category::create([
            'category_name' => 'Category', 
            'category_code' => 'CAT', 
            'setting_id' => $setting->id,
            'created_by' => $user->id
        ]);
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Unit', 
            'short_name' => 'U', 
            'operator' => '*', 
            'operation_value' => 1, 
            'setting_id' => $setting->id,
        ]);

        $location = Location::create([
            'name' => 'Warehouse 1',
            'setting_id' => $setting->id,
        ]);

        $tax1 = Tax::create(['name' => 'PPN 11%', 'value' => 11]);
        $tax2 = Tax::create(['name' => 'PPN 12%', 'value' => 12]);

        return [$setting, $user, $customer, $location, $tax1, $tax2, $paymentTerm, $category, $unit];
    }

    public function test_serial_number_relaxed_tax_matching_but_strict_segregation(): void
    {
        [$setting, $user, $customer, $location, $tax1, $tax2, $paymentTerm, $category, $unit] = $this->createSetup();

        $product = Product::create([
            'product_name' => 'SN Product',
            'product_code' => 'SN-001',
            'setting_id' => $setting->id,
            'product_quantity' => 1,
            'product_cost' => 1000,
            'product_price' => 1500,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
            'serial_number_required' => true,
        ]);

        // SN with Tax 1
        $sn = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => 'SERIAL-TAX-1',
            'tax_id' => $tax1->id,
            'status' => 'ACTIVE',
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'quantity_tax' => 1,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // 1. SUCCESS: Sale with Tax 2 (Relaxed matching because both are Taxed)
        $saleTaxed = Sale::create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-TAXED',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        SaleDetails::create([
            'sale_id' => $saleTaxed->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => $tax2->id,
        ]);

        $compositeKey = $product->id . '-' . $tax2->id . '-0';
        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$compositeKey => 1],
            'selectedSerialNumbers' => [$compositeKey => ['SERIAL-TAX-1']],
            'serialNumberLocations' => [$compositeKey => ['SERIAL-TAX-1' => $location->id]],
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $saleTaxed), $payload);

        $response->assertRedirect();
        $this->assertDatabaseHas('dispatch_details', ['sale_id' => $saleTaxed->id]);

        // 2. FAILURE: Sale with No Tax (Strict segregation)
        $saleNoTax = Sale::create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-NOTAX',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        SaleDetails::create([
            'sale_id' => $saleNoTax->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $compositeKeyNoTax = $product->id . '--0';
        $payloadNoTax = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$compositeKeyNoTax => 1],
            'selectedSerialNumbers' => [$compositeKeyNoTax => ['SERIAL-TAX-1']],
            'serialNumberLocations' => [$compositeKeyNoTax => ['SERIAL-TAX-1' => $location->id]],
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $saleNoTax), $payloadNoTax);

        $response->assertSessionHasErrors("selectedSerialNumbers.$compositeKeyNoTax");
    }

    public function test_non_serial_strict_segregation(): void
    {
        [$setting, $user, $customer, $location, $tax1, $tax2, $paymentTerm, $category, $unit] = $this->createSetup();

        $product = Product::create([
            'product_name' => 'Non-SN Product',
            'product_code' => 'NSN-001',
            'setting_id' => $setting->id,
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 1500,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
            'serial_number_required' => false,
        ]);

        // Stock ONLY in quantity_non_tax
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Sale WITH Tax
        $saleTaxed = Sale::create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-TAXED-NSN',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        SaleDetails::create([
            'sale_id' => $saleTaxed->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 7500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => $tax1->id,
        ]);

        $compositeKey = $product->id . '-' . $tax1->id . '-0';
        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$compositeKey => 5],
            'selectedLocations' => [$compositeKey => $location->id],
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $saleTaxed), $payload);

        // Should FAIL because no stock in quantity_tax
        $response->assertSessionHasErrors("dispatchedQuantities.$compositeKey");
    }

    public function test_stock_adjustment_decrements_correct_pool_for_serial_numbers(): void
    {
        [$setting, $user, $customer, $location, $tax1, $tax2, $paymentTerm, $category, $unit] = $this->createSetup();

        $product = Product::create([
            'product_name' => 'SN Stock Adj Product',
            'product_code' => 'SSA-001',
            'setting_id' => $setting->id,
            'product_quantity' => 2,
            'product_cost' => 1000,
            'product_price' => 1500,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
            'serial_number_required' => true,
        ]);

        $sn1 = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => 'S1',
            'tax_id' => $tax1->id,
            'status' => 'ACTIVE',
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 2,
            'quantity_tax' => 2,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $sale = Sale::create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-ADJ',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => $tax2->id, // Sale uses Tax 2, SN has Tax 1
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        $detail = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $location->id,
            'tax_id' => $tax2->id,
            'serial_numbers' => json_encode(['S1']),
        ]);

        // Approve Dispatch
        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch));

        $stock->refresh();
        $sn1->refresh();
        // Since S1 had a tax_id (it was taxed), quantity_tax should decrement.
        $this->assertEquals(1, $stock->quantity_tax);
        $this->assertEquals(1, $stock->quantity);
        $this->assertEquals($detail->id, $sn1->dispatch_detail_id);
        $this->assertEquals(ProductSerialNumber::STATUS_SOLD, $sn1->status);

        $tracking = SalesOrderSerialTracking::query()
            ->where('sale_id', $sale->id)
            ->where('product_serial_number_id', $sn1->id)
            ->first();

        $this->assertNotNull($tracking);
        $this->assertNull($tracking->return_date);
        $this->assertNotNull($tracking->dispatch_date);
        $this->assertSame(now()->toDateString(), $tracking->dispatch_date->toDateString());
    }
}
