<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ReturnPrefixTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'company_name'              => 'Test Company',
            'company_email'             => 'test@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'test@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => '123 Street',
            'document_prefix'           => 'ABC',
            'purchase_return_prefix_document' => 'PRX',
            'sale_return_prefix_document'     => 'SRX',
        ]);
    }

    public function test_purchase_return_uses_configured_prefixes(): void
    {
        $purchaseReturn = PurchaseReturn::create([
            'setting_id' => $this->setting->id,
            'date' => now(),
            'supplier_name' => 'Test Supplier',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $year = now()->year;
        $month = str_pad(now()->month, 2, '0', STR_PAD_LEFT);
        
        $this->assertStringStartsWith("ABC-PRX-{$year}-{$month}-", $purchaseReturn->reference);
    }

    public function test_sale_return_uses_configured_prefixes(): void
    {
        $saleReturn = SaleReturn::create([
            'setting_id' => $this->setting->id,
            'date' => now(),
            'customer_name' => 'Test Customer',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $year = now()->year;
        $month = str_pad(now()->month, 2, '0', STR_PAD_LEFT);
        
        $this->assertStringStartsWith("ABC-SRX-{$year}-{$month}-", $saleReturn->reference);
    }

    public function test_return_reference_falls_back_when_prefixes_are_null(): void
    {
        $this->setting->update([
            'document_prefix' => null,
            'purchase_return_prefix_document' => null,
            'sale_return_prefix_document' => null,
        ]);

        $purchaseReturn = PurchaseReturn::create([
            'setting_id' => $this->setting->id,
            'date' => now(),
            'supplier_name' => 'Test Supplier',
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
        ]);

        $saleReturn = SaleReturn::create([
            'setting_id' => $this->setting->id,
            'date' => now(),
            'customer_name' => 'Test Customer',
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
        ]);

        $year = now()->year;
        $month = str_pad(now()->month, 2, '0', STR_PAD_LEFT);

        $this->assertStringStartsWith("PRRN-{$year}-{$month}-", $purchaseReturn->reference);
        $this->assertStringStartsWith("SLRN-{$year}-{$month}-", $saleReturn->reference);
    }
}
