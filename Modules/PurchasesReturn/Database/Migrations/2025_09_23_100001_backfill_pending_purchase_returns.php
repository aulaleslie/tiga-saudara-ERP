<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('purchase_returns')
            ->where('approval_status', 'draft')
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->update(['approval_status' => 'pending']);
    }

    public function down(): void
    {
        DB::table('purchase_returns')
            ->where('approval_status', 'pending')
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->update(['approval_status' => 'draft']);
    }
};
