<?php

namespace Modules\SalesReturn\Tests\Feature;

use App\Livewire\SalesReturn\SaleReturnCreateForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleReturnDispatchLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_selection_includes_dispatch_details_linked_via_dispatch(): void
    {
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '0800000000',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Some Address',
        ]);

        session()->put('setting_id', $setting->id);

        $product = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Sample Product',
            'product_code' => 'PRD-001',
            'product_quantity' => 10,
            'serial_number_required' => true,
            'product_cost' => 5.50,
            'product_price' => 15.00,
            'product_stock_alert' => 2,
            'stock_managed' => true,
            'sale_price' => 15.00,
            'tier_1_price' => 15.00,
            'tier_2_price' => 15.00,
        ]);

        $sale = Sale::create([
            'date' => now()->format('Y-m-d'),
            'customer_name' => 'Acme Corp',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $setting->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 15000,
            'unit_price' => 15000,
            'sub_total' => 75000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $otherSale = Sale::create([
            'date' => now()->format('Y-m-d'),
            'customer_name' => 'Other Customer',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $setting->id,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
        ]);

        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $otherSale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 3,
            'serial_numbers' => json_encode([]),
        ]);

        Livewire::test(SaleReturnCreateForm::class)
            ->call('handleSaleSelected', ['id' => $sale->id])
            ->assertSet('rows', function ($rows) use ($dispatchDetail, $product) {
                $rows = collect($rows);

                $matchingRow = $rows->firstWhere('dispatch_detail_id', $dispatchDetail->id);

                if (! $matchingRow) {
                    return false;
                }

                return $matchingRow['product_id'] === $product->id
                    && $matchingRow['product_name'] === $product->product_name
                    && array_key_exists('serial_numbers', $matchingRow)
                    && is_array($matchingRow['serial_numbers'])
                    && $matchingRow['serial_number_required'] === true;
            });
    }
}

