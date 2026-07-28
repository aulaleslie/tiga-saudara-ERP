<?php

namespace Tests\Feature\GlobalSalesPayment;

use App\Livewire\Sale\SaleTable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Livewire\SaleSummaryCards;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GlobalSalesPaymentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected Sale $sale;
    protected User $authorizedUser;
    protected User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Only disable CheckUserRoleForSetting to preserve permission middleware
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        // Seed permissions needed for tests
        $this->seedPermissions(['salePayments.global.access', 'salePayments.create']);

        $this->setting = Setting::factory()->create();
        $this->customer = Customer::factory()->create();

        $this->sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => '',
            'note' => null,
            'payment_term_id' => null,
            'tax_id' => null,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        // Create user with global access
        $this->authorizedUser = User::factory()->create();
        $this->authorizedUser->givePermissionTo('salePayments.global.access');

        // Create user without global access
        $this->unauthorizedUser = User::factory()->create();
    }

    /**
     * Create permissions in test database
     */
    protected function seedPermissions(array $permissionNames): void
    {
        foreach ($permissionNames as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_anonymous_user_cannot_access_index()
    {
        $response = $this->get(route('sales.global-payments.index'));
        // Anonymous requests redirect to login, not 403
        $response->assertStatus(302);
    }

    public function test_user_without_permission_cannot_access_index()
    {
        $response = $this->actingAs($this->unauthorizedUser)
            ->get(route('sales.global-payments.index'));
        $response->assertStatus(403);
    }

    public function test_user_with_global_access_can_view_index()
    {
        $response = $this->actingAs($this->authorizedUser)
            ->get(route('sales.global-payments.index'));
        $response->assertStatus(200)
            ->assertSeeText('Pembayaran Penjualan Global');
    }

    public function test_global_access_permission_alone_exposes_sales_navigation()
    {
        $response = $this->actingAs($this->authorizedUser)
            ->get(route('sales.global-payments.index'));

        $response->assertOk()
            ->assertSee(route('sales.global-payments.index'), false);
    }

    public function test_create_form_uses_existing_document_upload_routes()
    {
        $this->authorizedUser->givePermissionTo('salePayments.create');

        $this->actingAs($this->authorizedUser)
            ->get(route('sales.global-payments.create', $this->sale->id))
            ->assertOk()
            ->assertSee(route('dropzone.upload.documents'), false)
            ->assertSee(route('dropzone.delete'), false)
            ->assertSee('response.name', false)
            ->assertDontSee('temp-files.upload', false);
    }

    public function test_document_attachment_can_be_uploaded_and_deleted()
    {
        Storage::fake('local');

        $response = $this->actingAs($this->authorizedUser)
            ->post(route('dropzone.upload.documents'), [
                'file' => UploadedFile::fake()->create(
                    'invoice.pdf',
                    10,
                    'application/pdf'
                ),
            ]);

        $response->assertOk()
            ->assertJsonStructure(['name', 'original_name']);

        $fileName = $response->json('name');
        Storage::disk('local')->assertExists('temp/dropzone/' . $fileName);

        $this->actingAs($this->authorizedUser)
            ->post(route('dropzone.delete'), ['file_name' => $fileName])
            ->assertOk();

        Storage::disk('local')->assertMissing('temp/dropzone/' . $fileName);
    }

    public function test_global_rows_use_global_read_only_routes_without_show_permissions()
    {
        Livewire::actingAs($this->authorizedUser)
            ->test(SaleTable::class, ['globalMode' => true])
            ->assertSee($this->sale->reference)
            ->assertSee(route('sales.global-payments.show', $this->sale->id), false)
            ->assertSee(route('sales.global-payments.history', $this->sale->id), false)
            ->assertDontSee('href="' . route('sales.show', $this->sale->id) . '"', false);
    }

    public function test_sale_table_global_mode_cannot_be_enabled_after_mount()
    {
        $component = Livewire::actingAs($this->unauthorizedUser)
            ->test(SaleTable::class, ['globalMode' => false]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [globalMode]');

        $component->set('globalMode', true);
    }

    public function test_sale_summary_global_mode_cannot_be_enabled_after_mount()
    {
        $component = Livewire::actingAs($this->unauthorizedUser)
            ->test(SaleSummaryCards::class, ['globalMode' => false]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [globalMode]');

        $component->set('globalMode', true);
    }

    public function test_anonymous_user_cannot_view_sale_detail()
    {
        $response = $this->get(route('sales.global-payments.show', $this->sale->id));
        // Anonymous requests redirect to login, not 403
        $response->assertStatus(302);
    }

    public function test_user_without_permission_cannot_view_sale_detail()
    {
        $response = $this->actingAs($this->unauthorizedUser)
            ->get(route('sales.global-payments.show', $this->sale->id));
        $response->assertStatus(403);
    }

    public function test_user_with_permission_can_view_sale_detail()
    {
        $otherSetting = Setting::factory()->create([
            'company_name' => 'Cross Setting Company',
        ]);
        $this->sale->update(['setting_id' => $otherSetting->id]);
        session(['setting_id' => $this->setting->id]);

        Model::preventLazyLoading();

        try {
            $response = $this->actingAs($this->authorizedUser)
                ->get(route('sales.global-payments.show', $this->sale->id));
        } finally {
            Model::preventLazyLoading(false);
        }

        $response->assertStatus(200)
            ->assertSeeText($otherSetting->company_name)
            ->assertDontSee(route('sales.deliverySlip', $this->sale->id), false)
            ->assertDontSee(route('sales.invoicePdf', $this->sale->id), false)
            ->assertDontSeeText('Ubah Penjualan')
            ->assertDontSeeText('Arsipkan Penjualan')
            ->assertDontSeeText('Hapus Penjualan');
    }

    public function test_global_access_does_not_bypass_normal_sale_route_authorization()
    {
        $this->actingAs($this->authorizedUser)
            ->get(route('sales.show', $this->sale->id))
            ->assertForbidden();
    }

    public function test_global_summary_uses_cross_setting_live_balances_and_active_payments()
    {
        $otherSetting = Setting::factory()->create();
        $otherSale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 800000,
            'paid_amount' => 0,
            'due_amount' => 800000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'payment_method' => '',
            'setting_id' => $otherSetting->id,
        ]);

        SalePayment::create([
            'sale_id' => $this->sale->id,
            'amount' => 100000,
            'date' => now()->toDateString(),
            'reference' => 'ACTIVE-COLLECTION',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        SalePayment::create([
            'sale_id' => $otherSale->id,
            'amount' => 300000,
            'date' => now()->toDateString(),
            'reference' => 'INVALIDATED-COLLECTION',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_INVALIDATED,
        ]);

        Livewire::actingAs($this->authorizedUser)
            ->test(SaleSummaryCards::class, ['globalMode' => true])
            ->assertSet('piutangBelumTertagih.count', 2)
            ->assertSet('piutangBelumTertagih.total', 1700000.0)
            ->assertSet('piutangTelat.count', 1)
            ->assertSet('piutangTelat.total', 800000.0)
            ->assertSet('penerimaan.count', 1)
            ->assertSet('penerimaan.total', 100000.0);
    }

    public function test_global_summary_filter_drives_cross_setting_table()
    {
        $this->sale->update(['reference' => 'CURRENT-FUTURE-SALE']);
        $otherSetting = Setting::factory()->create();
        $overdueSale = Sale::create([
            'reference' => 'OVERDUE-GLOBAL-SALE',
            'date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => '',
            'setting_id' => $otherSetting->id,
        ]);

        Livewire::actingAs($this->authorizedUser)
            ->test(SaleTable::class, ['globalMode' => true])
            ->assertSee($this->sale->reference)
            ->assertSee($overdueSale->reference)
            ->call('applySaleFilter', 'overdue')
            ->assertDontSee($this->sale->reference)
            ->assertSee($overdueSale->reference);
    }

    public function test_create_requires_both_permissions()
    {
        // User with only global.access cannot access create
        $user1 = User::factory()->create();
        $user1->givePermissionTo('salePayments.global.access');

        $response = $this->actingAs($user1)
            ->get(route('sales.global-payments.create', $this->sale->id));
        $response->assertStatus(403);

        // User with both permissions can access create
        $user2 = User::factory()->create();
        $user2->givePermissionTo(['salePayments.global.access', 'salePayments.create']);

        $response = $this->actingAs($user2)
            ->get(route('sales.global-payments.create', $this->sale->id));
        // Should not be forbidden (403) - can be 200 (form displays) or 302 (redirected if no live due)
        $this->assertNotEquals(403, $response->status());
    }

    public function test_store_requires_both_permissions()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('salePayments.global.access');

        $response = $this->actingAs($user)
            ->post(route('sales.global-payments.store', $this->sale->id), [
                'reference' => 'TEST001',
                'date' => now()->toDateString(),
                'payment_method_id' => 1,
                'allocations' => [$this->sale->id => 100000],
            ]);
        $response->assertStatus(403);
    }

    public function test_history_requires_global_access_permission()
    {
        $response = $this->actingAs($this->unauthorizedUser)
            ->get(route('sales.global-payments.history', $this->sale->id));
        $response->assertStatus(403);
    }

    public function test_user_with_global_access_can_view_history()
    {
        $response = $this->actingAs($this->authorizedUser)
            ->get(route('sales.global-payments.history', $this->sale->id));
        $response->assertStatus(200);
    }
}
