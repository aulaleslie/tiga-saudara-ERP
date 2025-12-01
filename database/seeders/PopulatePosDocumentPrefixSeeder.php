<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Setting\Entities\Setting;

class PopulatePosDocumentPrefixSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::whereNull('pos_document_prefix')
            ->update([
                'pos_document_prefix' => DB::raw('sale_prefix_document')
            ]);
    }
}
