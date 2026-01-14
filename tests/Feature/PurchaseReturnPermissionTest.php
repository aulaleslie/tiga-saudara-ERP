<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Ticket 1: Gate purchase return create by permission
 *
 * Verifies that only users with 'purchaseReturns.create' permission
 * can access the purchase return creation UI and API.
 */
class PurchaseReturnPermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckUserRoleForSetting::class,
            VerifyCsrfToken::class,
        ]);

        $this->currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'company_name'              => 'Test Company',
            'company_email'             => 'test@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'test@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => '123 Street',
        ]);

        $this->user = User::factory()->create();
    }

    /**
     * Scenario: Access with permission
     * Given a user has the purchase-return-create permission
     * When the user opens the purchase return create page
     * Then the page loads and the create API is accessible
     */
    public function test_user_with_permission_can_access_create_page(): void
    {
        // Mock all Gate methods to allow access
        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();
        Gate::shouldReceive('allows')->andReturnTrue()->zeroOrMoreTimes();
        Gate::shouldReceive('check')->andReturnTrue()->zeroOrMoreTimes();
        Gate::shouldReceive('authorize')->andReturnTrue()->zeroOrMoreTimes();
        Gate::shouldReceive('any')->andReturnTrue()->zeroOrMoreTimes();

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchase-returns.create'));

        $response->assertStatus(200);
    }


    /**
     * Scenario: Access without permission
     * Given a user lacks the purchase-return-create permission
     * When the user opens the purchase return create page
     * Then access is blocked and returns 403
     */
    public function test_user_without_permission_cannot_access_create_page(): void
    {
        Gate::shouldReceive('denies')
            ->with('purchaseReturns.create')
            ->andReturn(true);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('purchase-returns.create'));

        $response->assertStatus(403);
    }

    /**
     * Scenario: API access without permission
     * Given a user lacks the purchase-return-create permission
     * When the user calls the store API
     * Then the API returns 403 with a permission error
     */
    public function test_user_without_permission_cannot_store_purchase_return(): void
    {
        Gate::shouldReceive('denies')
            ->with('purchaseReturns.create')
            ->andReturn(true);

        Gate::shouldReceive('allows')
            ->with('purchaseReturns.create')
            ->andReturn(false);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('purchase-returns.store'), []);

        $response->assertStatus(403);
    }
}
