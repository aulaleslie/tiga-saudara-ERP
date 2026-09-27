<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class OriginRelativeMediaUrlTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->setting = Setting::factory()->create(['is_pkp' => false]);
    }

    public function test_user_avatar_url_is_origin_relative(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('avatar.jpg');

        $media = $user->addMedia($file)->toMediaCollection('avatars');

        $url = $user->getFirstMediaUrl('avatars');
        $this->assertStringStartsWith('/storage/', $url);
        $this->assertStringNotContainsString('http://', $url);
        $this->assertStringNotContainsString('https://', $url);
        $this->assertEquals('/storage/' . $media->id . '/avatar.jpg', $url);
    }

    public function test_product_image_url_is_origin_relative(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'PRD-001',
            'product_quantity' => 10,
            'product_cost' => 100,
            'product_price' => 150,
            'product_unit' => 'pc',
        ]);
        $file = UploadedFile::fake()->image('item.png');

        $media = $product->addMedia($file)->toMediaCollection('images');

        $url = $product->getFirstMediaUrl('images');
        $this->assertStringStartsWith('/storage/', $url);
        $this->assertStringNotContainsString('http://', $url);
        $this->assertStringNotContainsString('https://', $url);
        $this->assertEquals('/storage/' . $media->id . '/item.png', $url);
    }

    public function test_expense_attachment_url_is_origin_relative(): void
    {
        $category = ExpenseCategory::create([
            'category_name' => 'Test Category',
            'category_description' => 'Test Desc',
            'setting_id' => $this->setting->id,
        ]);

        $expense = Expense::create([
            'setting_id' => $this->setting->id,
            'date' => now()->toDateString(),
            'reference' => 'EXP-001',
            'amount' => 50000,
            'category_id' => $category->id,
            'details' => 'Office supply',
            'status' => Expense::STATUS_DRAFT,
        ]);
        $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

        $media = $expense->addMedia($file)->toMediaCollection('attachments');

        $url = $expense->getFirstMediaUrl('attachments');
        $this->assertStringStartsWith('/storage/', $url);
        $this->assertStringNotContainsString('http://', $url);
        $this->assertStringNotContainsString('https://', $url);
        $this->assertEquals('/storage/' . $media->id . '/receipt.pdf', $url);
    }

    public function test_purchase_payment_attachment_url_is_origin_relative(): void
    {
        $supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'reference' => 'PR-001',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $payment = PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 5000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
        ]);

        $file = UploadedFile::fake()->image('payment_receipt.jpg');
        $media = $payment->addMedia($file)->toMediaCollection('attachments');

        $url = $payment->getFirstMediaUrl('attachments');
        $this->assertStringStartsWith('/storage/', $url);
        $this->assertStringNotContainsString('http://', $url);
        $this->assertStringNotContainsString('https://', $url);
        $this->assertEquals('/storage/' . $media->id . '/payment_receipt.jpg', $url);
    }

    public function test_sale_payment_attachment_url_is_origin_relative(): void
    {
        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'date' => now()->toDateString(),
            'reference' => 'SL-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $salePayment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 5000,
            'date' => now()->toDateString(),
            'reference' => 'SPAY-001',
            'payment_method' => 'Cash',
        ]);

        $file = UploadedFile::fake()->image('transfer_proof.png');
        $media = $salePayment->addMedia($file)->toMediaCollection('attachments');

        $url = $salePayment->getFirstMediaUrl('attachments');
        $this->assertStringStartsWith('/storage/', $url);
        $this->assertStringNotContainsString('http://', $url);
        $this->assertStringNotContainsString('https://', $url);
        $this->assertEquals('/storage/' . $media->id . '/transfer_proof.png', $url);
    }

    public function test_purchase_return_attachment_url_is_origin_relative(): void
    {
        $supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);

        $purchaseReturn = PurchaseReturn::create([
            'setting_id' => $this->setting->id,
            'date' => now()->toDateString(),
            'reference' => 'PRT-001',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $file = UploadedFile::fake()->image('return_awb.jpg');
        $media = $purchaseReturn->addMedia($file)->toMediaCollection('return_awb_attachments');

        $url = $purchaseReturn->getFirstMediaUrl('return_awb_attachments');
        $this->assertStringStartsWith('/storage/', $url);
        $this->assertStringNotContainsString('http://', $url);
        $this->assertStringNotContainsString('https://', $url);
        $this->assertEquals('/storage/' . $media->id . '/return_awb.jpg', $url);
    }
}
