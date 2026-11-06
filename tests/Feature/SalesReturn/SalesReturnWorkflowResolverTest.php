<?php

namespace Tests\Feature\SalesReturn;

use App\Support\SalesReturn\SalesReturnWorkflowException;
use App\Support\SalesReturn\SalesReturnWorkflowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReturnWorkflowResolverTest extends TestCase
{
    use RefreshDatabase, BuildsWorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableForeignKeys();
        $this->resolver = app(SalesReturnWorkflowResolver::class);
    }

    protected SalesReturnWorkflowResolver $resolver;

    /** @test */
    public function ordinary_sale_is_standard(): void
    {
        $sale = $this->makeSale();
        $this->makeReturn($sale);

        $this->assertSame('standard', $this->resolver->classifySale($sale->id));
        $this->resolver->assertSaleAllowsStandardReturn($sale->id);
    }

    /** @test */
    public function legacy_direct_checkout_link_is_pos(): void
    {
        $sale = $this->makeSale();
        $this->linkLegacyCheckout($sale);

        $this->assertSame('pos', $this->resolver->classifySale($sale->id));
    }

    /** @test */
    public function split_checkout_sale_link_is_pos(): void
    {
        $sale = $this->makeSale();
        $this->linkSplitCheckoutSale($sale);

        $this->assertSame('pos', $this->resolver->classifySale($sale->id));
    }

    /** @test */
    public function linked_pos_return_marks_the_sale_and_return_as_pos_without_checkout_links(): void
    {
        $sale = $this->makeSale();
        $return = $this->makeReturn($sale, ['pos_return_id' => 99]);

        $this->assertSame('pos', $this->resolver->classifySale($sale->id));
        $this->assertSame('pos', $this->resolver->classifyReturn($return));
    }

    /** @test */
    public function pos_origin_sale_with_standard_returns_is_mixed_and_blocked_everywhere(): void
    {
        $sale = $this->makeSale();
        $this->linkSplitCheckoutSale($sale);
        $standard = $this->makeReturn($sale);
        $before = $standard->fresh()->toArray();

        $this->assertSame('mixed', $this->resolver->classifySale($sale->id));
        $this->assertSame('mixed', $this->resolver->classifyReturn($standard));

        try {
            $this->resolver->assertStandardMutable($standard);
            $this->fail('Mixed history must block new standard actions.');
        } catch (SalesReturnWorkflowException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        // Classification and rejection never touch historical evidence.
        $this->assertSame($before, $standard->fresh()->toArray());
    }

    /** @test */
    public function standard_mutation_of_a_pos_linked_return_is_rejected_with_pos_guidance(): void
    {
        $sale = $this->makeSale();
        $return = $this->makeReturn($sale, ['pos_return_id' => 5]);

        $this->expectException(SalesReturnWorkflowException::class);
        $this->expectExceptionMessage('/pos/returns');
        $this->resolver->assertStandardMutable($return);
    }
}
