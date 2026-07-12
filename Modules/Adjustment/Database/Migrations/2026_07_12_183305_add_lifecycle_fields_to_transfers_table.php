<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('transfers', 'revision')) {
                $table->unsignedInteger('revision')->default(1)->after('id');
            }

            if (!Schema::hasColumn('transfers', 'archived_by')) {
                $table->unsignedBigInteger('archived_by')->nullable()->after('return_received_at');
                $table->string('archive_reason')->nullable()->after('archived_by');
                $table->timestamp('archived_at')->nullable()->after('archive_reason');
                
                $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        // Upgrade status column to support new statuses: COMPLETED, AWAITING_RETURN, ARCHIVED, DRAFT
        // Handles database-specific constraints and ENUM conversion
        $this->upgradeTransferStatusColumn();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to convert back to ENUM, just drop the new columns
        Schema::table('transfers', function (Blueprint $table) {
            if (Schema::hasColumn('transfers', 'revision')) {
                $table->dropColumn('revision');
            }

            if (Schema::hasColumn('transfers', 'archived_by')) {
                $table->dropForeign(['archived_by']);
                $table->dropColumn(['archived_by', 'archive_reason', 'archived_at']);
            }
        });
    }

    /**
     * Upgrade the status column to support new lifecycle statuses.
     * Handles ENUM constraints on MySQL and SQLite column recreation.
     */
    private function upgradeTransferStatusColumn(): void
    {
        $driver = DB::getDriverName();
        
        if ($driver === 'mysql') {
            // For MySQL: check if status is an ENUM and convert to VARCHAR
            $table = DB::selectOne("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                                    WHERE TABLE_NAME = 'transfers' AND COLUMN_NAME = 'status'");
            
            if ($table && strpos($table->COLUMN_TYPE, 'enum') !== false) {
                DB::statement("ALTER TABLE `transfers` MODIFY `status` VARCHAR(50) NOT NULL DEFAULT 'PENDING'");
            }
        } elseif ($driver === 'sqlite') {
            // For SQLite: check if status column is constrained and recreate the table
            $hasConstraint = DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='transfers' AND sql LIKE '%CHECK%status%'"
            );
            
            if ($hasConstraint) {
                // SQLite requires recreating the table to remove CHECK constraints
                // Create temporary table with the new schema
                DB::statement("ALTER TABLE transfers RENAME TO transfers_old");
                
                Schema::create('transfers_temp', function (Blueprint $table) {
                    // Copy the structure from the old table
                    $table->bigIncrements('id');
                    $table->unsignedInteger('revision')->default(1);
                    $table->unsignedBigInteger('origin_location_id');
                    $table->unsignedBigInteger('destination_location_id');
                    $table->unsignedBigInteger('created_by');
                    $table->unsignedBigInteger('approved_by')->nullable();
                    $table->unsignedBigInteger('rejected_by')->nullable();
                    $table->unsignedBigInteger('dispatched_by')->nullable();
                    $table->string('status')->default('PENDING'); // No CHECK constraint
                    $table->timestamp('approved_at')->nullable();
                    $table->timestamp('rejected_at')->nullable();
                    $table->timestamp('dispatched_at')->nullable();
                    $table->unsignedBigInteger('received_by')->nullable();
                    $table->timestamp('received_at')->nullable();
                    $table->unsignedBigInteger('archived_by')->nullable();
                    $table->string('archive_reason')->nullable();
                    $table->timestamp('archived_at')->nullable();
                    $table->timestamps();
                });
                
                // Copy data from old table
                DB::statement("INSERT INTO transfers_temp SELECT * FROM transfers_old");
                
                // Drop old table and rename new one
                DB::statement("DROP TABLE transfers_old");
                DB::statement("ALTER TABLE transfers_temp RENAME TO transfers");
            }
        }
    }
};
