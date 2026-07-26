<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\People\Entities\Customer;
use Modules\PurchasesReturn\Entities\PurchaseReturn;

class NormalizeDecimalRoundtripTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $currency;
    protected $customer;
    protected $product;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup permissions and roles
        Permission::firstOrCreate(['name' => 'quotations.access']);
        Permission::firstOrCreate(['name' => 'quotations.create']);
        Permission::firstOrCreate(['name' => 'quotations.edit']);
        Permission::firstOrCreate(['name' => 'purchase_returns.access']);
        Permission::firstOrCreate(['name' => 'purchase_returns.create']);
        Role::firstOrCreate(['name' => 'Admin']);

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test',
            'company_email' => 'test@test.com',
            'company_phone' => '123',
            'notification_email' => 'test@test.com',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->customer = Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Test Customer',
            'customer_email' => 'c@test.com',
            'customer_phone' => '123',
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_cost' => 100000,
            'product_price' => 500000,
        ]);

        // Create and authenticate user
        $this->user = User::factory()->create();
        $adminRole = Role::where('name', 'Admin')->first();
        $this->user->assignRole($adminRole);
        $this->user->givePermissionTo(['quotations.access', 'quotations.create', 'quotations.edit', 'purchase_returns.access', 'purchase_returns.create']);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $adminRole->id]);

        session(['setting_id' => $this->setting->id]);
    }

    /** @test */
    public function quotation_controller_creates_without_scaling_monetary_values()
    {
        // Verify that the controller method at line 54 passes request->total_amount
        // directly to Quotation::create() WITHOUT multiplying by 100
        //
        // This test will FAIL if the controller has: $request->total_amount * 100
        // This test will PASS if the controller has: $request->total_amount

        $reflectionClass = new \ReflectionClass(\Modules\Quotation\Http\Controllers\QuotationController::class);
        $method = $reflectionClass->getMethod('store');
        $filename = $reflectionClass->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        // Verify that the total_amount assignment does NOT have * 100
        $this->assertStringNotContainsString("'total_amount' => \$request->total_amount * 100",
            $methodSource,
            'QuotationController::store() must NOT multiply total_amount by 100');

        // Verify it assigns the value directly
        $this->assertStringContainsString("'total_amount' => \$request->total_amount",
            $methodSource,
            'QuotationController::store() must assign total_amount directly from request');
    }

    /** @test */
    public function purchase_return_payment_controller_creates_without_scaling()
    {
        // Verify that PurchaseReturnPaymentsController::store() at line 50 passes
        // request->amount directly WITHOUT multiplying by 100
        //
        // This test will FAIL if the controller has: 'amount' => $request->amount * 100
        // This test will PASS if the controller has: 'amount' => $request->amount

        $reflectionClass = new \ReflectionClass(
            \Modules\PurchasesReturn\Http\Controllers\PurchaseReturnPaymentsController::class
        );
        $method = $reflectionClass->getMethod('store');
        $filename = $reflectionClass->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        // Verify that the amount assignment does NOT have * 100
        $this->assertStringNotContainsString("'amount' => \$request->amount * 100",
            $methodSource,
            'PurchaseReturnPaymentsController::store() must NOT multiply amount by 100');

        // Verify it assigns the value directly
        $this->assertStringContainsString("'amount' => \$request->amount",
            $methodSource,
            'PurchaseReturnPaymentsController::store() must assign amount directly from request');
    }
}
