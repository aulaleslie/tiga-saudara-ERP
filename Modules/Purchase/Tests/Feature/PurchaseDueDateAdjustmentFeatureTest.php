<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Modules\Purchase\Entities\DueDateAudit;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseDueDateAdjustmentFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;

    public function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('purchases.due-date.override', 'web');
        Permission::findOrCreate('purchases.reporting-date.override', 'web');
        Permission::findOrCreate('purchases.show', 'web');
        Role::findOrCreate('Super Admin', 'web');

        Currency::firstOrCreate(['id' => 1], [
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->user = User::first() ?: User::factory()->create([
            'is_active' => true,
        ]);

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

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
    }

    public function test_authorized_user_can_override_due_date()
    {
        $this->user->givePermissionTo('purchases.due-date.override');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(10),
            'reference' => 'TST-DUE-1',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $this->assertTrue($this->user->can('overrideDueDate', $purchase));

        $newDueDate = now()->subDays(2)->format('Y-m-d'); // Shortened before transaction date

        $response = $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'due_date_action' => 'set',
            'due_date' => $newDueDate,
            'reason' => 'Renegotiated terms with supplier',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $purchase->refresh();
        $this->assertEquals($newDueDate, $purchase->due_date->format('Y-m-d'));

        $this->assertDatabaseHas('due_date_audits', [
            'auditable_type' => Purchase::class,
            'auditable_id' => $purchase->id,
            'reason' => 'Renegotiated terms with supplier',
            'resulting_due_date' => $newDueDate . ' 00:00:00',
        ]);
    }

    public function test_reporting_permission_only_cannot_override_due_date()
    {
        $this->user->givePermissionTo('purchases.reporting-date.override');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(10),
            'reference' => 'TST-DUE-2',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $this->assertFalse($this->user->can('overrideDueDate', $purchase));

        $response = $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'due_date_action' => 'set',
            'due_date' => now()->addDays(15)->format('Y-m-d'),
            'reason' => 'Unauthorized attempt',
        ]);

        $response->assertStatus(403);
    }

    public function test_combined_reporting_and_due_date_adjustment()
    {
        $this->user->givePermissionTo('purchases.reporting-date.override');
        $this->user->givePermissionTo('purchases.due-date.override');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(10),
            'reference' => 'TST-DUE-3',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $newReportingDate = now()->subDays(5)->format('Y-m-d');
        $newDueDate = now()->addDays(20)->format('Y-m-d');

        $response = $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'reporting_action' => 'set',
            'reporting_date' => $newReportingDate,
            'due_date_action' => 'set',
            'due_date' => $newDueDate,
            'reason' => 'Combined renegotiation',
        ]);

        $response->assertStatus(200);

        $purchase->refresh();
        $this->assertEquals($newReportingDate, $purchase->reporting_date->format('Y-m-d'));
        $this->assertEquals($newDueDate, $purchase->due_date->format('Y-m-d'));

        $this->assertDatabaseHas('reporting_date_audits', [
            'auditable_id' => $purchase->id,
            'reason' => 'Combined renegotiation',
        ]);
        $this->assertDatabaseHas('due_date_audits', [
            'auditable_id' => $purchase->id,
            'reason' => 'Combined renegotiation',
        ]);
    }

    public function test_all_eligible_and_ineligible_statuses()
    {
        $this->user->givePermissionTo('purchases.due-date.override');

        $eligibleStatuses = [
            Purchase::STATUS_APPROVED,
            Purchase::STATUS_RECEIVED_PARTIALLY,
            Purchase::STATUS_RECEIVED,
            Purchase::STATUS_RETURNED_PARTIALLY,
            Purchase::STATUS_RETURNED,
        ];

        foreach ($eligibleStatuses as $status) {
            $purchase = Purchase::create([
                'status' => $status,
                'setting_id' => $this->setting->id,
                'date' => now()->subDays(10),
                'due_date' => now()->addDays(10),
                'reference' => 'TST-ELIG-' . $status,
                'payment_status' => 'Unpaid',
                'payment_method' => 'Cash',
                'total_amount' => 100,
                'due_amount' => 100,
                'paid_amount' => 0,
            ]);
            $this->assertTrue($this->user->can('overrideDueDate', $purchase), "Status {$status} should be eligible.");
        }

        $ineligibleStatuses = [
            Purchase::STATUS_DRAFTED,
            Purchase::STATUS_WAITING_APPROVAL,
            Purchase::STATUS_REJECTED,
        ];

        foreach ($ineligibleStatuses as $status) {
            $purchase = Purchase::create([
                'status' => $status,
                'setting_id' => $this->setting->id,
                'date' => now()->subDays(10),
                'due_date' => now()->addDays(10),
                'reference' => 'TST-INELIG-' . $status,
                'payment_status' => 'Unpaid',
                'payment_method' => 'Cash',
                'total_amount' => 100,
                'due_amount' => 100,
                'paid_amount' => 0,
            ]);
            $this->assertFalse($this->user->can('overrideDueDate', $purchase), "Status {$status} should be ineligible.");
        }
    }

    public function test_cross_setting_isolation()
    {
        $this->user->givePermissionTo('purchases.due-date.override');
        $otherSetting = Setting::create([
            'company_name' => 'Other Company',
            'company_email' => 'other@example.com',
            'company_phone' => '654321',
            'notification_email' => 'other@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Other',
            'company_address' => 'Other Address',
        ]);

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $otherSetting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(10),
            'reference' => 'TST-CROSS-SETTING',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $this->assertFalse($this->user->can('overrideDueDate', $purchase));

        $response = $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'due_date_action' => 'set',
            'due_date' => now()->addDays(20)->format('Y-m-d'),
            'reason' => 'Cross setting attack',
        ]);

        $response->assertStatus(403);
    }

    public function test_super_admin_bypass()
    {
        $this->user->assignRole('Super Admin');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(10),
            'reference' => 'TST-SADMIN',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $this->assertTrue($this->user->can('overrideDueDate', $purchase));

        $response = $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'due_date_action' => 'set',
            'due_date' => now()->addDays(25)->format('Y-m-d'),
            'reason' => 'Super admin adjustment',
        ]);

        $response->assertStatus(200);
    }

    public function test_missing_inputs_and_no_op_handling()
    {
        $this->user->givePermissionTo('purchases.due-date.override');

        $currentDueDate = now()->addDays(10)->format('Y-m-d');
        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => $currentDueDate,
            'reference' => 'TST-NOOP',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        // Missing reason
        $response = $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'due_date_action' => 'set',
            'due_date' => now()->addDays(15)->format('Y-m-d'),
            'reason' => '',
        ]);
        $response->assertStatus(422);

        // No-op request (submitting same due date)
        $response = $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'due_date_action' => 'set',
            'due_date' => $currentDueDate,
            'reason' => 'Unchanged date',
        ]);
        $response->assertStatus(422);
        $this->assertDatabaseMissing('due_date_audits', ['auditable_id' => $purchase->id]);
    }

    public function test_payment_term_preservation_on_purchase_due_date_adjustment()
    {
        $this->user->givePermissionTo('purchases.due-date.override');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(5),
            'payment_term_id' => 5,
            'reference' => 'TST-PRESERVE-TERM',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $newDueDate = now()->addDays(15)->format('Y-m-d');

        $response = $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'due_date_action' => 'set',
            'due_date' => $newDueDate,
            'reason' => 'Adjust due date and preserve payment term',
        ]);

        $response->assertStatus(200);

        $purchase->refresh();
        $this->assertEquals($newDueDate, $purchase->due_date->format('Y-m-d'));
        $this->assertEquals(5, $purchase->payment_term_id);
    }

    public function test_purchase_due_date_before_and_equal_to_transaction_date()
    {
        $this->user->givePermissionTo('purchases.due-date.override');

        $transactionDate = now()->subDays(10);
        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => $transactionDate,
            'due_date' => now()->addDays(10),
            'reference' => 'TST-BEFORE-EQUAL',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        // 1. Equal to transaction date
        $equalDate = $transactionDate->format('Y-m-d');
        $response = $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'due_date_action' => 'set',
            'due_date' => $equalDate,
            'reason' => 'Set equal to transaction date',
        ]);
        $response->assertStatus(200);
        $purchase->refresh();
        $this->assertEquals($equalDate, $purchase->due_date->format('Y-m-d'));

        // 2. Before transaction date
        $beforeDate = $transactionDate->copy()->subDays(5)->format('Y-m-d');
        $response = $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'due_date_action' => 'set',
            'due_date' => $beforeDate,
            'reason' => 'Set before transaction date',
        ]);
        $response->assertStatus(200);
        $purchase->refresh();
        $this->assertEquals($beforeDate, $purchase->due_date->format('Y-m-d'));
    }

    public function test_purchase_audit_payload_and_repeated_immutable_history()
    {
        $this->user->givePermissionTo('purchases.due-date.override');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(5),
            'reference' => 'TST-AUDIT-IMMUTABLE',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $date1 = now()->addDays(15)->format('Y-m-d');
        $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'due_date_action' => 'set',
            'due_date' => $date1,
            'reason' => 'First adjustment',
        ]);

        $purchase->refresh();

        $date2 = now()->addDays(25)->format('Y-m-d');
        $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'due_date_action' => 'set',
            'due_date' => $date2,
            'reason' => 'Second adjustment',
        ]);

        $audits = $purchase->dueDateAudits()->orderBy('id')->get();
        $this->assertCount(2, $audits);

        $this->assertEquals($this->user->id, $audits[0]->user_id);
        $this->assertEquals($this->setting->id, $audits[0]->setting_id);
        $this->assertEquals('First adjustment', $audits[0]->reason);
        $this->assertEquals($date1 . ' 00:00:00', $audits[0]->resulting_due_date->format('Y-m-d H:i:s'));

        $this->assertEquals($date1 . ' 00:00:00', $audits[1]->prior_due_date->format('Y-m-d H:i:s'));
        $this->assertEquals($date2 . ' 00:00:00', $audits[1]->resulting_due_date->format('Y-m-d H:i:s'));
    }

    public function test_per_field_tampering_denied_and_lock_time_authorization()
    {
        // User has ONLY due-date override permission, NOT reporting-date permission
        $this->user->givePermissionTo('purchases.due-date.override');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(5),
            'reference' => 'TST-TAMPER',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        // Attempting to include reporting_action=set without permission
        $response = $this->putJson(route('purchases.date-adjustment.update', $purchase), [
            'reporting_action' => 'set',
            'reporting_date' => now()->format('Y-m-d'),
            'due_date_action' => 'set',
            'due_date' => now()->addDays(20)->format('Y-m-d'),
            'reason' => 'Tampering payload attempt',
        ]);

        $response->assertStatus(403);

        // Test mid-execution lock-time reauthorization rejection
        $this->user->givePermissionTo('purchases.reporting-date.override');

        // Revoke permission right before locked service execution
        \Illuminate\Support\Facades\Event::listen('eloquent.retrieved: Modules\Purchase\Entities\Purchase', function () {
            $this->user->revokePermissionTo('purchases.due-date.override');
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        });

        $lockService = app(\App\Services\DocumentDateAdjustmentService::class);
        $command = new \App\DTOs\DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: now()->addDays(25)->format('Y-m-d'),
            reason: 'Lock time revocation test'
        );

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $lockService->adjustDates($purchase, $command, $this->user, authorize: true);
    }

    public function test_forced_transactional_rollback_on_failure()
    {
        $this->user->givePermissionTo('purchases.due-date.override');
        $this->user->givePermissionTo('purchases.reporting-date.override');

        $originalReportingDate = null;
        $originalDueDate = now()->addDays(5)->format('Y-m-d');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reporting_date' => $originalReportingDate,
            'due_date' => $originalDueDate,
            'reference' => 'TST-ROLLBACK',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        // Listen for DueDateAudit insertion and throw an exception to force rollback of BOTH fields
        \Illuminate\Support\Facades\Event::listen('eloquent.creating: Modules\Purchase\Entities\DueDateAudit', function () {
            throw new \RuntimeException('Forced audit creation failure');
        });

        try {
            $this->putJson(route('purchases.date-adjustment.update', $purchase), [
                'reporting_action' => 'set',
                'reporting_date' => now()->subDays(2)->format('Y-m-d'),
                'due_date_action' => 'set',
                'due_date' => now()->addDays(20)->format('Y-m-d'),
                'reason' => 'Trigger failure on combined update',
            ]);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $purchase->refresh();

        // Assert NEITHER field changed and NEITHER audit persisted
        $this->assertNull($purchase->reporting_date);
        $this->assertEquals($originalDueDate, $purchase->due_date->format('Y-m-d'));
        $this->assertDatabaseMissing('reporting_date_audits', ['auditable_id' => $purchase->id]);
        $this->assertDatabaseMissing('due_date_audits', ['auditable_id' => $purchase->id]);
    }
}
