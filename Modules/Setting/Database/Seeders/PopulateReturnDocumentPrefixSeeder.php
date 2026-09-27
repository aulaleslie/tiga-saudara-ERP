<?php

namespace Modules\Setting\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Modules\Setting\Entities\Setting;

class PopulateReturnDocumentPrefixSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();

        Setting::whereNull('purchase_return_prefix_document')
            ->update(['purchase_return_prefix_document' => 'PRRN']);

        Setting::whereNull('sale_return_prefix_document')
            ->update(['sale_return_prefix_document' => 'SLRN']);
    }
}
