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
     * Permissions for the Karyawan role.
     * Mapped from requirements:
     * - retur beli, retur jual, pos, transfer stock, breakage,
     * - terima/keluar barang, stock opname, terminal Harga, expense create
     */
    private array $karyawanPermissions = [
        // Retur Beli (Purchase Returns)
        'purchaseReturns.access',
        'purchaseReturns.create',
        'purchaseReturns.edit',
        'purchaseReturns.show',
        'purchaseReturns.approval',
        'purchaseReturns.dispatchRequest',
        'purchaseReturnSettlements.access',
        'purchaseReturnSettlements.submit',

        // Retur Jual (Sales Returns)
        'saleReturns.access',
        'saleReturns.create',
        'saleReturns.edit',
        'saleReturns.show',
        'saleReturns.approve',
        'saleReturns.receive',

        // POS
        'pos.access',
        'pos.create',
        'pos.transactions.access',

        // Transfer Stock
        'stockTransfers.access',
        'stockTransfers.create',
        'stockTransfers.edit',
        'stockTransfers.show',
        'stockTransfers.dispatch',
        'stockTransfers.receive',

        // Breakage
        'adjustments.access',
        'adjustments.breakage.create',
        'adjustments.breakage.edit',
        'adjustments.show',

        // Terima/Keluar Barang (Receiving/Dispatch)
        'purchaseReceivings.access',
        'sales.dispatch',
        'purchases.receive',

        // Stock Opname (Adjustments)
        'adjustments.create',
        'adjustments.edit',

        // Terminal Harga (Price Points)
        'pricePoints.access',

        // Expense Create
        'expenses.access',
        'expenses.create',
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        DB::transaction(function () {
            // Step 1: Create or get the Manager role with all permissions
            $managerRole = Role::firstOrCreate(['name' => 'Manager']);
            $allPermissions = Permission::pluck('name')->toArray();
            $managerRole->syncPermissions($allPermissions);

            // Step 2: Create or get the Karyawan role with specific permissions
            $karyawanRole = Role::firstOrCreate(['name' => 'Karyawan']);
            // Filter to only existing permissions
            $existingKaryawanPermissions = Permission::whereIn('name', $this->karyawanPermissions)
                ->pluck('name')
                ->toArray();
            $karyawanRole->syncPermissions($existingKaryawanPermissions);

            // Step 3: Create Manager user if not exists
            $managerUser = User::firstOrCreate(
                ['email' => 'manager@tiga-computer.com'],
                [
                    'name' => 'Manager',
                    'password' => Hash::make('Manager@1234'),
                    'is_active' => 1,
                ]
            );

            // Step 4: Create Karyawan user if not exists
            $karyawanUser = User::firstOrCreate(
                ['email' => 'karyawan@tiga-computer.com'],
                [
                    'name' => 'Karyawan',
                    'password' => Hash::make('Karyawan@1234'),
                    'is_active' => 1,
                ]
            );

            // Step 5: Get all settings (tenants)
            $settings = Setting::all();

            // Step 6: Attach users to all tenants with their respective roles
            foreach ($settings as $setting) {
                // Attach manager to tenant
                if (!$managerUser->settings()->where('setting_id', $setting->id)->exists()) {
                    $managerUser->settings()->attach($setting->id, ['role_id' => $managerRole->id]);
                }

                // Attach karyawan to tenant
                if (!$karyawanUser->settings()->where('setting_id', $setting->id)->exists()) {
                    $karyawanUser->settings()->attach($setting->id, ['role_id' => $karyawanRole->id]);
                }
            }

            $this->command->info('Created Manager role with ' . count($allPermissions) . ' permissions');
            $this->command->info('Created Karyawan role with ' . count($existingKaryawanPermissions) . ' permissions');
            $this->command->info('Manager user assigned to ' . $settings->count() . ' tenants');
            $this->command->info('Karyawan user assigned to ' . $settings->count() . ' tenants');
        });
    }
}
