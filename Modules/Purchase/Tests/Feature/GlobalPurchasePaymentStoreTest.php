<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\PaymentMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GlobalPurchasePaymentStoreTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting1;
    protected $setting2;
    protected $supplier;
    protected $supplier2;
    protected $purchase1;
    protected $purchase2;
    protected $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Currency ID 1 first
        \Modules\Currency\Entities\Currency::create([
            'id' => 1,
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ','
        ]);

        $this->setting1 = Setting::create([
            'company_name' => 'Setting 1',
            'company_email' => 'setting1@test.com',
            'company_phone' => '111',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'setting1@test.com',
            'company_address' => 'Addr 1',
            'footer_text' => 'Footer 1'
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Setting 2',
            'company_email' => 'setting2@test.com',
            'company_phone' => '222',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'setting2@test.com',
            'company_address' => 'Addr 2',
            'footer_text' => 'Footer 2'
        ]);

        Permission::firstOrCreate(['name' => 'purchasePayments.global.access']);
        Permission::firstOrCreate(['name' => 'purchasePayments.create']);
        
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $role->givePermissionTo(['purchasePayments.global.access', 'purchasePayments.create']);

        $this->user = User::factory()->create();
        $this->user->assignRole($role);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier Global ' . uniqid(),
            'supplier_email' => uniqid() . '@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Address',
            'setting_id' => $this->setting1->id,
        ]);
        
        $this->supplier2 = Supplier::create([
            'supplier_name' => 'Supplier Other ' . uniqid(),
            'supplier_email' => uniqid() . '@example.com',
            'supplier_phone' => '87654321',
            'city' => 'Bandung',
            'country' => 'Indonesia',
            'address' => 'Address 2',
            'setting_id' => $this->setting1->id,
        ]);

        $this->purchase1 = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PR-1',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'tax_rate' => 0,
            'setting_id' => $this->setting1->id,
        ]);
        
        $this->purchase2 = $this->purchase1->replicate();
        $this->purchase2->reference = 'PR-2';
        $this->purchase2->setting_id = $this->setting2->id; // different setting!
        $this->purchase2->status = \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED;
        $this->purchase2->save();
        $coa = \Modules\Setting\Entities\ChartOfAccount::create([
            'setting_id' => $this->setting1->id,
            'account_number' => 'COA-' . uniqid(),
            'name' => 'Cash in Bank',
            'category' => 'Kas & Bank',
        ]);
        
        $this->paymentMethod = PaymentMethod::create([
            'name' => 'Bank Transfer',
            'coa_id' => $coa->id,
            'is_active' => 1
        ]);
    }

    public function test_valid_atomic_multi_store()
    {
        $this->withoutExceptionHandling();
        $response = $this->actingAs($this->user)->post(route('purchases.global-payments.store', $this->supplier->id), [
            'reference' => 'GLOB-PAY-01',
            'date' => now()->format('Y-m-d'),
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [
                $this->purchase1->id => 1000, // full payment
                $this->purchase2->id => 500,  // partial payment
            ]
        ]);

        $response->assertSessionHasNoErrors();
        if ($response->headers->get('Location') === 'http://localhost:8000') {
            dump(session('toast_notification.message'));
        }

        $response->assertRedirect(route('purchases.global-payments.index'));

        // Check balances updated
        $this->purchase1->refresh();
        $this->assertEquals(0, $this->purchase1->due_amount);
        $this->assertEquals(1000, $this->purchase1->paid_amount);
        $this->assertEquals('PAID', $this->purchase1->payment_status);

        $this->purchase2->refresh();
        $this->assertEquals(500, $this->purchase2->due_amount);
        $this->assertEquals(500, $this->purchase2->paid_amount);
        $this->assertEquals('PARTIAL', $this->purchase2->payment_status);

        // Check payments created
        $this->assertEquals(2, PurchasePayment::count());
    }
    
    public function test_rejects_allocation_exceeding_balance()
    {
        $response = $this->actingAs($this->user)->post(route('purchases.global-payments.store', $this->supplier->id), [
            'reference' => 'GLOB-PAY-01',
            'date' => now()->format('Y-m-d'),
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [
                $this->purchase1->id => 1500, // exceeds 1000
                $this->purchase2->id => 500,
            ]
        ]);

        // Because of atomic transaction, neither should be updated
        $this->purchase2->refresh();
        $this->assertEquals(1000, $this->purchase2->due_amount);
        $this->assertEquals(0, PurchasePayment::count());
    }
    
    public function test_rejects_negative_allocations()
    {
        $response = $this->actingAs($this->user)->post(route('purchases.global-payments.store', $this->supplier->id), [
            'reference' => 'GLOB-PAY-01',
            'date' => now()->format('Y-m-d'),
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [
                $this->purchase1->id => 1000,
                $this->purchase2->id => -500, // negative
            ]
        ]);

        $response->assertSessionHasErrors(['allocations.'.$this->purchase2->id]);
        $this->assertEquals(0, PurchasePayment::count());
    }

    public function test_ignores_zero_allocations()
    {
        $response = $this->actingAs($this->user)->post(route('purchases.global-payments.store', $this->supplier->id), [
            'reference' => 'GLOB-PAY-01',
            'date' => now()->format('Y-m-d'),
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [
                $this->purchase1->id => 1000,
                $this->purchase2->id => 0, // zero
            ]
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertEquals(1, PurchasePayment::count()); // Only 1 created
    }

    public function test_attachment_replication()
    {
        // Mock storage for medialibrary and temp dropzone
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('public');
        
        $tempPath = 'temp/dropzone/test-file.pdf';
        \Illuminate\Support\Facades\Storage::disk('local')->put($tempPath, 'dummy content');
        $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($tempPath);

        // Ensure directory exists in fake disk
        if (!file_exists(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0777, true);
        }
        file_put_contents($fullPath, 'dummy content'); // write to fake path directly just in case

        $response = $this->actingAs($this->user)->post(route('purchases.global-payments.store', $this->supplier->id), [
            'reference' => 'GLOB-PAY-02',
            'date' => now()->format('Y-m-d'),
            'payment_method_id' => $this->paymentMethod->id,
            'attachment' => 'test-file.pdf',
            'allocations' => [
                $this->purchase1->id => 100,
                $this->purchase2->id => 100,
            ]
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertEquals(2, PurchasePayment::count());
        
        $payments = PurchasePayment::all();
        foreach ($payments as $payment) {
            $this->assertCount(1, $payment->getMedia('attachments'));
        }
    }

    public function test_rejects_supplier_mismatch()
    {
        // Give purchase1 to supplier2 instead
        $this->purchase1->update(['supplier_id' => $this->supplier2->id]);
        
        $response = $this->actingAs($this->user)->post(route('purchases.global-payments.store', $this->supplier->id), [
            'reference' => 'GLOB-PAY-01',
            'date' => now()->format('Y-m-d'),
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [
                $this->purchase1->id => 100, // belogns to supplier2
            ]
        ]);

        $this->assertEquals(0, PurchasePayment::count());
    }
}
