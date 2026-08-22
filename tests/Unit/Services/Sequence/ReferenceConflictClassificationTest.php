<?php

namespace Tests\Unit\Services\Sequence;

use App\Services\Sequence\DocumentSequenceAllocator;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

class ReferenceConflictClassificationTest extends TestCase
{
    private DocumentSequenceAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new DocumentSequenceAllocator();
    }

    private function queryException(string $message, string $sqlState, int $driverCode): QueryException
    {
        $previous = new PDOException($message);
        $previous->errorInfo = [$sqlState, $driverCode, $message];

        return new QueryException('mysql_test', 'insert into `purchases` ...', [], $previous);
    }

    public function test_recognizes_mysql_purchase_reference_constraint(): void
    {
        $e = $this->queryException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '5-PD-BL-PR-2026-08-00001' for key 'purchases_setting_reference_unique'",
            '23000',
            1062
        );

        $this->assertTrue($this->allocator->isUniqueReferenceConflict($e));
    }

    public function test_recognizes_mysql_sale_reference_constraint(): void
    {
        $e = $this->queryException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '5-PD-BL-SL-2026-08-00001' for key 'sales_setting_reference_unique'",
            '23000',
            1062
        );

        $this->assertTrue($this->allocator->isUniqueReferenceConflict($e));
    }

    public function test_rejects_unrelated_mysql_purchase_unique_constraint(): void
    {
        // e.g. supplier_purchase_number or tax_ref_no unique violation
        $e = $this->queryException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '5-INV-001' for key 'purchases_setting_id_supplier_purchase_number_unique'",
            '23000',
            1062
        );

        $this->assertFalse($this->allocator->isUniqueReferenceConflict($e));
    }

    public function test_rejects_unrelated_mysql_sale_unique_constraint(): void
    {
        $e = $this->queryException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '5-IDEMP-1' for key 'sales_idempotency_key_unique'",
            '23000',
            1062
        );

        $this->assertFalse($this->allocator->isUniqueReferenceConflict($e));
    }

    public function test_rejects_generic_23000_1062_without_exact_index_name(): void
    {
        $e = $this->queryException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '5' for key 'some_other_unique'",
            '23000',
            1062
        );

        $this->assertFalse($this->allocator->isUniqueReferenceConflict($e));
    }

    public function test_recognizes_sqlite_purchase_reference_constraint(): void
    {
        $e = $this->queryException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: purchases.setting_id, purchases.reference',
            '23000',
            19
        );

        $this->assertTrue($this->allocator->isUniqueReferenceConflict($e));
    }

    public function test_recognizes_sqlite_sale_reference_constraint(): void
    {
        $e = $this->queryException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: sales.setting_id, sales.reference',
            '23000',
            19
        );

        $this->assertTrue($this->allocator->isUniqueReferenceConflict($e));
    }

    public function test_rejects_sqlite_unrelated_unique_constraint(): void
    {
        $e = $this->queryException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: purchases.setting_id, purchases.tax_ref_no',
            '23000',
            19
        );

        $this->assertFalse($this->allocator->isUniqueReferenceConflict($e));
    }

    public function test_deadlocks_remain_classified_separately_and_never_as_a_reference_collision(): void
    {
        $e = $this->queryException(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction',
            '40001',
            1213
        );

        $this->assertTrue($this->allocator->isDeadlockConflict($e));
        $this->assertFalse($this->allocator->isUniqueReferenceConflict($e));
    }

    public function test_non_query_exception_is_never_a_reference_collision(): void
    {
        $this->assertFalse($this->allocator->isUniqueReferenceConflict(new \RuntimeException('unrelated failure')));
    }
}
