<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Purchase\Entities\PaymentTerm;

class PosPaymentTermSearchTest extends PosTransactionFeatureTestCase
{
    use RefreshDatabase;

    private $setting;
    private $cashier;
    private $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->setting = $this->createSetting('Test Company');
        $this->cashier = $this->createUserForSetting($this->setting, 'Cashier', [
            'pos.access',
            'pos.sell',
            'pos.checkout.payment',
        ]);
        
        [$this->terminal, $location] = $this->createTerminalWithLocation($this->setting);
        $session = $this->openSession($this->setting, $this->terminal, $this->cashier);

        $this->actingAsInSetting($this->cashier, $this->setting);
        // We need to inject the session into request attributes for pos sell controller.
        // Or we can just let middleware do it if we visit the route.
        // Actually, the route uses middleware pos.checkout, which checks session.
        // We need to ensure the pos_active_session attribute is set or the user has an open session.
        // The middleware `pos.session.resolve` probably resolves it.
    }

    public function test_it_returns_payment_terms_successfully(): void
    {
        PaymentTerm::query()->delete();

        PaymentTerm::create([
            'name' => 'Net 15',
            'longevity' => 15,
        ]);
        PaymentTerm::create([
            'name' => 'Net 30',
            'longevity' => 30,
        ]);

        $response = $this->getJson('/pos/sell/payment-terms/search');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'terms' => [
                '*' => ['id', 'name', 'longevity']
            ]
        ]);
        
        $terms = $response->json('terms');
        // Let's sort to match order since it orders by longevity asc
        $this->assertCount(2, $terms);
        $this->assertEquals('NET 15', $terms[0]['name']);
        $this->assertEquals(15, $terms[0]['longevity']);
        $this->assertEquals('NET 30', $terms[1]['name']);
        $this->assertEquals(30, $terms[1]['longevity']);
    }

    public function test_it_returns_empty_table_successfully(): void
    {
        PaymentTerm::query()->delete();

        $response = $this->getJson('/pos/sell/payment-terms/search');

        $response->assertStatus(200);
        $response->assertExactJson([
            'terms' => []
        ]);
    }

    public function test_it_rejects_unauthorized_checkout_users(): void
    {
        auth()->logout();

        $response = $this->getJson('/pos/sell/payment-terms/search');

        $response->assertStatus(401);
    }
}

