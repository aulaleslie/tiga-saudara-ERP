<?php

namespace Tests\Feature\Adjustment;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Focused coverage for the `notifications:sync` command's Adjustment branch,
 * fixed as fallout from converting AdjustmentStatus to a backed enum
 * (tasks 1.2-1.4): the branch previously compared against a stale
 * 'PENDING APPROVAL' literal and read a nonexistent Adjustment::setting_id,
 * so it never actually fired.
 */
class SyncNotificationsAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_command_notifies_approval_needed_for_waiting_approval_adjustment()
    {
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'Rp',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);
        $setting = Setting::create([
            'company_name' => 'Test Company', 'company_email' => 'test@company.com',
            'company_phone' => '123456789', 'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer', 'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);
        $location = Location::create([
            'name' => 'Gudang Sync', 'setting_id' => $setting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        Permission::findOrCreate('adjustments.approval', 'web');
        Permission::findOrCreate('adjustments.edit', 'web');
        $approverRole = Role::firstOrCreate(['name' => 'Adjustment Approver']);
        $approverRole->givePermissionTo('adjustments.approval');

        $approver = User::factory()->create(['is_active' => 1]);
        $approver->settings()->attach($setting->id, ['role_id' => $approverRole->id]);

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SYNC-WAIT',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::WaitingApproval,
            'location_id' => $location->id,
            'count_draft' => ['schema_version' => 1, 'rows' => []],
        ]);

        $this->artisan('notifications:sync')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'source_type' => Adjustment::class,
            'source_id' => $adjustment->id,
            'category' => 'approval',
        ]);
    }

    public function test_sync_command_notifies_revision_needed_for_rejected_adjustment()
    {
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'Rp',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);
        $setting = Setting::create([
            'company_name' => 'Test Company', 'company_email' => 'test@company.com',
            'company_phone' => '123456789', 'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer', 'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);
        $location = Location::create([
            'name' => 'Gudang Sync 2', 'setting_id' => $setting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        Permission::findOrCreate('adjustments.approval', 'web');
        Permission::findOrCreate('adjustments.edit', 'web');
        $editorRole = Role::firstOrCreate(['name' => 'Adjustment Editor']);
        $editorRole->givePermissionTo('adjustments.edit');

        $editor = User::factory()->create(['is_active' => 1]);
        $editor->settings()->attach($setting->id, ['role_id' => $editorRole->id]);

        $rejector = User::factory()->create(['is_active' => 1]);
        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SYNC-REJ',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::Rejected,
            'location_id' => $location->id,
            'count_draft' => ['schema_version' => 1, 'rows' => []],
            'rejected_by' => $rejector->id,
            'rejected_at' => now(),
            'rejection_reason' => 'Data tidak sesuai.',
        ]);

        $this->artisan('notifications:sync')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'source_type' => Adjustment::class,
            'source_id' => $adjustment->id,
            'category' => 'revision',
        ]);
    }
}
