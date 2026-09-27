<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Normalize 'Completed' and 'Shipped' to 'DISPATCHED'
        Illuminate\Support\Facades\DB::table('sales')
            ->whereIn('status', ['Completed', 'Shipped'])
            ->update(['status' => \Modules\Sale\Entities\Sale::STATUS_DISPATCHED]);

        // Normalize 'Pending' to 'WAITING_APPROVAL'
        Illuminate\Support\Facades\DB::table('sales')
            ->where('status', 'Pending')
            ->update(['status' => \Modules\Sale\Entities\Sale::STATUS_WAITING_APPROVAL]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No specific revert logic needed as we want to keep DISPATCHED
    }
};
