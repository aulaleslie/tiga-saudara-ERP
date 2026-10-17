<?php

namespace Modules\Purchase\Tests\Feature;

use App\DTOs\DateAdjustmentCommand;
use App\Livewire\Reports\PurchaseReport;
use App\Models\User;
use App\Services\DocumentDateAdjustmentService;
use App\Services\ReportingDateOverrideService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentReceiptAllocation;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBillingConversionService;
use Modules\Consignment\Services\ConsignmentBillingPreviewService;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\DueDateAudit;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReportingDateAudit;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConsignmentPurchaseDateAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected Supplier $supplier;
    protected PaymentTerm $paymentTerm;
    protected Product $product;
    protected Purchase $consignmentPurchase;
    protected User $userBothPermissions;
    protected User $userReportingOnly;
    protected User $userDueOnly;
    protected User $userNoPermissions;
    protected User $superAdmin;
    protected User $globalUser;
    protected DocumentDateAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'purchases.show', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.update', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.reporting-date.override', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.due-date.override', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchasePayments.global.access', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchasePayments.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchaseReports.access', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.received.correct', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.received.monetary.edit', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.approved.edit', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.receive.cancel', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.receive', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.approval', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.create', 'guard_name' => 'web']);

        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting1 = Setting::create([
            'company_name' => 'Date Setting 1',
            'company_email' => 'date1@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'date1@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address 1',
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Date Setting 2',
            'company_email' => 'date2@example.com',
            'company_phone' => '654321',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'date2@example.com',
            'footer_text' => 'Footer 2',
            'company_address' => 'Address 2',
        ]);

        $this->paymentTerm = PaymentTerm::firstOrCreate(
            ['name' => 'Net 30'],
            ['longevity' => 30]
        );

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting1->id,
            'supplier_name' => 'Date Test Supplier',
            'supplier_email' => 'date-supplier@example.com',
            'supplier_phone' => '0812345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor Street 1',
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $unit = Unit::create([
            'name' => 'Pieces',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Date Consignment Product',
            'product_code' => 'DCSG-A',
            'product_unit' => $unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 10,
        ]);

        $location = Location::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Rack Date',
            'is_consignment' => true,
        ]);

        $confirmation = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-DATE-001',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => '2026-08-01',
            'is_ready_for_billing' => true,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting1->id,
            'customer_name' => 'Cust Date',
            'customer_email' => 'datecust@example.com',
            'customer_phone' => '08123126',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Addr',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => '2026-08-01',
            'customer_id' => $customer->id,
            'customer_name' => 'Cust Date',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale->id,
            'status' => 'APPROVED',
        ]);

        $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $location->id,
            'dispatched_quantity' => 1,
        ]);

        $soldSource = ConsignmentSoldSource::create([
            'setting_id' => $this->setting1->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $location->id,
            'original_base_quantity' => 1,
            'dispatched_at' => '2026-08-01 10:00:00',
            'source_hash' => 'hash_date_test',
            'source_snapshot' => [],
        ]);

        $confLine = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_sold_source_id' => $soldSource->id,
            'product_id' => $this->product->id,
            'location_id' => $location->id,
            'allocated_base_quantity' => 1,
        ]);

        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-DATE-001',
            'receival_number' => 'CR-DATE-001',
            'status' => 'APPROVED',
            'date' => '2026-08-01',
        ]);

        $receiving = ConsignmentReceiving::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $location->id,
            'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-DATE-001',
            'status' => ConsignmentReceiving::STATUS_APPROVED,
            'date' => '2026-08-01',
        ]);

        $recLine = \Modules\Consignment\Entities\ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'subtotal_cost' => 50000,
            'subtotal_dpp' => 50000,
            'total_cost' => 50000,
            'total_dpp' => 50000,
        ]);

        $crd = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $recLine->id,
            'product_id' => $this->product->id,
            'quantity_received' => 1,
            'received_base_quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
        ]);

        ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_receiving_detail_id' => $crd->id,
            'allocated_base_quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);

        $adminUser = User::factory()->create(['is_active' => true]);
        $roleAdmin = Role::firstOrCreate(['name' => 'Admin Temp', 'guard_name' => 'web']);
        $adminUser->assignRole($roleAdmin);
        $adminUser->settings()->attach($this->setting1->id, ['role_id' => $roleAdmin->id]);

        $previewService = new ConsignmentBillingPreviewService();
        $conversionService = new ConsignmentBillingConversionService($previewService);

        $this->consignmentPurchase = $conversionService->convert(
            $confirmation->id,
            $this->setting1->id,
            $adminUser->id,
            [
                'supplier_invoice_number' => 'INV-DATE-001',
                'invoice_date' => '2026-08-01',
                'due_date' => '2026-08-31',
                'payment_term_id' => $this->paymentTerm->id,
            ]
        );

        $roleBoth = Role::firstOrCreate(['name' => 'Date Both', 'guard_name' => 'web']);
        $roleBoth->syncPermissions(['purchases.show', 'purchases.reporting-date.override', 'purchases.due-date.override']);

        $roleRepOnly = Role::firstOrCreate(['name' => 'Date Rep Only', 'guard_name' => 'web']);
        $roleRepOnly->syncPermissions(['purchases.show', 'purchases.reporting-date.override']);

        $roleDueOnly = Role::firstOrCreate(['name' => 'Date Due Only', 'guard_name' => 'web']);
        $roleDueOnly->syncPermissions(['purchases.show', 'purchases.due-date.override']);

        $roleNone = Role::firstOrCreate(['name' => 'Date None', 'guard_name' => 'web']);
        $roleNone->syncPermissions(['purchases.show']);

        $roleGlobal = Role::firstOrCreate(['name' => 'Date Global', 'guard_name' => 'web']);
        $roleGlobal->syncPermissions([
            'purchases.show',
            'purchasePayments.global.access',
            'purchasePayments.create',
            'purchases.reporting-date.override',
            'purchases.due-date.override',
        ]);

        $this->userBothPermissions = User::factory()->create(['is_active' => true]);
        $this->userBothPermissions->assignRole($roleBoth);
        $this->userBothPermissions->settings()->attach($this->setting1->id, ['role_id' => $roleBoth->id]);

        $this->userReportingOnly = User::factory()->create(['is_active' => true]);
        $this->userReportingOnly->assignRole($roleRepOnly);
        $this->userReportingOnly->settings()->attach($this->setting1->id, ['role_id' => $roleRepOnly->id]);

        $this->userDueOnly = User::factory()->create(['is_active' => true]);
        $this->userDueOnly->assignRole($roleDueOnly);
        $this->userDueOnly->settings()->attach($this->setting1->id, ['role_id' => $roleDueOnly->id]);

        $this->userNoPermissions = User::factory()->create(['is_active' => true]);
        $this->userNoPermissions->assignRole($roleNone);
        $this->userNoPermissions->settings()->attach($this->setting1->id, ['role_id' => $roleNone->id]);

        $this->superAdmin = User::factory()->create(['is_active' => true]);
        $this->superAdmin->assignRole($superAdminRole);
        $this->superAdmin->settings()->attach($this->setting1->id, ['role_id' => $superAdminRole->id]);

        $this->globalUser = User::factory()->create(['is_active' => true]);
        $this->globalUser->assignRole($roleGlobal);
        $this->globalUser->settings()->attach($this->setting2->id, ['role_id' => $roleGlobal->id]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->service = app(DocumentDateAdjustmentService::class);
        session(['setting_id' => $this->setting1->id]);
    }

    /**
     * Task 2.1: Reporting set, replace, clear, explicit due-date replacement, original-date fallback,
     * unchanged payment terms, and standard audit records.
     */
    public function test_reporting_date_set_replace_clear_and_original_date_fallback(): void
    {
        $originalDate = $this->consignmentPurchase->date->format('Y-m-d');
        $this->assertEquals('2026-08-01', $originalDate);
        $this->assertEquals('2026-08-01', $this->consignmentPurchase->reporting_date->format('Y-m-d'));
        $this->assertEquals('2026-08-01', $this->consignmentPurchase->effective_date->format('Y-m-d'));

        // 1. Set reporting date override
        $commandSet = new DateAdjustmentCommand(
            reportingAction: 'set',
            reportingDate: '2026-08-10',
            dueDateAction: 'keep',
            dueDate: null,
            reason: 'First reporting adjustment',
        );
        $result1 = $this->service->adjustDates($this->consignmentPurchase, $commandSet, $this->userBothPermissions);

        $this->consignmentPurchase->refresh();
        $this->assertEquals('2026-08-10', $this->consignmentPurchase->reporting_date->format('Y-m-d'));
        $this->assertEquals('2026-08-10', $this->consignmentPurchase->effective_date->format('Y-m-d'));
        $this->assertEquals('2026-08-01', $this->consignmentPurchase->date->format('Y-m-d')); // Original unchanged
        $this->assertNotNull($result1->reportingAudit);
        $this->assertEquals('2026-08-01', Carbon::parse($result1->reportingAudit->prior_override)->format('Y-m-d'));
        $this->assertEquals('2026-08-10 00:00:00', $result1->reportingAudit->resulting_override);
        $this->assertEquals('First reporting adjustment', $result1->reportingAudit->reason);

        // 2. Replace reporting date override
        $commandReplace = new DateAdjustmentCommand(
            reportingAction: 'set',
            reportingDate: '2026-08-15',
            dueDateAction: 'keep',
            dueDate: null,
            reason: 'Second reporting adjustment',
        );
        $result2 = $this->service->adjustDates($this->consignmentPurchase, $commandReplace, $this->userBothPermissions);

        $this->consignmentPurchase->refresh();
        $this->assertEquals('2026-08-15', $this->consignmentPurchase->reporting_date->format('Y-m-d'));
        $this->assertEquals('2026-08-15', $this->consignmentPurchase->effective_date->format('Y-m-d'));
        $this->assertEquals('2026-08-01', $this->consignmentPurchase->date->format('Y-m-d')); // Original unchanged
        $this->assertNotNull($result2->reportingAudit);
        $this->assertEquals('2026-08-10', Carbon::parse($result2->reportingAudit->prior_override)->format('Y-m-d'));
        $this->assertEquals('2026-08-15 00:00:00', $result2->reportingAudit->resulting_override);

        // 3. Clear reporting date override
        $commandClear = new DateAdjustmentCommand(
            reportingAction: 'clear',
            reportingDate: null,
            dueDateAction: 'keep',
            dueDate: null,
            reason: 'Revert to original transaction date',
        );
        $result3 = $this->service->adjustDates($this->consignmentPurchase, $commandClear, $this->userBothPermissions);

        $this->consignmentPurchase->refresh();
        $this->assertNull($this->consignmentPurchase->reporting_date);
        $this->assertEquals('2026-08-01', $this->consignmentPurchase->effective_date->format('Y-m-d')); // Fallback to original
        $this->assertEquals('2026-08-01', $this->consignmentPurchase->date->format('Y-m-d')); // Original untouched
        $this->assertNotNull($result3->reportingAudit);
        $this->assertNull($result3->reportingAudit->resulting_override);

        // Assert all audits are recorded on the purchase
        $this->assertCount(3, $this->consignmentPurchase->reportingDateAudits);
    }

    /**
     * Task 2.1: Due-date replacement does not rewrite original date or reporting date, does not
     * recalculate payment terms, and appends DueDateAudit.
     */
    public function test_explicit_due_date_replacement_leaves_payment_term_and_original_date_unchanged(): void
    {
        $originalDueDate = $this->consignmentPurchase->due_date->format('Y-m-d');
        $this->assertEquals('2026-08-31', $originalDueDate);
        $this->assertEquals($this->paymentTerm->id, $this->consignmentPurchase->payment_term_id);

        $command = new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: '2026-09-15',
            reason: 'Extend credit terms with supplier',
        );
        $result = $this->service->adjustDates($this->consignmentPurchase, $command, $this->userBothPermissions);

        $this->consignmentPurchase->refresh();
        $this->assertEquals('2026-09-15', $this->consignmentPurchase->due_date->format('Y-m-d'));
        $this->assertEquals('2026-08-01', $this->consignmentPurchase->date->format('Y-m-d'));
        $this->assertEquals('2026-08-01', $this->consignmentPurchase->reporting_date->format('Y-m-d'));
        $this->assertEquals($this->paymentTerm->id, $this->consignmentPurchase->payment_term_id);

        $this->assertNotNull($result->dueDateAudit);
        $this->assertEquals('2026-08-31', Carbon::parse($result->dueDateAudit->prior_due_date)->format('Y-m-d'));
        $this->assertEquals('2026-09-15 00:00:00', $result->dueDateAudit->resulting_due_date);
        $this->assertEquals('Extend credit terms with supplier', $result->dueDateAudit->reason);
        $this->assertCount(1, $this->consignmentPurchase->dueDateAudits);
    }

    /**
     * Task 2.1: Changing reporting date does NOT automatically recalculate due date.
     */
    public function test_reporting_date_change_does_not_automatically_recalculate_due_date(): void
    {
        $initialDueDate = $this->consignmentPurchase->due_date->format('Y-m-d');

        $command = new DateAdjustmentCommand(
            reportingAction: 'set',
            reportingDate: '2026-08-20',
            dueDateAction: 'keep',
            dueDate: null,
            reason: 'Audit cutoff adjustment',
        );
        $this->service->adjustDates($this->consignmentPurchase, $command, $this->userBothPermissions);

        $this->consignmentPurchase->refresh();
        $this->assertEquals('2026-08-20', $this->consignmentPurchase->reporting_date->format('Y-m-d'));
        $this->assertEquals($initialDueDate, $this->consignmentPurchase->due_date->format('Y-m-d'));
    }

    /**
     * Task 2.2: Normal endpoint retains independent permissions, reason validation, and error handling.
     */
    public function test_normal_entry_path_independent_permissions_and_reason_validation(): void
    {
        // 1. User with reporting-only permission can adjust reporting date
        $this->actingAs($this->userReportingOnly);
        $response = $this->putJson(route('purchases.date-adjustment.update', $this->consignmentPurchase), [
            'reporting_action' => 'set',
            'reporting_date' => '2026-08-12',
            'due_date_action' => 'keep',
            'reason' => 'Reporting only adjustment',
        ]);
        $response->assertOk();
        $response->assertJson(['success' => true]);

        // 2. User with reporting-only permission is DENIED adjusting due date
        $response = $this->putJson(route('purchases.date-adjustment.update', $this->consignmentPurchase), [
            'reporting_action' => 'keep',
            'due_date_action' => 'set',
            'due_date' => '2026-09-20',
            'reason' => 'Unauthorized due date change',
        ]);
        $response->assertForbidden();

        // 3. User with due-only permission can adjust due date
        $this->actingAs($this->userDueOnly);
        $response = $this->putJson(route('purchases.date-adjustment.update', $this->consignmentPurchase), [
            'reporting_action' => 'keep',
            'due_date_action' => 'set',
            'due_date' => '2026-09-20',
            'reason' => 'Authorized due date change',
        ]);
        $response->assertOk();

        // 4. User with due-only permission is DENIED adjusting reporting date
        $response = $this->putJson(route('purchases.date-adjustment.update', $this->consignmentPurchase), [
            'reporting_action' => 'set',
            'reporting_date' => '2026-08-22',
            'due_date_action' => 'keep',
            'reason' => 'Unauthorized reporting change',
        ]);
        $response->assertForbidden();

        // 5. Empty reason fails validation
        $this->actingAs($this->userBothPermissions);
        $response = $this->putJson(route('purchases.date-adjustment.update', $this->consignmentPurchase), [
            'reporting_action' => 'set',
            'reporting_date' => '2026-08-25',
            'due_date_action' => 'keep',
            'reason' => '',
        ]);
        $response->assertStatus(422);
    }

    /**
     * Task 2.2: Combined requests fail atomically rollback if any part fails authorization.
     */
    public function test_combined_request_fails_atomically_when_one_part_is_unauthorized(): void
    {
        $this->consignmentPurchase->update(['reporting_date' => null, 'due_date' => '2026-08-31']);
        $initialReportingDate = $this->consignmentPurchase->reporting_date;
        $initialDueDate = $this->consignmentPurchase->due_date->format('Y-m-d');

        // User has reporting permission but lacks due date permission.
        $this->actingAs($this->userReportingOnly);

        $response = $this->putJson(route('purchases.date-adjustment.update', $this->consignmentPurchase), [
            'reporting_action' => 'set',
            'reporting_date' => '2026-08-25',
            'due_date_action' => 'set',
            'due_date' => '2026-09-25',
            'reason' => 'Combined attempt where due date is unauthorized',
        ]);

        $response->assertForbidden();

        // Verify neither field was updated
        $this->consignmentPurchase->refresh();
        $this->assertEquals($initialReportingDate, $this->consignmentPurchase->reporting_date);
        $this->assertEquals($initialDueDate, $this->consignmentPurchase->due_date->format('Y-m-d'));
        $this->assertEquals(0, $this->consignmentPurchase->reportingDateAudits()->count());
        $this->assertEquals(0, $this->consignmentPurchase->dueDateAudits()->count());
    }

    /**
     * Task 2.2: Tenant and archive checks.
     */
    public function test_date_adjustment_denied_for_foreign_tenant_or_archived_purchase(): void
    {
        $this->actingAs($this->userBothPermissions);

        // Foreign tenant session
        session(['setting_id' => $this->setting2->id]);
        $response = $this->putJson(route('purchases.date-adjustment.update', $this->consignmentPurchase), [
            'reporting_action' => 'set',
            'reporting_date' => '2026-08-25',
            'due_date_action' => 'keep',
            'reason' => 'Foreign tenant attempt',
        ]);
        $response->assertForbidden();

        // Archived purchase
        session(['setting_id' => $this->setting1->id]);
        \Illuminate\Support\Facades\DB::table('purchases')
            ->where('id', $this->consignmentPurchase->id)
            ->update(['archived_at' => now(), 'archived_by' => $this->userBothPermissions->id]);

        $response = $this->putJson(route('purchases.date-adjustment.update', $this->consignmentPurchase), [
            'reporting_action' => 'set',
            'reporting_date' => '2026-08-25',
            'due_date_action' => 'keep',
            'reason' => 'Archived attempt',
        ]);
        $response->assertNotFound();
    }

    /**
     * Task 2.2: Super Admin can adjust dates without explicit permission grant.
     */
    public function test_super_admin_can_adjust_dates_on_consignment_purchase(): void
    {
        $this->actingAs($this->superAdmin);
        session(['setting_id' => $this->setting1->id]);

        $response = $this->putJson(route('purchases.date-adjustment.update', $this->consignmentPurchase), [
            'reporting_action' => 'set',
            'reporting_date' => '2026-08-27',
            'due_date_action' => 'set',
            'due_date' => '2026-09-27',
            'reason' => 'Super Admin override',
        ]);

        $response->assertOk();
        $this->consignmentPurchase->refresh();
        $this->assertEquals('2026-08-27', $this->consignmentPurchase->reporting_date->format('Y-m-d'));
        $this->assertEquals('2026-09-27', $this->consignmentPurchase->due_date->format('Y-m-d'));
    }

    /**
     * Task 2.2: Global endpoint retains global permissions and allows date adjustments across settings.
     */
    public function test_global_date_adjustment_endpoint_works_for_authorized_global_user(): void
    {
        $this->actingAs($this->globalUser);
        session(['setting_id' => $this->setting2->id]);

        $response = $this->putJson(route('purchases.global-payments.date-adjustment.update', $this->consignmentPurchase->id), [
            'reporting_action' => 'set',
            'reporting_date' => '2026-08-28',
            'due_date_action' => 'set',
            'due_date' => '2026-09-28',
            'reason' => 'Global payment surface date adjustment',
        ]);

        $response->assertOk();
        $this->consignmentPurchase->refresh();
        $this->assertEquals('2026-08-28', $this->consignmentPurchase->reporting_date->format('Y-m-d'));
        $this->assertEquals('2026-09-28', $this->consignmentPurchase->due_date->format('Y-m-d'));
    }

    /**
     * Task 2.3: Shared purchase show view displays persisted date adjustments, modal button, and audits.
     */
    public function test_shared_purchase_show_view_displays_adjusted_dates_and_audit_history(): void
    {
        $this->actingAs($this->userBothPermissions);

        $command = new DateAdjustmentCommand(
            reportingAction: 'set',
            reportingDate: '2026-08-18',
            dueDateAction: 'set',
            dueDate: '2026-09-18',
            reason: 'Audit and presentation test',
        );
        $this->service->adjustDates($this->consignmentPurchase, $command, $this->userBothPermissions);

        $response = $this->get(route('purchases.show', $this->consignmentPurchase));

        $response->assertOk();
        $response->assertSee('2026-08-18');
        $response->assertSee('2026-09-18');
        $response->assertSee('dateAdjustmentModalButton');
        $response->assertSee('Riwayat Penyesuaian Tanggal');
        $response->assertSee('Audit and presentation test');
    }

    /**
     * Task 2.3: Existing due-date consumer filters by replacement due date on consignment purchase.
     */
    public function test_due_date_query_consumer_matches_replacement_due_date(): void
    {
        $newDueDate = '2026-10-25';
        $command = new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Consumer query test',
        );
        $this->service->adjustDates($this->consignmentPurchase, $command, $this->userBothPermissions);

        // 1. Direct database query consumer
        $matches = Purchase::where('setting_id', $this->setting1->id)
            ->whereDate('due_date', '2026-10-25')
            ->get();

        $this->assertTrue($matches->contains($this->consignmentPurchase));

        // 2. Real payment view consumer (global payment candidate list displays replacement due date)
        $this->actingAs($this->globalUser);
        session(['setting_id' => $this->setting2->id]);

        $response = $this->get(route('purchases.global-payments.create', ['supplier' => $this->supplier->id]));
        $response->assertOk();
        $response->assertSee(Carbon::parse($newDueDate)->format('d M Y'));

        // 3. Real report consumer (PurchaseReport with dateBasis = 'due_date')
        session(['setting_id' => $this->setting1->id]);
        Livewire::test(PurchaseReport::class)
            ->set('settingId', $this->setting1->id)
            ->set('dateBasis', 'due_date')
            ->set('startDate', '2026-10-01')
            ->set('endDate', '2026-10-31')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                $ids = $purchases->pluck('purchase_id')->toArray();
                return in_array($this->consignmentPurchase->id, $ids);
            });
    }
}
