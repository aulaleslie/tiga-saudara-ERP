<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

$indexes = collect(DB::select('SHOW INDEX FROM setting_sale_locations'))->pluck('Key_name')->unique()->values()->all();
echo "INDEXES:\n";
print_r($indexes);

echo "\nCOLUMNS:\n";
print_r(Schema::getColumnListing('setting_sale_locations'));
