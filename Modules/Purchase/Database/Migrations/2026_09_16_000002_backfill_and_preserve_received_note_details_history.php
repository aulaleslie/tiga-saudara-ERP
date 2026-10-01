<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Purchase\Services\LegacyTransactionResolver;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Backfill historical receiving-detail snapshots from current relationships
        $details = DB::table('received_note_details')
            ->join('received_notes', 'received_notes.id', '=', 'received_note_details.received_note_id')
            ->leftJoin('purchase_details', 'purchase_details.id', '=', 'received_note_details.po_detail_id')
            ->leftJoin('products', 'products.id', '=', 'purchase_details.product_id')
            ->select([
                'received_note_details.id as rnd_id',
                'received_notes.location_id as rn_location_id',
                'purchase_details.product_id as pd_product_id',
                'purchase_details.product_code as pd_product_code',
                'purchase_details.product_name as pd_product_name',
                'purchase_details.purchase_unit_id as pd_purchase_unit_id',
                'purchase_details.unit_name as pd_unit_name',
                'purchase_details.base_unit_name as pd_base_unit_name',
                'purchase_details.conversion_factor as pd_conversion_factor',
                'purchase_details.entered_quantity as pd_entered_quantity',
                'purchase_details.tax_id as pd_tax_id',
                'products.product_code as p_product_code',
                'products.product_name as p_product_name',
            ])
            ->get();

        foreach ($details as $row) {
            DB::table('received_note_details')
                ->where('id', $row->rnd_id)
                ->update([
                    'product_id' => $row->pd_product_id,
                    'product_code' => $row->pd_product_code ?? $row->p_product_code,
                    'product_name' => $row->pd_product_name ?? $row->p_product_name,
                    'purchase_unit_id' => $row->pd_purchase_unit_id,
                    'unit_name' => $row->pd_unit_name,
                    'base_unit_name' => $row->pd_base_unit_name,
                    'conversion_factor' => $row->pd_conversion_factor ?? 1.000000,
                    'entered_quantity' => $row->pd_entered_quantity,
                    'tax_id' => $row->pd_tax_id,
                    'location_id' => $row->rn_location_id,
                ]);
        }

        // Step 2: Backfill uniquely resolvable BUY transaction provenance where missing
        $unlinkedDetails = \Modules\Purchase\Entities\ReceivedNoteDetail::whereDoesntHave('transaction')
            ->whereHas('receivedNote', function ($q) {
                $q->where('status', \Modules\Purchase\Entities\ReceivedNote::STATUS_APPROVED);
            })
            ->get();

        $resolver = app(LegacyTransactionResolver::class);
        foreach ($unlinkedDetails as $rnd) {
            $resolution = $resolver->resolve($rnd);
            if ($resolution['status'] === LegacyTransactionResolver::RESULT_MATCHED && $resolution['transaction']) {
                $txn = $resolution['transaction'];
                // Only link if the transaction has no other detail attached
                if ($txn->received_note_detail_id === null) {
                    $txn->received_note_detail_id = $rnd->id;
                    $txn->save();
                }
            }
        }

        // Step 3: Replace cascade deletion with a nullable history-preserving Purchase-detail relationship
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF;');

            // In SQLite, copy table to new schema with nullable po_detail_id and nullOnDelete FK
            DB::statement('
                CREATE TABLE received_note_details_temp (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    received_note_id INTEGER NOT NULL,
                    po_detail_id INTEGER DEFAULT NULL,
                    product_id INTEGER DEFAULT NULL,
                    product_code VARCHAR(100) DEFAULT NULL,
                    product_name VARCHAR(255) DEFAULT NULL,
                    purchase_unit_id INTEGER DEFAULT NULL,
                    unit_name VARCHAR(100) DEFAULT NULL,
                    base_unit_name VARCHAR(100) DEFAULT NULL,
                    conversion_factor NUMERIC DEFAULT NULL,
                    entered_quantity NUMERIC DEFAULT NULL,
                    tax_id INTEGER DEFAULT NULL,
                    location_id INTEGER DEFAULT NULL,
                    quantity_received NUMERIC NOT NULL,
                    pending_serial_numbers TEXT DEFAULT NULL,
                    note TEXT DEFAULT NULL,
                    created_at DATETIME DEFAULT NULL,
                    updated_at DATETIME DEFAULT NULL,
                    FOREIGN KEY (received_note_id) REFERENCES received_notes(id) ON DELETE CASCADE,
                    FOREIGN KEY (po_detail_id) REFERENCES purchase_details(id) ON DELETE SET NULL,
                    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
                    FOREIGN KEY (purchase_unit_id) REFERENCES units(id) ON DELETE SET NULL,
                    FOREIGN KEY (tax_id) REFERENCES taxes(id) ON DELETE SET NULL,
                    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
                );
            ');

            DB::statement('
                INSERT INTO received_note_details_temp SELECT
                    id, received_note_id, po_detail_id, product_id, product_code, product_name,
                    purchase_unit_id, unit_name, base_unit_name, conversion_factor, entered_quantity,
                    tax_id, location_id, quantity_received, pending_serial_numbers, note,
                    created_at, updated_at
                FROM received_note_details;
            ');

            DB::statement('DROP TABLE received_note_details;');
            DB::statement('ALTER TABLE received_note_details_temp RENAME TO received_note_details;');

            DB::statement('CREATE INDEX idx_rnd_product_id ON received_note_details (product_id);');
            DB::statement('CREATE INDEX idx_rnd_location_id ON received_note_details (location_id);');

            DB::statement('PRAGMA foreign_keys=ON;');
        } else {
            Schema::table('received_note_details', function (Blueprint $table) {
                $table->dropForeign(['po_detail_id']);
                $table->unsignedBigInteger('po_detail_id')->nullable()->change();
                $table->foreign('po_detail_id')
                    ->references('id')
                    ->on('purchase_details')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF;');

            DB::statement('
                CREATE TABLE received_note_details_temp (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    received_note_id INTEGER NOT NULL,
                    po_detail_id INTEGER NOT NULL,
                    product_id INTEGER DEFAULT NULL,
                    product_code VARCHAR(100) DEFAULT NULL,
                    product_name VARCHAR(255) DEFAULT NULL,
                    purchase_unit_id INTEGER DEFAULT NULL,
                    unit_name VARCHAR(100) DEFAULT NULL,
                    base_unit_name VARCHAR(100) DEFAULT NULL,
                    conversion_factor NUMERIC DEFAULT NULL,
                    entered_quantity NUMERIC DEFAULT NULL,
                    tax_id INTEGER DEFAULT NULL,
                    location_id INTEGER DEFAULT NULL,
                    quantity_received NUMERIC NOT NULL,
                    pending_serial_numbers TEXT DEFAULT NULL,
                    note TEXT DEFAULT NULL,
                    created_at DATETIME DEFAULT NULL,
                    updated_at DATETIME DEFAULT NULL,
                    FOREIGN KEY (received_note_id) REFERENCES received_notes(id) ON DELETE CASCADE,
                    FOREIGN KEY (po_detail_id) REFERENCES purchase_details(id) ON DELETE CASCADE,
                    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
                    FOREIGN KEY (purchase_unit_id) REFERENCES units(id) ON DELETE SET NULL,
                    FOREIGN KEY (tax_id) REFERENCES taxes(id) ON DELETE SET NULL,
                    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
                );
            ');

            DB::statement('
                INSERT INTO received_note_details_temp SELECT
                    id, received_note_id, po_detail_id, product_id, product_code, product_name,
                    purchase_unit_id, unit_name, base_unit_name, conversion_factor, entered_quantity,
                    tax_id, location_id, quantity_received, pending_serial_numbers, note,
                    created_at, updated_at
                FROM received_note_details;
            ');

            DB::statement('DROP TABLE received_note_details;');
            DB::statement('ALTER TABLE received_note_details_temp RENAME TO received_note_details;');

            DB::statement('CREATE INDEX idx_rnd_product_id ON received_note_details (product_id);');
            DB::statement('CREATE INDEX idx_rnd_location_id ON received_note_details (location_id);');

            DB::statement('PRAGMA foreign_keys=ON;');
        } else {
            Schema::table('received_note_details', function (Blueprint $table) {
                $table->dropForeign(['po_detail_id']);
                $table->unsignedBigInteger('po_detail_id')->nullable(false)->change();
                $table->foreign('po_detail_id')
                    ->references('id')
                    ->on('purchase_details')
                    ->onDelete('cascade');
            });
        }
    }
};
