<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Adjustment\Services\TransferRoutePolicyResolver;
use Modules\Setting\Entities\Tax;
use RuntimeException;
use Tests\TestCase;

class TransferRoutePolicyResolverTest extends TestCase
{
    use RefreshDatabase;

    protected TransferRoutePolicyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(TransferRoutePolicyResolver::class);
    }

    public function test_same_business_resolves_preserve_with_no_return(): void
    {
        $decision = $this->resolver->resolveClassification(true, false, true);

        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_PRESERVE, $decision['classification']);
        $this->assertFalse($decision['mandatory_return']);
    }

    public function test_cross_business_non_pkp_to_non_pkp_resolves_non_tax_with_no_return(): void
    {
        $decision = $this->resolver->resolveClassification(false, false, false);

        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_NON_TAX, $decision['classification']);
        $this->assertFalse($decision['mandatory_return']);
    }

    public function test_cross_business_pkp_to_non_pkp_resolves_non_tax_with_mandatory_return(): void
    {
        $decision = $this->resolver->resolveClassification(false, true, false);

        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_NON_TAX, $decision['classification']);
        $this->assertTrue($decision['mandatory_return']);
    }

    public function test_cross_business_non_pkp_to_pkp_resolves_tax_with_mandatory_return(): void
    {
        $decision = $this->resolver->resolveClassification(false, false, true);

        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_TAX, $decision['classification']);
        $this->assertTrue($decision['mandatory_return']);
    }

    public function test_cross_business_pkp_to_pkp_resolves_tax_with_mandatory_return(): void
    {
        $decision = $this->resolver->resolveClassification(false, true, true);

        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_TAX, $decision['classification']);
        $this->assertTrue($decision['mandatory_return']);
    }

    public function test_resolves_configured_default_tax(): void
    {
        Tax::create(['name' => 'Non-Default', 'value' => 5, 'is_active' => true, 'is_default' => false]);
        $default = Tax::create(['name' => 'Default Tax', 'value' => 11, 'is_active' => true, 'is_default' => true]);

        $result = $this->resolver->resolveDestinationTax();

        $this->assertEquals($default->id, $result['tax_id']);
        $this->assertEquals(TransferRoutePolicy::PROVENANCE_DEFAULT, $result['provenance']);
    }

    public function test_falls_back_to_lowest_id_applicable_tax_when_no_default(): void
    {
        $first = Tax::create(['name' => 'Tax A', 'value' => 5, 'is_active' => true, 'is_default' => false]);
        Tax::create(['name' => 'Tax B', 'value' => 11, 'is_active' => true, 'is_default' => false]);

        $result = $this->resolver->resolveDestinationTax();

        $this->assertEquals($first->id, $result['tax_id']);
        $this->assertEquals(TransferRoutePolicy::PROVENANCE_FALLBACK, $result['provenance']);
    }

    public function test_throws_when_no_applicable_tax_exists(): void
    {
        Tax::create(['name' => 'Inactive Tax', 'value' => 11, 'is_active' => false, 'is_default' => false]);

        $this->expectException(RuntimeException::class);
        $this->resolver->resolveDestinationTax();
    }

    public function test_snapshots_immutable_id_name_and_rate(): void
    {
        $tax = Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_active' => true, 'is_default' => true]);

        $result = $this->resolver->resolveDestinationTax();

        $this->assertEquals($tax->id, $result['tax_id']);
        $this->assertEquals('PPN 11%', $result['tax_name']);
        $this->assertEquals('11', $result['tax_rate']);

        // Mutating the tax afterward must not retroactively change a previously resolved snapshot
        $tax->update(['name' => 'Renamed Tax', 'value' => 20]);

        $this->assertEquals('PPN 11%', $result['tax_name']);
        $this->assertEquals('11', $result['tax_rate']);
    }
}
