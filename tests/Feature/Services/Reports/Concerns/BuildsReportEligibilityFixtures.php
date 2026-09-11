<?php

namespace Tests\Feature\Services\Reports\Concerns;

use App\Models\User;
use Carbon\Carbon;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;

trait BuildsReportEligibilityFixtures
{
    private int $referenceCounter = 0;

    private function makeSetting(): Setting
    {
        return Setting::factory()->create();
    }

    private function makeCustomer(Setting $setting): Customer
    {
        return Customer::factory()->create(['setting_id' => $setting->id]);
    }

    private function makeSupplier(Setting $setting): Supplier
    {
        return Supplier::factory()->create(['setting_id' => $setting->id]);
    }

    private function makeUnit(Setting $setting): Unit
    {
        return Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $setting->id,
        ]);
    }

    private function makeCategory(Setting $setting): Category
    {
        $user = User::factory()->create();

        return Category::create([
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Category',
            'setting_id' => $setting->id,
            'created_by' => $user->id,
        ]);
    }

    private function makeTax(int $value = 10): Tax
    {
        return Tax::create([
            'name' => "VAT {$value}%",
            'value' => $value,
        ]);
    }

    private function makeProduct(Setting $setting, Category $category, Unit $unit, array $overrides = []): Product
    {
        $this->referenceCounter++;

        return Product::create(array_merge([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'Product ' . $this->referenceCounter,
            'product_code' => 'PRD-' . $this->referenceCounter,
            'product_quantity' => 100,
            'product_cost' => 5.00,
            'product_price' => 10.00,
            'product_unit' => 'PCS',
            'product_stock_alert' => 5,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'stock_managed' => true,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ], $overrides));
    }

    private function makeSale(Setting $setting, Customer $customer, string $status, ?Carbon $archivedAt = null, array $overrides = []): Sale
    {
        $this->referenceCounter++;
        $date = $overrides['date'] ?? Carbon::parse('2026-01-15');

        return Sale::create(array_merge([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'reference' => "SALE-{$this->referenceCounter}",
            'customer_name' => $customer->customer_name,
            'date' => $date,
            'due_date' => Carbon::parse($date)->copy()->addDays(30),
            'status' => $status,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'archived_at' => $archivedAt,
        ], $overrides));
    }

    private function makePurchase(Setting $setting, Supplier $supplier, string $status, ?Carbon $archivedAt = null, array $overrides = []): Purchase
    {
        $this->referenceCounter++;
        $date = $overrides['date'] ?? Carbon::parse('2026-01-15');

        return Purchase::create(array_merge([
            'setting_id' => $setting->id,
            'supplier_id' => $supplier->id,
            'reference' => "PURCHASE-{$this->referenceCounter}",
            'supplier_name' => $supplier->supplier_name,
            'date' => $date,
            'due_date' => Carbon::parse($date)->copy()->addDays(30),
            'status' => $status,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'archived_at' => $archivedAt,
        ], $overrides));
    }

    private function makeSaleDetail(Sale $sale, Product $product, array $overrides = []): SaleDetails
    {
        return SaleDetails::create(array_merge([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ], $overrides));
    }

    private function makePurchaseDetail(Purchase $purchase, Product $product, array $overrides = []): PurchaseDetail
    {
        return PurchaseDetail::create(array_merge([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ], $overrides));
    }
}
