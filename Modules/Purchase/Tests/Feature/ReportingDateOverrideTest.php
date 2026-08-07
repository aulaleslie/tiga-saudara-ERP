<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use App\Services\ReportingDateOverrideService;
use Carbon\Carbon;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\ReportingDateAudit;
use Modules\Setting\Entities\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class ReportingDateOverrideTest extends TestCase
{
    use RefreshDatabase;

    private ReportingDateOverrideService $service;
    private User $user;
    private Setting $setting;
    private Purchase $purchase;

    public function setUp(): void
    {
        parent::setUp();

        // The fresh-sqlite runner starts from an empty database, so create the
        // currency/setting this suite depends on instead of assuming seed data.
        Currency::firstOrCreate(['id' => 1], [
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->service = app(ReportingDateOverrideService::class);

        // Get or create a user
        $this->user = User::first() ?: User::factory()->create([
            'is_active' => true,
        ]);

        // Get or create a setting
        $this->setting = Setting::first() ?: Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'notification_email' => 'test@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Test',
            'company_address' => 'Test Address',
        ]);

        // Create an approved purchase
        $this->purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reference' => 'TST-2026-08-001',
            'due_date' => now()->addDays(30),
            'supplier_name' => 'Test Supplier',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
        ]);
    }

    public function test_set_override_creates_audit_entry()
    {
        $newDate = now()->addDays(5);
        $reason = 'Penundaan Pelaporan';

        $audit = $this->service->setOverride($this->purchase, $newDate, $reason, $this->user);

        $this->assertInstanceOf(ReportingDateAudit::class, $audit);
        $this->assertEquals($reason, $audit->reason);
        $this->assertEquals($this->purchase->id, $audit->auditable_id);
        $this->assertEquals($this->setting->id, $audit->setting_id);
        $this->assertNull($audit->prior_override);
        $this->assertEquals($newDate->format('Y-m-d'), $audit->resulting_override?->format('Y-m-d'));
    }

    public function test_override_preserves_original_date()
    {
        $originalDate = $this->purchase->date;
        $newDate = now()->addDays(5);

        $this->service->setOverride($this->purchase, $newDate, 'Test', $this->user);

        $this->purchase->refresh();
        $this->assertEquals($originalDate->format('Y-m-d'), $this->purchase->date->format('Y-m-d'));
    }

    public function test_effective_date_returns_override_when_present()
    {
        $newDate = now()->addDays(5);
        $this->service->setOverride($this->purchase, $newDate, 'Test', $this->user);

        $this->purchase->refresh();
        $this->assertEquals($newDate->format('Y-m-d'), $this->purchase->effective_date->format('Y-m-d'));
    }

    public function test_effective_date_returns_original_when_no_override()
    {
        $this->assertEquals(
            $this->purchase->date->format('Y-m-d'),
            $this->purchase->effective_date->format('Y-m-d')
        );
    }

    public function test_clear_override_nullifies_reporting_date()
    {
        // First set an override
        $this->service->setOverride($this->purchase, now()->addDays(5), 'Set', $this->user);
        $this->purchase->refresh();
        $this->assertNotNull($this->purchase->reporting_date);

        // Clear it
        $this->service->clearOverride($this->purchase, 'Clear', $this->user);
        $this->purchase->refresh();
        $this->assertNull($this->purchase->reporting_date);
    }

    public function test_replace_override_preserves_history()
    {
        $date1 = now()->addDays(5);
        $date2 = now()->addDays(10);

        $audit1 = $this->service->setOverride($this->purchase, $date1, 'First', $this->user);
        $this->purchase->refresh();

        $audit2 = $this->service->setOverride($this->purchase, $date2, 'Second', $this->user);

        $audits = ReportingDateAudit::where('auditable_id', $this->purchase->id)->get();
        $this->assertCount(2, $audits);

        // Second audit should record the first override as prior
        $this->assertEquals($date1->format('Y-m-d'), $audit2->prior_override?->format('Y-m-d'));
        $this->assertEquals($date2->format('Y-m-d'), $audit2->resulting_override?->format('Y-m-d'));
    }

    public function test_empty_reason_throws_exception()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setOverride($this->purchase, now()->addDays(5), '', $this->user);
    }
}
