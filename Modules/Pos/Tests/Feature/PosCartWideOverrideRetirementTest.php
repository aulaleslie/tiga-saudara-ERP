<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Services\PosApprovalRequestService;
use Modules\Pos\Services\PosCartActionAuthorizationService;
use Tests\TestCase;

/**
 * The cart-wide total override and the ambiguous unit-price action are retired.
 *
 * Historical records stay readable and render read-only, but neither action may
 * be created for a new request nor authorize any new operation.
 */
class PosCartWideOverrideRetirementTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    // ------------------------------------------- action type registry

    public function test_retired_actions_are_declared_retired(): void
    {
        $this->assertTrue(PosActionApprovalRequest::isRetiredAction('PRICE_OVERRIDE'));
        $this->assertTrue(PosActionApprovalRequest::isRetiredAction('TOTAL_PRICE_OVERRIDE'));

        $this->assertFalse(PosActionApprovalRequest::isActiveAction('PRICE_OVERRIDE'));
        $this->assertFalse(PosActionApprovalRequest::isActiveAction('TOTAL_PRICE_OVERRIDE'));
    }

    public function test_both_row_overrides_are_active(): void
    {
        $this->assertTrue(PosActionApprovalRequest::isActiveAction('LINE_UNIT_PRICE_OVERRIDE'));
        $this->assertTrue(PosActionApprovalRequest::isActiveAction('LINE_TOTAL_OVERRIDE'));

        $this->assertSame(
            ['LINE_UNIT_PRICE_OVERRIDE', 'LINE_TOTAL_OVERRIDE'],
            PosActionApprovalRequest::ROW_OVERRIDE_ACTIONS
        );
    }

    public function test_legacy_constants_survive_for_historical_reads(): void
    {
        // Removing them would break deserialisation of existing rows.
        $this->assertSame('PRICE_OVERRIDE', PosActionApprovalRequest::ACTION_PRICE_OVERRIDE);
        $this->assertSame('TOTAL_PRICE_OVERRIDE', PosActionApprovalRequest::ACTION_TOTAL_PRICE_OVERRIDE);
    }

    // ------------------------------------------------ request creation

    /**
     * @dataProvider retiredActions
     */
    public function test_a_retired_action_cannot_be_created(string $actionType): void
    {
        $service = app(PosApprovalRequestService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ACTION_RETIRED');

        $service->createRequest(
            settingId: 1,
            sessionId: 1,
            requester: $this->user(),
            actionType: $actionType,
            targetType: 'pos_cart_line',
            targetId: 1,
            payload: ['unit_price' => 1000]
        );
    }

    /**
     * @dataProvider retiredActions
     */
    public function test_a_retired_action_cannot_authorize(string $actionType): void
    {
        $service = app(PosCartActionAuthorizationService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ACTION_RETIRED');

        $service->authorizeWithoutConsuming($this->user(), $actionType, null);
    }

    public static function retiredActions(): array
    {
        return [
            'ambiguous unit price' => ['PRICE_OVERRIDE'],
            'cart-wide total' => ['TOTAL_PRICE_OVERRIDE'],
        ];
    }

    // ------------------------------------------------ retired endpoints

    public function test_the_cart_wide_endpoint_remains_registered_but_non_mutating(): void
    {
        $route = app('router')->getRoutes()->getByName('pos.sell.cart.total-override.store');

        $this->assertNotNull($route, 'The compatibility route should remain so old clients get a clear answer.');
        $this->assertSame('pos/sell/cart/total-override', $route->uri());
    }

    public function test_the_retired_endpoints_return_feature_retired(): void
    {
        $controller = new \ReflectionClass(\Modules\Pos\Http\Controllers\PosCartTotalOverrideController::class);
        $source = file_get_contents($controller->getFileName());

        $this->assertStringContainsString('FEATURE_RETIRED', $source);
        $this->assertStringContainsString('422', $source);
        $this->assertStringNotContainsString('overrideTotalPrice(', $source, 'The retired endpoint must not mutate.');
    }

    public function test_the_ambiguous_unit_price_endpoint_cannot_bypass_the_new_contract(): void
    {
        $controller = new \ReflectionClass(\Modules\Pos\Http\Controllers\PosSellController::class);
        $source = file_get_contents($controller->getFileName());

        $start = strpos($source, 'public function cartOverridePrice(');
        $this->assertNotFalse($start);

        $body = substr($source, $start, 800);

        $this->assertStringContainsString('FEATURE_RETIRED', $body);
        $this->assertStringNotContainsString('$cartService->override', $body, 'The retired endpoint must not mutate.');
    }

    public function test_the_cart_wide_service_method_refuses_to_execute(): void
    {
        $service = app(\Modules\Pos\Services\PosCartService::class);

        $this->expectException(DomainException::class);

        $service->overrideTotalPrice(1, 1, 1, 100000, null, null, $this->user());
    }

    // -------------------------------------------------- permissions

    public function test_the_deprecated_cart_total_permission_is_absent_from_active_bundles(): void
    {
        $matrix = file_get_contents(
            base_path('Modules/Pos/Support/PosPermissionMatrix.php')
        );

        // It may remain defined for migration, but must not be granted.
        $activeGrants = preg_match_all("/'pos\.overrides\.total-price'/", $matrix);

        $this->assertLessThanOrEqual(
            1,
            $activeGrants,
            'The deprecated cart-total permission should not be granted in active bundles.'
        );
    }

    public function test_both_active_actions_map_to_the_same_direct_permission(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(PosCartActionAuthorizationService::class))->getFileName()
        );

        $this->assertMatchesRegularExpression(
            "/ACTION_LINE_UNIT_PRICE_OVERRIDE\s*=>\s*'pos\.overrides\.price'/",
            $source
        );
        $this->assertMatchesRegularExpression(
            "/ACTION_LINE_TOTAL_OVERRIDE\s*=>\s*'pos\.overrides\.price'/",
            $source
        );
    }

    // ------------------------------------------- historical rendering

    public function test_historical_actions_still_render_in_the_approval_queue(): void
    {
        $queue = file_get_contents(
            base_path('Modules/Pos/Resources/views/approval-queue/index.blade.php')
        );

        $this->assertStringContainsString("'PRICE_OVERRIDE'", $queue);
        $this->assertStringContainsString("'TOTAL_PRICE_OVERRIDE'", $queue);
        $this->assertStringContainsString('dipensiunkan', $queue);
    }

    public function test_no_active_cart_wide_ui_remains(): void
    {
        $sell = file_get_contents(base_path('Modules/Pos/Resources/views/sell.blade.php'));

        $this->assertStringNotContainsString('pos-total-override-modal', $sell);
        $this->assertStringNotContainsString('total_price_override_approval', $sell);
        $this->assertFileDoesNotExist(
            base_path('Modules/Pos/Resources/views/sell/modals/total_override.blade.php')
        );
    }
}
