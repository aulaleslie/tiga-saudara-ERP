<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class TenantUserSeeder extends Seeder
{
    /**
     * Supported POS cashier bundle for seeded demo data.
     */
    private array $cashierPermissions = [
        'pos.access',
        'pos.sell',
        'pos.sessions.open',
        'pos.sessions.close',
        'pos.checkout.payment',
        'pos.safeDrops.create',
        'pos.transactions.view',
        'pos.transactions.save',
        'pos.transactions.load',
    ];

    /**
     * Supported POS floor-staff bundle for seeded demo data.
     */
    private array $floorStaffPermissions = [
        'pos.access',
        'pos.sell',
        'pos.sessions.open',
        'pos.sessions.close',
        'pos.transactions.view',
        'pos.transactions.save',
        'pos.transactions.load',
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        DB::transaction(function () {
            $allPermissions = Permission::pluck('name')->toArray();

            // Keep the seeded Manager role as a global all-permissions role.
            $managerRole = Role::firstOrCreate(['name' => 'Manager']);
            $managerRole->syncPermissions($allPermissions);

            $cashierRole = Role::firstOrCreate(['name' => 'Cashier']);
            $cashierPermissions = $this->existingPermissions($this->cashierPermissions);
            $cashierRole->syncPermissions($cashierPermissions);

            $floorStaffRole = Role::firstOrCreate(['name' => 'Floor Staff']);
            $floorStaffPermissions = $this->existingPermissions($this->floorStaffPermissions);
            $floorStaffRole->syncPermissions($floorStaffPermissions);

            $managerUser = User::firstOrCreate(
                ['email' => 'manager@tigacom.com'],
                [
                    'name' => 'Manager',
                    'password' => Hash::make('12345678'),
                    'is_active' => 1,
                ]
            );

            $cashierUser = User::firstOrCreate(
                ['email' => 'kasir@tigacom.com'],
                [
                    'name' => 'Cashier',
                    'password' => Hash::make('12345678'),
                    'is_active' => 1,
                ]
            );

            $floorStaffUser1 = User::firstOrCreate(
                ['email' => 'staff1@tigacom.com'],
                [
                    'name' => 'Floor Staff 1',
                    'password' => Hash::make('12345678'),
                    'is_active' => 1,
                ]
            );

            $floorStaffUser2 = User::firstOrCreate(
                ['email' => 'staff2@tigacom.com'],
                [
                    'name' => 'Floor Staff 2',
                    'password' => Hash::make('12345678'),
                    'is_active' => 1,
                ]
            );

            $floorStaffUser3 = User::firstOrCreate(
                ['email' => 'staff3@tigacom.com'],
                [
                    'name' => 'Floor Staff 3',
                    'password' => Hash::make('12345678'),
                    'is_active' => 1,
                ]
            );

            $floorStaffUser4 = User::firstOrCreate(
                ['email' => 'staff4@tigacom.com'],
                [
                    'name' => 'Floor Staff 4',
                    'password' => Hash::make('12345678'),
                    'is_active' => 1,
                ]
            );
            

            $settings = Setting::all();

            foreach ($settings as $setting) {
                $this->assignUserToSetting($managerUser, $setting->id, $managerRole->id);
                $this->assignUserToSetting($cashierUser, $setting->id, $cashierRole->id);
                $this->assignUserToSetting($floorStaffUser1, $setting->id, $floorStaffRole->id);
                $this->assignUserToSetting($floorStaffUser2, $setting->id, $floorStaffRole->id);
                $this->assignUserToSetting($floorStaffUser3, $setting->id, $floorStaffRole->id);
                $this->assignUserToSetting($floorStaffUser4, $setting->id, $floorStaffRole->id);
            }

            $this->command->info('Created Manager role with ' . count($allPermissions) . ' permissions');
            $this->command->info('Created Cashier role with ' . count($cashierPermissions) . ' permissions');
            $this->command->info('Created Floor Staff role with ' . count($floorStaffPermissions) . ' permissions');
            $this->command->info('Manager user assigned to ' . $settings->count() . ' tenants');
            $this->command->info('Cashier user assigned to ' . $settings->count() . ' tenants');
            $this->command->info('Floor Staff user assigned to ' . $settings->count() . ' tenants');
        });
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    private function existingPermissions(array $permissions): array
    {
        return Permission::whereIn('name', $permissions)
            ->pluck('name')
            ->toArray();
    }

    private function assignUserToSetting(User $user, int $settingId, int $roleId): void
    {
        $existingAssignment = $user->settings()->where('setting_id', $settingId)->exists();

        if (! $existingAssignment) {
            $user->settings()->attach($settingId, ['role_id' => $roleId]);

            return;
        }

        $user->settings()->updateExistingPivot($settingId, ['role_id' => $roleId]);
    }
}
