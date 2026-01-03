<?php

namespace Tests\Unit;

use Modules\Sale\Jobs\StageSalesImportRows;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Sales Import chunking functionality.
 * These tests don't require database access.
 */
class SalesImportChunkTest extends TestCase
{
    /**
     * Test chunk size constant is within reasonable range.
     */
    public function test_chunk_size_is_reasonable(): void
    {
        $reflection = new \ReflectionClass(StageSalesImportRows::class);
        $chunkSize = $reflection->getConstant('CHUNK_SIZE');

        $this->assertGreaterThanOrEqual(100, $chunkSize, 'Chunk size should be at least 100 for efficiency');
        $this->assertLessThanOrEqual(1000, $chunkSize, 'Chunk size should not exceed 1000 to avoid memory issues');
    }

    /**
     * Test job timeout is sufficient for large files.
     */
    public function test_staging_job_has_sufficient_timeout(): void
    {
        $job = new StageSalesImportRows(1, [], [], ',');

        $this->assertGreaterThanOrEqual(300, $job->timeout, 'Timeout should be at least 5 minutes for large files');
    }

    /**
     * Test job retries are configured.
     */
    public function test_staging_job_has_retries(): void
    {
        $job = new StageSalesImportRows(1, [], [], ',');

        $this->assertGreaterThanOrEqual(1, $job->tries, 'Job should have at least 1 retry');
    }
}
