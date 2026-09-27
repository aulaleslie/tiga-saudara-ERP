<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Setting;
use App\Models\User;

try {
    $setting = Setting::first() ?: Setting::create([
        'company_name' => 'Test',
        'company_email' => 'test@test.com',
        'company_phone' => '123',
        'notification_email' => 'test@test.com',
        'default_currency_id' => 1,
        'default_currency_position' => 'prefix',
        'footer_text' => 'test',
        'company_address' => 'test',
    ]);

    $user = User::first() ?: User::factory()->create();

    $category = Category::create([
        'category_code' => 'TEST_CAT',
        'category_name' => 'Test Category',
        'created_by' => $user->id,
        'setting_id' => $setting->id,
    ]);

    $product = Product::create([
        'product_name' => 'Test Product',
        'product_code' => 'TEST-001',
        'slug' => 'test-product',
        'product_barcode_symbology' => 'code128',
        'product_quantity' => 100,
        'product_cost' => 100,
        'product_price' => 200,
        'product_unit' => 'pcs',
        'product_stock_alert' => 10,
        'setting_id' => $setting->id,
        'category_id' => $category->id,
        'is_stock_managed' => true,
    ]);
    echo "Product created successfully\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
