<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;

class MasterDataLifecycleService
{
    /**
     * Deactivate a master record with domain-specific guard validations.
     *
     * @throws ValidationException
     */
    public function deactivate(Model $record): bool
    {
        return DB::transaction(function () use ($record) {
            if ($record instanceof Tax) {
                $record = Tax::query()->lockForUpdate()->findOrFail($record->id);
            }

            $this->validateDeactivationGuards($record);

            $record->is_active = false;
            return $record->save();
        });
    }

    /**
     * Reactivate a master record with domain-specific guard validations.
     *
     * @throws ValidationException
     */
    public function reactivate(Model $record): bool
    {
        $this->validateReactivationGuards($record);

        $record->is_active = true;
        return $record->save();
    }

    /**
     * Validate deactivation rules for covered models.
     *
     * @throws ValidationException
     */
    protected function validateDeactivationGuards(Model $record): void
    {
        if ($record instanceof Tax) {
            if ($record->is_default) {
                $otherActiveTaxExists = Tax::query()
                    ->where('id', '!=', $record->id)
                    ->where('is_active', true)
                    ->exists();

                if (! $otherActiveTaxExists) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Tidak dapat menonaktifkan pajak default karena tidak ada pajak aktif lain sebagai pengganti.',
                    ]);
                }

                // If another active tax exists, transfer is_default flag to the first active tax
                $newDefaultTax = Tax::query()
                    ->where('id', '!=', $record->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if ($newDefaultTax) {
                    $newDefaultTax->update(['is_default' => true]);
                    $record->is_default = false;
                }
            }
        }

        if ($record instanceof Location) {
            // Check if this is the only active standard location for this setting
            if (! $record->is_consignment) {
                $otherActiveLocationExists = Location::query()
                    ->where('setting_id', $record->setting_id)
                    ->where('id', '!=', $record->id)
                    ->where('is_consignment', false)
                    ->where('is_active', true)
                    ->exists();

                if (! $otherActiveLocationExists) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Tidak dapat menonaktifkan lokasi karena bisnis harus memiliki setidaknya satu lokasi standar yang aktif.',
                    ]);
                }
            }

            $hasStock = \Modules\Product\Entities\ProductStock::query()
                ->where('location_id', $record->id)
                ->where(function ($query) {
                    $query->where('quantity', '!=', 0)
                        ->orWhere('quantity_tax', '!=', 0)
                        ->orWhere('quantity_non_tax', '!=', 0)
                        ->orWhere('broken_quantity', '!=', 0)
                        ->orWhere('broken_quantity_tax', '!=', 0)
                        ->orWhere('broken_quantity_non_tax', '!=', 0);
                })
                ->exists();

            if ($hasStock) {
                throw ValidationException::withMessages([
                    'is_active' => 'Tidak dapat menonaktifkan lokasi karena masih memiliki stok aktif.',
                ]);
            }

            $isActiveSalesLocation = SettingSaleLocation::query()
                ->where('location_id', $record->id)
                ->where('is_enabled', true)
                ->exists();

            if ($isActiveSalesLocation) {
                throw ValidationException::withMessages([
                    'is_active' => 'Tidak dapat menonaktifkan lokasi karena masih dikonfigurasi sebagai lokasi penjualan.',
                ]);
            }
        }

        if ($record instanceof ChartOfAccount) {
            // Check for active child accounts
            $activeChildExists = ChartOfAccount::query()
                ->where('parent_account_id', $record->id)
                ->where('is_active', true)
                ->exists();

            if ($activeChildExists) {
                throw ValidationException::withMessages([
                    'is_active' => 'Tidak dapat menonaktifkan akun yang masih memiliki sub-akun aktif.',
                ]);
            }

            // Check for active payment methods referencing this account
            $activePaymentMethodExists = PaymentMethod::query()
                ->where('coa_id', $record->id)
                ->where('is_active', true)
                ->exists();

            if ($activePaymentMethodExists) {
                throw ValidationException::withMessages([
                    'is_active' => 'Tidak dapat menonaktifkan akun yang masih digunakan oleh metode pembayaran aktif.',
                ]);
            }
        }
    }

    /**
     * Validate reactivation rules for covered models.
     *
     * @throws ValidationException
     */
    protected function validateReactivationGuards(Model $record): void
    {
        if ($record instanceof ChartOfAccount) {
            if ($record->parent_account_id) {
                $parent = ChartOfAccount::find($record->parent_account_id);
                if ($parent && ! $parent->is_active) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Tidak dapat mengaktifkan akun karena akun induk sedang nonaktif.',
                    ]);
                }
            }
        }
    }
}
