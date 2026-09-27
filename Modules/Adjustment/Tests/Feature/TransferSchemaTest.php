<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransferSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function transfers_table_has_required_foreign_keys()
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // For SQLite, verify foreign keys using PRAGMA
            $foreignKeys = DB::select("PRAGMA foreign_key_list(transfers)");
            $fkMap = [];
            foreach ($foreignKeys as $fk) {
                $fkMap[$fk->from] = [
                    'table' => $fk->table,
                    'column' => $fk->to,
                    'on_delete' => $fk->on_delete,
                ];
            }

            // Verify all user actor foreign keys exist with correct ON DELETE actions
            $this->assertArrayHasKey('created_by', $fkMap, 'created_by foreign key missing');
            $this->assertEquals('users', $fkMap['created_by']['table']);
            $this->assertEquals('id', $fkMap['created_by']['column']);
            $this->assertEquals('RESTRICT', $fkMap['created_by']['on_delete']);

            $this->assertArrayHasKey('approved_by', $fkMap, 'approved_by foreign key missing');
            $this->assertEquals('users', $fkMap['approved_by']['table']);
            $this->assertEquals('SET NULL', $fkMap['approved_by']['on_delete']);

            $this->assertArrayHasKey('rejected_by', $fkMap, 'rejected_by foreign key missing');
            $this->assertEquals('users', $fkMap['rejected_by']['table']);
            $this->assertEquals('SET NULL', $fkMap['rejected_by']['on_delete']);

            $this->assertArrayHasKey('dispatched_by', $fkMap, 'dispatched_by foreign key missing');
            $this->assertEquals('users', $fkMap['dispatched_by']['table']);
            $this->assertEquals('SET NULL', $fkMap['dispatched_by']['on_delete']);

            $this->assertArrayHasKey('received_by', $fkMap, 'received_by foreign key missing');
            $this->assertEquals('users', $fkMap['received_by']['table']);
            $this->assertEquals('SET NULL', $fkMap['received_by']['on_delete']);

            $this->assertArrayHasKey('return_dispatched_by', $fkMap, 'return_dispatched_by foreign key missing');
            $this->assertEquals('users', $fkMap['return_dispatched_by']['table']);
            $this->assertEquals('SET NULL', $fkMap['return_dispatched_by']['on_delete']);

            $this->assertArrayHasKey('return_received_by', $fkMap, 'return_received_by foreign key missing');
            $this->assertEquals('users', $fkMap['return_received_by']['table']);
            $this->assertEquals('SET NULL', $fkMap['return_received_by']['on_delete']);

            $this->assertArrayHasKey('archived_by', $fkMap, 'archived_by foreign key missing');
            $this->assertEquals('users', $fkMap['archived_by']['table']);
            $this->assertEquals('SET NULL', $fkMap['archived_by']['on_delete']);

            // Verify location foreign keys exist with correct ON DELETE actions
            $this->assertArrayHasKey('origin_location_id', $fkMap, 'origin_location_id foreign key missing');
            $this->assertEquals('locations', $fkMap['origin_location_id']['table']);
            $this->assertEquals('id', $fkMap['origin_location_id']['column']);
            $this->assertEquals('RESTRICT', $fkMap['origin_location_id']['on_delete']);

            $this->assertArrayHasKey('destination_location_id', $fkMap, 'destination_location_id foreign key missing');
            $this->assertEquals('locations', $fkMap['destination_location_id']['table']);
            $this->assertEquals('id', $fkMap['destination_location_id']['column']);
            $this->assertEquals('RESTRICT', $fkMap['destination_location_id']['on_delete']);
        } else {
            // For MySQL, use Doctrine schema manager
            $schemaManager = DB::connection()->getDoctrineSchemaManager();
            $table = $schemaManager->listTableDetails('transfers');
            $foreignKeys = $table->getForeignKeys();
            $fkMap = [];
            foreach ($foreignKeys as $fk) {
                $fkMap[$fk->getLocalColumnName()] = $fk;
            }

            // Verify all required foreign keys exist
            $this->assertArrayHasKey('created_by', $fkMap, 'created_by foreign key missing');
            $this->assertArrayHasKey('approved_by', $fkMap, 'approved_by foreign key missing');
            $this->assertArrayHasKey('rejected_by', $fkMap, 'rejected_by foreign key missing');
            $this->assertArrayHasKey('dispatched_by', $fkMap, 'dispatched_by foreign key missing');
            $this->assertArrayHasKey('received_by', $fkMap, 'received_by foreign key missing');
            $this->assertArrayHasKey('return_dispatched_by', $fkMap, 'return_dispatched_by foreign key missing');
            $this->assertArrayHasKey('return_received_by', $fkMap, 'return_received_by foreign key missing');
            $this->assertArrayHasKey('archived_by', $fkMap, 'archived_by foreign key missing');
            $this->assertArrayHasKey('origin_location_id', $fkMap, 'origin_location_id foreign key missing');
            $this->assertArrayHasKey('destination_location_id', $fkMap, 'destination_location_id foreign key missing');
        }
    }

    /** @test */
    public function transfers_table_has_required_columns()
    {
        // Use Schema::getColumnListing() for database-agnostic column checking
        $columnNames = Schema::getColumnListing('transfers');

        // Verify all required columns exist
        $requiredColumns = [
            'id',
            'document_number',
            'revision',
            'origin_location_id',
            'destination_location_id',
            'created_by',
            'approved_by',
            'rejected_by',
            'dispatched_by',
            'received_by',
            'return_dispatched_by',
            'return_received_by',
            'status',
            'approved_at',
            'rejected_at',
            'dispatched_at',
            'received_at',
            'return_dispatched_at',
            'return_received_at',
            'archived_by',
            'archive_reason',
            'archived_at',
            'created_at',
            'updated_at',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $columnNames, "Column {$column} is missing from transfers table");
        }
    }

    /** @test */
    public function transfers_table_has_unique_indexes()
    {
        if (DB::getDriverName() === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list(transfers)");
            $uniqueIndexes = array_filter($indexes, fn($idx) => $idx->unique === 1);
            $uniqueIndexNames = array_map(fn($idx) => $idx->name, $uniqueIndexes);

            // Verify transfers_origin_document_number_unique exists
            $this->assertContains(
                'transfers_origin_document_number_unique',
                $uniqueIndexNames,
                'transfers_origin_document_number_unique index missing'
            );
        }
    }
}
