<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use App\Services\Reports\PurchaseReportQueryService;
use App\Support\ImportPaymentSummaryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Guards the import/report bridge for Jumlah Pemotongan: a deducted invoice persists a cash
 * payment row plus a non-cash deduction credit row. Purchase reports derive "paid" from active
 * payment rows, so both rows must sum to the total for the invoice to report as fully paid.
 *
 * Lives under Modules/Purchase/Tests so it runs in the default PHPUnit suite (phpunit.xml does not
 * include Modules/Reports/Tests), keeping the bridge protected under composer test:fresh-sqlite.
 */
class PurchaseImportDeductionReportBridgeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('purchaseReports.access');
        Role::findOrCreate('Staff');

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Staff');
        $this->user->givePermissionTo('purchaseReports.access');
        $this->user->settings()->attach($this->setting->id, ['role_id' => Role::where('name', 'Staff')->first()->id]);
    }

    /** @test */
    public function it_reports_jumlah_pemotongan_credit_as_fully_paid(): void
    {
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier 1',
            'supplier_email' => 's1@test.com',
            'supplier_phone' => '1',
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);

        // Header paid = cash 300 + deduction 700; due 0.
        $purchase = Purchase::create([
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'due_date' => now()->startOfMonth()->format('Y-m-d'),
            'setting_id' => $this->setting->id,
            'reference' => 'PR-0001',
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);

        // Cash Pembayaran row.
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'reference' => 'PP-0001',
            'amount' => 300,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'CASH',
            'date' => now()->format('Y-m-d'),
        ]);

        // Non-cash Jumlah Pemotongan credit row.
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'reference' => 'PP-0002',
            'amount' => 700,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => ImportPaymentSummaryResolver::DEDUCTION_METHOD_NAME,
            'date' => now()->format('Y-m-d'),
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => null,
            'quantity' => 1,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_name' => 'Deducted Product',
            'product_code' => 'DP-001',
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $component = \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('reportMode', 'header')
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases->items());

        $mapped = PurchaseReportQueryService::mapRow($purchases->items()[0], 'header');

        // Report derives paid from active payment rows (cash + credit) -> fully paid, zero outstanding.
        $this->assertSame('Lunas', $mapped['Status Pembayaran']);
        $this->assertEquals(1000, $mapped['Pembayaran']);
        $this->assertEquals(0, $mapped['Sisa Tagihan']);
    }
}
