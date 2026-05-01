<?php

namespace Modules\Pos\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Services\PosReturnLookupService;

class POSReturnLookupTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $lookupService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->lookupService = app(PosReturnLookupService::class);
    }

    /** @test */
    public function it_can_lookup_transaction_by_code()
    {
        // TODO: Seed POS transaction and checkout
        // $this->markTestIncomplete('Seeding POS data required');
    }

    /** @test */
    public function it_blocks_lookup_of_non_existent_transaction()
    {
        $result = $this->lookupService->lookup('INVALID-CODE');
        $this->assertNull($result);
    }
}
