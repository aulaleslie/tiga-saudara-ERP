<?php

namespace Modules\Setting\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\PaymentMethod;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Get the CASH chart of account
        $cashAccount = ChartOfAccount::where('name', 'Kas')->first();

        if ($cashAccount) {
            PaymentMethod::create([
                'name' => 'CASH',
                'coa_id' => $cashAccount->id,
                'is_cash' => true,
                'is_available_in_pos' => true,
            ]);
        }
    }
}
