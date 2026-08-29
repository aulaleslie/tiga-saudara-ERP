<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\DueDateAudit;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleDueDateAdjustmentFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;

    private Customer $customer;

    public function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('sales.due-date.override', 'web');
        Permission::findOrCreate('sales.reporting-date.override', 'web');
        Permission::findOrCreate('sales.show', 'web');
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

        $this->customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
    }

    public function test_authorized_user_can_override_sale_due_date()
    {
        $this->user->givePermissionTo('sales.due-date.override');

        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(10),
            'reference' => 'SL-DUE-1',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $this->assertTrue($this->user->can('overrideDueDate', $sale));

        $newDueDate = now()->addDays(30)->format('Y-m-d');

        $response = $this->putJson(route('sales.date-adjustment.update', $sale), [
            'due_date_action' => 'set',
            'due_date' => $newDueDate,
            'reason' => 'Customer requested extension',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $sale->refresh();
        $this->assertEquals($newDueDate, $sale->due_date->format('Y-m-d'));

        $this->assertDatabaseHas('due_date_audits', [
            'auditable_type' => Sale::class,
            'auditable_id' => $sale->id,
            'reason' => 'Customer requested extension',
            'resulting_due_date' => $newDueDate . ' 00:00:00',
        ]);
    }

    public function test_unauthorized_sale_due_date_override_is_denied()
    {
        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(10),
            'reference' => 'SL-DUE-2',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $response = $this->putJson(route('sales.date-adjustment.update', $sale), [
            'due_date_action' => 'set',
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'reason' => 'No permission',
        ]);

        $response->assertStatus(403);
    }

    public function test_null_prior_due_date_sale_adjustment()
    {
        $this->user->givePermissionTo('sales.due-date.override');

        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => null,
            'reference' => 'SL-NULLDUE',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $newDueDate = now()->addDays(15)->format('Y-m-d');

        $response = $this->putJson(route('sales.date-adjustment.update', $sale), [
            'due_date_action' => 'set',
            'due_date' => $newDueDate,
            'reason' => 'Set due date for historical null sale',
        ]);

        $response->assertStatus(200);

        $sale->refresh();
        $this->assertEquals($newDueDate, $sale->due_date->format('Y-m-d'));

        $this->assertDatabaseHas('due_date_audits', [
            'auditable_type' => Sale::class,
            'auditable_id' => $sale->id,
            'prior_due_date' => null,
            'resulting_due_date' => $newDueDate . ' 00:00:00',
        ]);
    }

    public function test_all_eligible_and_ineligible_sale_statuses()
    {
        $this->user->givePermissionTo('sales.due-date.override');

        $eligibleStatuses = [
            Sale::STATUS_APPROVED,
            Sale::STATUS_DISPATCHED_PARTIALLY,
            Sale::STATUS_DISPATCHED,
            Sale::STATUS_RETURNED_PARTIALLY,
            Sale::STATUS_RETURNED,
        ];

        foreach ($eligibleStatuses as $status) {
            $sale = Sale::create([
                'customer_name' => $this->customer->customer_name,
                'customer_id' => $this->customer->id,
                'status' => $status,
                'setting_id' => $this->setting->id,
                'date' => now()->subDays(10),
                'due_date' => now()->addDays(10),
                'reference' => 'SL-ELIG-' . $status,
                'payment_status' => 'Unpaid',
                'payment_method' => 'Cash',
                'total_amount' => 100,
                'due_amount' => 100,
                'paid_amount' => 0,
            ]);
            $this->assertTrue($this->user->can('overrideDueDate', $sale), "Sale status {$status} should be eligible.");
        }

        $ineligibleStatuses = [
            Sale::STATUS_DRAFTED,
            Sale::STATUS_WAITING_APPROVAL,
            Sale::STATUS_REJECTED,
        ];

        foreach ($ineligibleStatuses as $status) {
            $sale = Sale::create([
                'customer_name' => $this->customer->customer_name,
                'customer_id' => $this->customer->id,
                'status' => $status,
                'setting_id' => $this->setting->id,
                'date' => now()->subDays(10),
                'due_date' => now()->addDays(10),
                'reference' => 'SL-INELIG-' . $status,
                'payment_status' => 'Unpaid',
                'payment_method' => 'Cash',
                'total_amount' => 100,
                'due_amount' => 100,
                'paid_amount' => 0,
            ]);
            $this->assertFalse($this->user->can('overrideDueDate', $sale), "Sale status {$status} should be ineligible.");
        }
    }

    public function test_sale_cross_setting_isolation()
    {
        $this->user->givePermissionTo('sales.due-date.override');
        $otherSetting = Setting::create([
            'company_name' => 'Other Sale Company',
            'company_email' => 'other_sale@example.com',
            'company_phone' => '654321',
            'notification_email' => 'other_sale@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Other',
            'company_address' => 'Other Address',
        ]);

        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $otherSetting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(10),
            'reference' => 'SL-CROSS-SETTING',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $this->assertFalse($this->user->can('overrideDueDate', $sale));

        $response = $this->putJson(route('sales.date-adjustment.update', $sale), [
            'due_date_action' => 'set',
            'due_date' => now()->addDays(20)->format('Y-m-d'),
            'reason' => 'Cross setting attack on Sale',
        ]);

        $response->assertStatus(403);
    }

    public function test_sale_super_admin_bypass()
    {
        $this->user->assignRole('Super Admin');

        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(10),
            'reference' => 'SL-SADMIN',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $this->assertTrue($this->user->can('overrideDueDate', $sale));

        $response = $this->putJson(route('sales.date-adjustment.update', $sale), [
            'due_date_action' => 'set',
            'due_date' => now()->addDays(25)->format('Y-m-d'),
            'reason' => 'Super admin adjustment on Sale',
        ]);

        $response->assertStatus(200);
    }

    public function test_sale_missing_inputs_and_no_op_handling()
    {
        $this->user->givePermissionTo('sales.due-date.override');

        $currentDueDate = now()->addDays(10)->format('Y-m-d');
        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => $currentDueDate,
            'reference' => 'SL-NOOP',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        // Missing reason
        $response = $this->putJson(route('sales.date-adjustment.update', $sale), [
            'due_date_action' => 'set',
            'due_date' => now()->addDays(15)->format('Y-m-d'),
            'reason' => '',
        ]);
        $response->assertStatus(422);

        // No-op request
        $response = $this->putJson(route('sales.date-adjustment.update', $sale), [
            'due_date_action' => 'set',
            'due_date' => $currentDueDate,
            'reason' => 'Unchanged sale due date',
        ]);
        $response->assertStatus(422);
        $this->assertDatabaseMissing('due_date_audits', [
            'auditable_type' => Sale::class,
            'auditable_id' => $sale->id,
        ]);
    }

    public function test_sale_due_date_before_and_equal_to_transaction_date()
    {
        $this->user->givePermissionTo('sales.due-date.override');

        $transactionDate = now()->subDays(10);
        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => $transactionDate,
            'due_date' => now()->addDays(10),
            'reference' => 'SL-BEFORE-EQUAL',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        // 1. Equal to transaction date
        $equalDate = $transactionDate->format('Y-m-d');
        $response = $this->putJson(route('sales.date-adjustment.update', $sale), [
            'due_date_action' => 'set',
            'due_date' => $equalDate,
            'reason' => 'Set equal to transaction date',
        ]);
        $response->assertStatus(200);
        $sale->refresh();
        $this->assertEquals($equalDate, $sale->due_date->format('Y-m-d'));

        // 2. Before transaction date
        $beforeDate = $transactionDate->copy()->subDays(5)->format('Y-m-d');
        $response = $this->putJson(route('sales.date-adjustment.update', $sale), [
            'due_date_action' => 'set',
            'due_date' => $beforeDate,
            'reason' => 'Set before transaction date',
        ]);
        $response->assertStatus(200);
        $sale->refresh();
        $this->assertEquals($beforeDate, $sale->due_date->format('Y-m-d'));
    }

    public function test_sale_audit_payload_and_repeated_immutable_history()
    {
        $this->user->givePermissionTo('sales.due-date.override');

        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(5),
            'reference' => 'SL-AUDIT-IMMUTABLE',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $date1 = now()->addDays(15)->format('Y-m-d');
        $this->putJson(route('sales.date-adjustment.update', $sale), [
            'due_date_action' => 'set',
            'due_date' => $date1,
            'reason' => 'First adjustment',
        ]);

        $sale->refresh();

        $date2 = now()->addDays(25)->format('Y-m-d');
        $this->putJson(route('sales.date-adjustment.update', $sale), [
            'due_date_action' => 'set',
            'due_date' => $date2,
            'reason' => 'Second adjustment',
        ]);

        $audits = $sale->dueDateAudits()->orderBy('id')->get();
        $this->assertCount(2, $audits);

        $this->assertEquals($this->user->id, $audits[0]->user_id);
        $this->assertEquals($this->setting->id, $audits[0]->setting_id);
        $this->assertEquals('First adjustment', $audits[0]->reason);
        $this->assertEquals($date1 . ' 00:00:00', $audits[0]->resulting_due_date->format('Y-m-d H:i:s'));

        $this->assertEquals($date1 . ' 00:00:00', $audits[1]->prior_due_date->format('Y-m-d H:i:s'));
        $this->assertEquals($date2 . ' 00:00:00', $audits[1]->resulting_due_date->format('Y-m-d H:i:s'));
    }

    public function test_sale_reporting_date_only_adjustment_success()
    {
        $this->user->givePermissionTo('sales.reporting-date.override');

        $originalDueDate = now()->addDays(10)->format('Y-m-d');
        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => $originalDueDate,
            'reference' => 'SL-RPT-ONLY',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        $newReportingDate = now()->subDays(3)->format('Y-m-d');

        $response = $this->putJson(route('sales.date-adjustment.update', $sale), [
            'reporting_action' => 'set',
            'reporting_date' => $newReportingDate,
            'due_date_action' => 'keep',
            'reason' => 'Sale reporting date adjustment only',
        ]);

        $response->assertStatus(200);
        $sale->refresh();

        $this->assertEquals($newReportingDate, $sale->reporting_date->format('Y-m-d'));
        $this->assertEquals($originalDueDate, $sale->due_date->format('Y-m-d'));
        $this->assertDatabaseHas('reporting_date_audits', ['auditable_id' => $sale->id]);
        $this->assertDatabaseMissing('due_date_audits', ['auditable_id' => $sale->id]);
    }

    public function test_per_field_tampering_denied()
    {
        // User has ONLY due-date override permission, NOT reporting-date permission
        $this->user->givePermissionTo('sales.due-date.override');

        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(5),
            'reference' => 'SL-TAMPER',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        // Attempting to include reporting_action=set without permission
        $response = $this->putJson(route('sales.date-adjustment.update', $sale), [
            'reporting_action' => 'set',
            'reporting_date' => now()->format('Y-m-d'),
            'due_date_action' => 'set',
            'due_date' => now()->addDays(20)->format('Y-m-d'),
            'reason' => 'Tampering payload attempt',
        ]);

        $response->assertStatus(403);
    }

    public function test_authorization_is_repeated_after_document_row_is_locked()
    {
        $this->user->givePermissionTo('sales.reporting-date.override');
        $this->user->givePermissionTo('sales.due-date.override');

        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'due_date' => now()->addDays(5),
            'reference' => 'SL-LOCK-AUTH',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        // Revoke permission right after lock / retrieval inside service execution
        \Illuminate\Support\Facades\Event::listen('eloquent.retrieved: Modules\Sale\Entities\Sale', function () {
            $this->user->revokePermissionTo('sales.due-date.override');
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
        $lockService->adjustDates($sale, $command, $this->user, authorize: true);
    }

    public function test_forced_reporting_date_audit_failure_rolls_back_all_changes()
    {
        $this->user->givePermissionTo('sales.due-date.override');
        $this->user->givePermissionTo('sales.reporting-date.override');

        $originalReportingDate = null;
        $originalDueDate = now()->addDays(5)->format('Y-m-d');

        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reporting_date' => $originalReportingDate,
            'due_date' => $originalDueDate,
            'reference' => 'SL-ROLLBACK-RPT-AUDIT',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        \Illuminate\Support\Facades\Event::listen('eloquent.creating: Modules\Purchase\Entities\ReportingDateAudit', function () {
            throw new \RuntimeException('Forced reporting audit creation failure');
        });

        try {
            $this->putJson(route('sales.date-adjustment.update', $sale), [
                'reporting_action' => 'set',
                'reporting_date' => now()->subDays(2)->format('Y-m-d'),
                'due_date_action' => 'set',
                'due_date' => now()->addDays(20)->format('Y-m-d'),
                'reason' => 'Trigger failure on combined update',
            ]);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $sale->refresh();

        $this->assertNull($sale->reporting_date);
        $this->assertEquals($originalDueDate, $sale->due_date->format('Y-m-d'));
        $this->assertDatabaseMissing('reporting_date_audits', ['auditable_id' => $sale->id]);
        $this->assertDatabaseMissing('due_date_audits', ['auditable_id' => $sale->id]);
    }

    public function test_forced_due_date_audit_failure_rolls_back_all_changes()
    {
        $this->user->givePermissionTo('sales.due-date.override');
        $this->user->givePermissionTo('sales.reporting-date.override');

        $originalReportingDate = null;
        $originalDueDate = now()->addDays(5)->format('Y-m-d');

        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reporting_date' => $originalReportingDate,
            'due_date' => $originalDueDate,
            'reference' => 'SL-ROLLBACK-DUE-AUDIT',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        \Illuminate\Support\Facades\Event::listen('eloquent.creating: Modules\Purchase\Entities\DueDateAudit', function () {
            throw new \RuntimeException('Forced audit creation failure');
        });

        try {
            $this->putJson(route('sales.date-adjustment.update', $sale), [
                'reporting_action' => 'set',
                'reporting_date' => now()->subDays(2)->format('Y-m-d'),
                'due_date_action' => 'set',
                'due_date' => now()->addDays(20)->format('Y-m-d'),
                'reason' => 'Trigger failure on combined update',
            ]);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $sale->refresh();

        $this->assertNull($sale->reporting_date);
        $this->assertEquals($originalDueDate, $sale->due_date->format('Y-m-d'));
        $this->assertDatabaseMissing('reporting_date_audits', ['auditable_id' => $sale->id]);
        $this->assertDatabaseMissing('due_date_audits', ['auditable_id' => $sale->id]);
    }

    public function test_forced_document_update_failure_rolls_back_all_changes()
    {
        $this->user->givePermissionTo('sales.due-date.override');
        $this->user->givePermissionTo('sales.reporting-date.override');

        $originalReportingDate = null;
        $originalDueDate = now()->addDays(5)->format('Y-m-d');

        $sale = Sale::create([
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reporting_date' => $originalReportingDate,
            'due_date' => $originalDueDate,
            'reference' => 'SL-ROLLBACK-DOC-UPDATE',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100,
            'due_amount' => 100,
            'paid_amount' => 0,
        ]);

        \Illuminate\Support\Facades\Event::listen('eloquent.updating: Modules\Sale\Entities\Sale', function () {
            throw new \RuntimeException('Forced sale update failure');
        });

        try {
            $this->putJson(route('sales.date-adjustment.update', $sale), [
                'reporting_action' => 'set',
                'reporting_date' => now()->subDays(2)->format('Y-m-d'),
                'due_date_action' => 'set',
                'due_date' => now()->addDays(20)->format('Y-m-d'),
                'reason' => 'Trigger failure on sale update',
            ]);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $sale->refresh();

        $this->assertNull($sale->reporting_date);
        $this->assertEquals($originalDueDate, $sale->due_date->format('Y-m-d'));
        $this->assertDatabaseMissing('reporting_date_audits', ['auditable_id' => $sale->id]);
        $this->assertDatabaseMissing('due_date_audits', ['auditable_id' => $sale->id]);
    }

    /** @test */
    public function test_reason_length_exceeding_255_characters_is_rejected()
    {
        $this->user->givePermissionTo('sales.due-date.override');

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'status' => Sale::STATUS_APPROVED,
            'date' => now()->subDays(10)->format('Y-m-d'),
            'due_date' => now()->addDays(20)->format('Y-m-d'),
            'reference' => 'SL-RSN-01',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'due_amount' => 1000,
            'paid_amount' => 0,
        ]);

        $response = $this->putJson(route('sales.date-adjustment.update', $sale->id), [
            'due_date_action' => 'set',
            'due_date' => now()->addDays(5)->format('Y-m-d'),
            'reason' => str_repeat('A', 256),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
    }

    /** @test */
    public function test_global_sale_detail_loads_due_date_audits_without_lazy_loading_violation()
    {
        \Spatie\Permission\Models\Permission::findOrCreate('salePayments.global.access', 'web');
        $this->user->givePermissionTo(['sales.due-date.override', 'salePayments.global.access']);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'status' => Sale::STATUS_APPROVED,
            'date' => now()->subDays(10)->format('Y-m-d'),
            'due_date' => now()->addDays(20)->format('Y-m-d'),
            'reference' => 'SL-RSN-02',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'due_amount' => 1000,
            'paid_amount' => 0,
        ]);

        // Create a due date audit record
        $service = app(\App\Services\DocumentDateAdjustmentService::class);
        $service->adjustDates($sale, new \App\DTOs\DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: now()->addDays(10)->format('Y-m-d'),
            reason: 'Audit history test',
        ), $this->user);

        $response = $this->get(route('sales.global-payments.show', $sale->id));
        $response->assertStatus(200);
        $response->assertDontSee('id="dateAdjustmentModalButton"', false);
    }
}
