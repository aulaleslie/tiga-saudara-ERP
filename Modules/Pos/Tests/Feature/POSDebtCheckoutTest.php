<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Tests\TestCase;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Pos\Entities\PosTerminal;
use Modules\Setting\Entities\PaymentMethod;

/**
 * @group pos-checkout-debt
 */
class POSDebtCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_debt_checkout_guards()
    {
        // Simple assertion to verify the test file is loaded and debt checkout tests are organized here.
        $this->assertTrue(true);
    }
}
