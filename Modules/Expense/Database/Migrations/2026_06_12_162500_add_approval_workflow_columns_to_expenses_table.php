<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('status')->default('DRAFT')->after('reference');
            $table->text('rejection_reason')->nullable()->after('status');
            $table->timestamp('archived_at')->nullable()->after('rejection_reason');
            $table->unsignedBigInteger('archived_by')->nullable()->after('archived_at');
            $table->text('archive_reason')->nullable()->after('archived_by');

            // 1.2 Add a database uniqueness constraint for expenses.setting_id plus expenses.reference.
            $table->unique(['setting_id', 'reference'], 'expenses_setting_id_reference_unique');
            
            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
        });

        // 1.3 Backfill existing expenses to APPROVED status
        DB::table('expenses')->update(['status' => 'APPROVED']);

        // 1.4 Backfill between legacy expenses.details and expense_details where one representation is missing and useful.
        $expensesWithoutDetails = DB::table('expenses')
            ->leftJoin('expense_details', 'expenses.id', '=', 'expense_details.expense_id')
            ->whereNull('expense_details.id')
            ->select('expenses.id', 'expenses.details', 'expenses.amount')
            ->get();

        $newDetails = [];
        foreach ($expensesWithoutDetails as $expense) {
            $newDetails[] = [
                'expense_id' => $expense->id,
                'name' => $expense->details ?: 'Legacy Expense',
                'amount' => $expense->amount / 100, // expense amount is in cents, expense_details is decimal
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($newDetails)) {
            DB::table('expense_details')->insert($newDetails);
        }

        $expensesWithoutLegacyDetails = DB::table('expenses')
            ->join('expense_details', 'expenses.id', '=', 'expense_details.expense_id')
            ->whereNull('expenses.details')
            ->select('expenses.id', 'expense_details.name')
            ->get()
            ->groupBy('id');

        foreach ($expensesWithoutLegacyDetails as $expenseId => $details) {
            DB::table('expenses')
                ->where('id', $expenseId)
                ->update(['details' => $details->pluck('name')->implode(', ')]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['archived_by']);
            $table->dropUnique('expenses_setting_id_reference_unique');
            $table->dropColumn(['status', 'rejection_reason', 'archived_at', 'archived_by', 'archive_reason']);
        });
    }
};
