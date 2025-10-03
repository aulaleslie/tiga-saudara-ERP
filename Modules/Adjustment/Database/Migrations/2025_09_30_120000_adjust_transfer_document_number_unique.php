<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transfers', 'document_number')) {
            return;
        }

        if ($this->indexExists('transfers', 'transfers_document_number_unique')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->dropUnique('transfers_document_number_unique');
            });
        }

        $this->deduplicatePerOriginAndPeriod();

        if (! $this->indexExists('transfers', 'transfers_origin_document_number_unique')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->unique(['origin_location_id', 'document_number'], 'transfers_origin_document_number_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('transfers', 'document_number')) {
            return;
        }

        if ($this->indexExists('transfers', 'transfers_origin_document_number_unique')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->dropUnique('transfers_origin_document_number_unique');
            });
        }

        if (! $this->indexExists('transfers', 'transfers_document_number_unique')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->unique('document_number');
            });
        }
    }

    private function deduplicatePerOriginAndPeriod(): void
    {
        $state = [];

        DB::table('transfers')
            ->select('id', 'origin_location_id', 'document_number')
            ->whereNotNull('document_number')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$state) {
                foreach ($rows as $row) {
                    $documentNumber = $row->document_number;

                    if (! preg_match('/^(TS-\d{4}-\d{2}-)(\d{4})$/', $documentNumber, $matches)) {
                        continue;
                    }

                    $originKey = $row->origin_location_id ?? 'null';
                    $prefix    = $matches[1];
                    $sequence  = (int) $matches[2];
                    $stateKey  = $originKey . '::' . $prefix;

                    if (! array_key_exists($stateKey, $state)) {
                        $state[$stateKey] = [
                            'assigned' => [],
                            'max'      => 0,
                        ];
                    }

                    if (! in_array($sequence, $state[$stateKey]['assigned'], true)) {
                        $state[$stateKey]['assigned'][] = $sequence;
                        $state[$stateKey]['max']        = max($state[$stateKey]['max'], $sequence);
                        continue;
                    }

                    $next = $state[$stateKey]['max'];

                    do {
                        $next++;
                    } while (in_array($next, $state[$stateKey]['assigned'], true));

                    $state[$stateKey]['assigned'][] = $next;
                    $state[$stateKey]['max']        = max($state[$stateKey]['max'], $next);

                    $nextDocumentNumber = $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);

                    DB::table('transfers')
                        ->where('id', $row->id)
                        ->update(['document_number' => $nextDocumentNumber]);
                }
            }, 'id');
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $database   = $connection->getDatabaseName();
        $prefix     = $connection->getTablePrefix();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $prefix . $table)
            ->where('index_name', $index)
            ->exists();
    }
};
