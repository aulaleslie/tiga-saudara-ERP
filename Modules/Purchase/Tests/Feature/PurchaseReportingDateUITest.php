<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use App\Services\ReportingDateOverrideService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Modules\People\Entities\Supplier;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Modules\Currency\Entities\Currency;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use App\Livewire\Purchase\PurchaseTable;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseReportingDateUITest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private ReportingDateOverrideService $service;

    public function setUp(): void
    {
        parent::setUp();

        // Create currency
        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'notification_email' => 'test@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Test',
            'company_address' => 'Test Address',
        ]);
        $this->service = app(ReportingDateOverrideService::class);

        // The detail view calls can('purchases.reporting-date.override'); Spatie throws
        // if the permission row is absent, so define it for every test and grant
        // it only where the override UI is under test.
        Permission::findOrCreate('purchases.reporting-date.override', 'web');
        Permission::findOrCreate('purchases.due-date.override', 'web');

        $this->grantPermission('purchases.show');
        $this->grantPermission('purchases.access');

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
    }

    /**
     * RefreshDatabase truncates the seeded permission table, so create the
     * permission on demand before granting it to the active test user.
     */
    private function grantPermission(string $name): void
    {
        Permission::findOrCreate($name, 'web');
        $this->user->givePermissionTo($name);
        $this->user->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createPurchase(): Purchase
    {
        $supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);

        return Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reference' => 'TST-' . Str::random(8),
            'due_date' => now()->addDays(30),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
        ]);
    }

    public function test_effective_date_displays_on_purchase_detail_when_override_exists()
    {
        $this->grantPermission('purchases.reporting-date.override');
        $purchase = $this->createPurchase();

        $overrideDate = now()->addDays(5);
        $this->service->setOverride($purchase, $overrideDate, 'Test Override', $this->user);

        $response = $this->get(route('purchases.show', $purchase));

        $this->assertEquals(200, $response->status());
        $this->assertStringContainsString(
            $overrideDate->format('d M, Y'),
            $response->getContent()
        );
    }

    public function test_original_date_displays_on_detail_when_no_override()
    {
        $purchase = $this->createPurchase();

        $response = $this->get(route('purchases.show', $purchase));

        $this->assertEquals(200, $response->status());
        $this->assertStringContainsString(
            $purchase->date->format('d M, Y'),
            $response->getContent()
        );
    }

    public function test_audit_history_displays_on_detail_page()
    {
        // Audit history renders inside @can('overrideReportingDate').
        $this->grantPermission('purchases.reporting-date.override');

        $purchase = $this->createPurchase();

        $date1 = now()->addDays(5);
        $audit1 = $this->service->setOverride($purchase, $date1, 'First Override', $this->user);

        $response = $this->get(route('purchases.show', $purchase));

        $this->assertEquals(200, $response->status());
        $this->assertStringContainsString('Riwayat Penyesuaian Tanggal', $response->getContent());
        $this->assertStringContainsString('First Override', $response->getContent());
        $this->assertStringContainsString($this->user->name, $response->getContent());
    }

    public function test_audit_history_shows_original_date()
    {
        // Audit history renders inside @can('overrideReportingDate').
        $this->grantPermission('purchases.reporting-date.override');

        $purchase = $this->createPurchase();

        $overrideDate = now()->addDays(5);
        $this->service->setOverride($purchase, $overrideDate, 'Test', $this->user);

        $response = $this->get(route('purchases.show', $purchase));

        $this->assertEquals(200, $response->status());
        // Verify original date is shown in audit
        $this->assertStringContainsString(
            $purchase->date->format('d M, Y'),
            $response->getContent()
        );
    }

    public function test_audit_history_shows_null_override_as_safe_placeholder()
    {
        // Audit history renders inside @can('overrideReportingDate').
        $this->grantPermission('purchases.reporting-date.override');

        $purchase = $this->createPurchase();

        // Set override then clear it
        $this->service->setOverride($purchase, now()->addDays(5), 'Set', $this->user);
        $this->service->clearOverride($purchase, 'Clear', $this->user);

        $response = $this->get(route('purchases.show', $purchase));

        $this->assertEquals(200, $response->status());
        // Verify the audit table shows dash/placeholder for null resulting_override
        $this->assertStringContainsString('-', $response->getContent());
    }

    public function test_permitted_user_sees_reporting_date_action()
    {
        $this->grantPermission('purchases.reporting-date.override');

        $purchase = $this->createPurchase();

        $response = $this->get(route('purchases.show', $purchase));

        $this->assertEquals(200, $response->status());
        $response->assertSee('id="dateAdjustmentModalButton"', false);
        $response->assertSee('dateAdjustmentModal', false);
        $response->assertSee('<select id="reporting_action"', false);
        $response->assertDontSee('<select id="due_date_action"', false);
    }

    public function test_due_date_only_permitted_user_sees_due_date_action()
    {
        $this->grantPermission('purchases.due-date.override');

        $purchase = $this->createPurchase();

        $response = $this->get(route('purchases.show', $purchase));

        $this->assertEquals(200, $response->status());
        $response->assertSee('id="dateAdjustmentModalButton"', false);
        $response->assertSee('dateAdjustmentModal', false);
        $response->assertSee('<select id="due_date_action"', false);
        $response->assertDontSee('<select id="reporting_action"', false);
    }

    public function test_both_permissions_user_sees_all_adjustment_controls()
    {
        $this->grantPermission('purchases.reporting-date.override');
        $this->grantPermission('purchases.due-date.override');

        $purchase = $this->createPurchase();

        $response = $this->get(route('purchases.show', $purchase));

        $this->assertEquals(200, $response->status());
        $response->assertSee('id="dateAdjustmentModalButton"', false);
        $response->assertSee('dateAdjustmentModal', false);
        $response->assertSee('<select id="reporting_action"', false);
        $response->assertSee('<select id="due_date_action"', false);
    }

    public function test_unpermitted_user_does_not_see_action()
    {
        $purchase = $this->createPurchase();

        $response = $this->get(route('purchases.show', $purchase));

        $this->assertEquals(200, $response->status());
        // Button should not be present
        $this->assertStringNotContainsString('id="dateAdjustmentModalButton"', $response->getContent());
        $this->assertStringNotContainsString('id="dateAdjustmentModal"', $response->getContent());
        $this->assertStringNotContainsString('id="dateAdjustmentForm"', $response->getContent());
    }

    /**
     * The audit history renders one actor name per row, so a missing eager load
     * would still render correctly while issuing one actor query per audit row.
     * Assert on the relation state handed to the view instead of on the markup.
     */
    public function test_detail_page_eager_loads_audit_relationships()
    {
        // Audit history renders inside @can('overrideReportingDate').
        $this->grantPermission('purchases.reporting-date.override');

        $actor1 = User::factory()->create(['name' => 'First Actor', 'is_active' => true]);
        $actor2 = User::factory()->create(['name' => 'Second Actor', 'is_active' => true]);

        Permission::findOrCreate('purchases.reporting-date.override', 'web');
        $actor1->givePermissionTo('purchases.reporting-date.override');
        $actor2->givePermissionTo('purchases.reporting-date.override');

        $purchase = $this->createPurchase();

        $this->service->setOverride($purchase, now()->addDays(5), 'First', $actor1);
        $purchase->refresh();
        $this->service->setOverride($purchase, now()->addDays(10), 'Second', $actor2);

        // Capture the model as the view receives it, before any Blade access can
        // lazy-load the relations and mask a missing eager load.
        $viewPurchase = null;
        View::composer('purchase::show', function ($view) use (&$viewPurchase) {
            $viewPurchase = $view->getData()['purchase'] ?? null;
        });

        $response = $this->get(route('purchases.show', $purchase));

        $this->assertEquals(200, $response->status());
        $this->assertNotNull($viewPurchase, 'purchase::show was not rendered with a purchase.');
        $this->assertTrue(
            $viewPurchase->relationLoaded('reportingDateAudits'),
            'reportingDateAudits was not eager-loaded on the purchase passed to the view.'
        );

        $audits = $viewPurchase->getRelation('reportingDateAudits');
        $this->assertCount(2, $audits);

        foreach ($audits as $audit) {
            $this->assertTrue(
                $audit->relationLoaded('actor'),
                'Audit actor was not eager-loaded; the view would trigger an N+1 query.'
            );
        }

        // Compare against the persisted names; BaseModel uppercases string
        // attributes on write, so the stored names are not the literals above.
        $this->assertEqualsCanonicalizing(
            [$actor1->name, $actor2->name],
            $audits->map(fn ($audit) => $audit->getRelation('actor')->name)->all()
        );
    }

    /**
     * Complements the relation assertion above: adding a third audit row must not
     * add a third actor query.
     */
    public function test_detail_page_does_not_issue_one_actor_query_per_audit_row()
    {
        // Audit history renders inside @can('overrideReportingDate').
        $this->grantPermission('purchases.reporting-date.override');

        $purchase = $this->createPurchase();

        foreach (['Actor One', 'Actor Two', 'Actor Three'] as $index => $name) {
            $actor = User::factory()->create(['name' => $name, 'is_active' => true]);
            Permission::findOrCreate('purchases.reporting-date.override', 'web');
            $actor->givePermissionTo('purchases.reporting-date.override');
            $this->service->setOverride($purchase, now()->addDays(5 + $index), $name, $actor);
            $purchase->refresh();
        }

        $auditQueries = 0;
        $actorQueries = 0;

        DB::listen(function ($query) use (&$auditQueries, &$actorQueries) {
            if (str_contains($query->sql, 'reporting_date_audits')) {
                $auditQueries++;
            } elseif (preg_match('/from ["`]users["`]/i', $query->sql)) {
                $actorQueries++;
            }
        });

        $response = $this->get(route('purchases.show', $purchase));

        $this->assertEquals(200, $response->status());
        $this->assertSame(1, $auditQueries, 'Expected a single reporting_date_audits query.');
        $this->assertLessThanOrEqual(
            2,
            $actorQueries,
            'Actor lookups scaled with audit rows, indicating an N+1 query.'
        );
    }

    /**
     * The list is rendered by the PurchaseTable Livewire component, not inlined into
     * the index page HTML, so drive the component directly.
     */
    public function test_purchase_list_displays_effective_date_when_override_exists()
    {
        $this->grantPermission('purchases.reporting-date.override');
        $purchaseWithOverride = $this->createPurchase();
        $overrideDate = now()->addDays(5);
        $this->service->setOverride($purchaseWithOverride, $overrideDate, 'List Test', $this->user);

        Livewire::test(PurchaseTable::class, ['settingId' => $this->setting->id])
            ->assertSee($overrideDate->format('d M Y'))
            ->assertDontSee($purchaseWithOverride->date->format('d M Y'));
    }

    public function test_purchase_list_displays_original_date_when_no_override()
    {
        $purchaseNoOverride = $this->createPurchase();

        Livewire::test(PurchaseTable::class, ['settingId' => $this->setting->id])
            ->assertSee($purchaseNoOverride->date->format('d M Y'));
    }
}
