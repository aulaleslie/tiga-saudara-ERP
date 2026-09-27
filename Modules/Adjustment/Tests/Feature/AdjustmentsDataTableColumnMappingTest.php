<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use Modules\Adjustment\DataTables\AdjustmentsDataTable;
use Tests\TestCase;

/**
 * Regression coverage: the adjustments list previously bound the
 * `reference` column but labeled it "Catatan", so an adjustment/breakage
 * document number rendered under a "Notes" heading while the actual note
 * field was never shown at all. `reference` and `note` must be separate,
 * correctly labeled columns.
 */
class AdjustmentsDataTableColumnMappingTest extends TestCase
{
    private function getColumns(): array
    {
        $dataTable = new AdjustmentsDataTable();
        $reflection = new \ReflectionMethod($dataTable, 'getColumns');
        $reflection->setAccessible(true);

        return $reflection->invoke($dataTable);
    }

    private function findColumnByField(array $columns, string $field): ?\Yajra\DataTables\Html\Column
    {
        foreach ($columns as $column) {
            if (($column->data ?? null) === $field) {
                return $column;
            }
        }

        return null;
    }

    /** @test */
    public function reference_column_is_labeled_as_a_document_number_not_a_note(): void
    {
        $columns = $this->getColumns();
        $reference = $this->findColumnByField($columns, 'reference');

        $this->assertNotNull($reference, 'Expected a "reference" column to be defined.');
        $this->assertNotSame('Catatan', $reference->title);
    }

    /** @test */
    public function note_column_is_present_and_labeled_catatan(): void
    {
        $columns = $this->getColumns();
        $note = $this->findColumnByField($columns, 'note');

        $this->assertNotNull($note, 'Expected a separate "note" column to be defined.');
        $this->assertSame('Catatan', $note->title);
    }

    /** @test */
    public function reference_and_note_are_distinct_columns(): void
    {
        $columns = $this->getColumns();
        $reference = $this->findColumnByField($columns, 'reference');
        $note = $this->findColumnByField($columns, 'note');

        $this->assertNotSame($reference->data, $note->data);
    }
}
