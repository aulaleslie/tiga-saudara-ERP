<?php

namespace Tests\Feature\SalesReturn;

use App\Support\SalesReturn\SerialRegistration\HistoricalFulfillmentClassifier;
use App\Support\SalesReturn\SerialRegistration\PendingSerialValidator;
use App\Support\SalesReturn\SerialRegistration\SourceClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SerialRegistrationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_evidence_is_quantity_only_and_allows_registration(): void
    {
        $c = (new HistoricalFulfillmentClassifier)->classify('dispatch_detail:1', 1, [], false, [], 3);

        $this->assertSame(SourceClassification::QUANTITY_ONLY, $c->type);
        $this->assertTrue($c->allowsUnknownSerialRegistration());
    }

    public function test_unresolved_serial_ids_are_inconsistent(): void
    {
        $c = (new HistoricalFulfillmentClassifier)->classify('dispatch_detail:1', 1, [999999], false);

        $this->assertTrue($c->isInconsistent());
        $this->assertFalse($c->allowsUnknownSerialRegistration());
    }

    public function test_serialized_indicator_without_serials_is_inconsistent(): void
    {
        $c = (new HistoricalFulfillmentClassifier)->classify('pos_line:1', 1, [], true);

        $this->assertTrue($c->isInconsistent());
    }

    public function test_return_created_serials_are_excluded_from_original_evidence(): void
    {
        $c = (new HistoricalFulfillmentClassifier)->classify('dispatch_detail:1', 1, [999999], false, [999999]);

        $this->assertSame(SourceClassification::QUANTITY_ONLY, $c->type);
    }

    public function test_fingerprint_ignores_return_registrations_but_detects_source_changes(): void
    {
        $k = new HistoricalFulfillmentClassifier;
        $a = $k->classify('s:1', 1, [], false, [], 2)->fingerprint;
        $b = $k->classify('s:1', 1, [55], false, [55], 2)->fingerprint;
        $c = $k->classify('s:1', 1, [], false, [], 3)->fingerprint;

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function test_validator_flags_empty_oversized_and_normalized_duplicates(): void
    {
        $errors = (new PendingSerialValidator)->validate([
            ['product_id' => 1, 'serial' => '  ', 'key' => 'a'],
            ['product_id' => 1, 'serial' => str_repeat('x', 256), 'key' => 'b'],
            ['product_id' => 1, 'serial' => ' abc ', 'key' => 'c'],
            ['product_id' => 1, 'serial' => 'ABC', 'key' => 'd'],
            ['product_id' => 2, 'serial' => 'ABC', 'key' => 'e'],
        ], false);

        $this->assertSame([
            'a' => PendingSerialValidator::ERROR_EMPTY,
            'b' => PendingSerialValidator::ERROR_TOO_LONG,
            'd' => PendingSerialValidator::ERROR_DUPLICATE,
        ], $errors);
    }

    public function test_registration_table_exists_with_claim_uniqueness(): void
    {
        $this->assertTrue(Schema::hasTable('return_serial_registrations'));
        $this->assertTrue(Schema::hasColumns('return_serial_registrations', ['claim_uuid', 'registered_serial_id', 'unit_attribution_key']));
    }
}
