<?php

namespace Modules\Purchase\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class PurchasePaymentListStatusActionsTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $supplier;
    protected $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        
        DB::statement('PRAGMA foreign_keys = OFF');
        
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        
        Permission::findOrCreate('purchasePayments.edit', 'web');
        Permission::findOrCreate('purchasePayments.delete', 'web');
        Permission::findOrCreate('purchasePayments.access', 'web');

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo('purchasePayments.edit');
        $this->user->givePermissionTo('purchasePayments.delete');
        $this->user->givePermissionTo('purchasePayments.access');
        $this->actingAs($this->user);

        Currency::create([
             'id' => 1,
             'currency_name' => 'Rupiah',
             'code' => 'IDR',
             'symbol' => 'Rp',
             'thousand_separator' => '.',
             'decimal_separator' => ',',
             'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '123456',
            'notification_email' => 'notify@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);
        
        session(['setting_id' => $this->setting->id]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);
    }

    /** @test */
    public function it_shows_active_status_badge_in_datatable()
    {
        $payment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $ajaxUrl = route('datatable.purchase_payments', $this->purchase->id);
        
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->getJson($ajaxUrl);
            
        $response->assertStatus(200);
        
        $data = $response->json('data.0');
        
        $this->assertStringContainsString('badge-success', $data['status']);
        $this->assertStringContainsString(PurchasePayment::STATUS_ACTIVE, $data['status']);
    }

    /** @test */
    public function it_shows_invalidated_status_badge_in_datatable()
    {
        $payment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_INVALIDATED,
        ]);

        $ajaxUrl = route('datatable.purchase_payments', $this->purchase->id);
        
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->getJson($ajaxUrl);
            
        $response->assertStatus(200);
        
        $data = $response->json('data.0');
        
        $this->assertStringContainsString('badge-danger', $data['status']);
        $this->assertStringContainsString(PurchasePayment::STATUS_INVALIDATED, $data['status']);
    }

    /** @test */
    public function it_gates_actions_based_on_payment_status()
    {
        $activePayment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-ACT',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $invalidPayment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-INV',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_INVALIDATED,
        ]);

        $ajaxUrl = route('datatable.purchase_payments', $this->purchase->id);
        
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->getJson($ajaxUrl);
            
        $response->assertStatus(200);
        $data = $response->json('data');

        // Check active payment (should be index 0 or 1 depending on sort)
        $activeData = collect($data)->where('reference', 'PAY-ACT')->first();
        $this->assertStringContainsString('bi-pencil', $activeData['action']);
        $this->assertStringContainsString('bi-x-circle', $activeData['action']); // Invalidate button
        $this->assertStringNotContainsString('bi-trash', $activeData['action']); // Delete button

        // Check invalidated payment
        $invalidData = collect($data)->where('reference', 'PAY-INV')->first();
        $this->assertStringNotContainsString('bi-pencil', $invalidData['action']);
        $this->assertStringNotContainsString('bi-x-circle', $invalidData['action']);
        $this->assertStringContainsString('bi-trash', $invalidData['action']); // Delete button
    }
}
