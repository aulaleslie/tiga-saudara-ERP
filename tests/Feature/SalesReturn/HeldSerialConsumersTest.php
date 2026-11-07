<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\Adjustment\AdjustmentProductTable;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use App\Support\SalesReturn\HeldSerialException;
use App\Support\SalesReturn\HeldSerialGuard;
use App\Support\SalesReturn\StandardReturnCustodyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Services\BreakageSerialPolicy;
use Modules\Pos\Services\PosReturnReplacementGuard;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Services\ReceivingSerialNumberValidationService;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnCustody;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Modules\Setting\Entities\Location;
use Tests\TestCase;

/**
 * 4.6: a serial held in standard-return custody must be unusable by every consumer, including the
 * ones that bypass the common availability scopes. (POS cart selection: POSSerialIncrementalAssignmentTest.)
 */
class HeldSerialConsumersTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow;

    private array $flow;
    private ProductSerialNumber $held;
    private ProductSerialNumber $free;
    private SaleReturn $return;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        Gate::before(fn ($user, $ability) => $ability === 'saleReturns.inspect' ? null : true);
        $this->actingAs(User::factory()->create());
        $this->app->instance(DocumentNotificationService::class, \Mockery::mock(DocumentNotificationService::class)->shouldIgnoreMissing());

        $this->flow = $this->makeDispatchedSale(2, true);
        $this->return = $this->makeReturnFor($this->flow, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], [$this->flow['serials'][0]->id]);
        $this->withSession(['setting_id' => 1])->post(route('sale-returns.receive', $this->return))->assertRedirect();

        $this->held = $this->flow['serials'][0]->fresh();
        $this->free = $this->flow['serials'][1]->fresh();   // stays SOLD to the customer (not part of the return)
    }

    /** @test */
    public function the_serial_is_held_before_any_consumer_is_tried(): void
    {
        $this->assertTrue(HeldSerialGuard::isHeld($this->held));
        $this->assertFalse(HeldSerialGuard::isHeld($this->free));
        $this->assertTrue(HeldSerialGuard::anyHeld([$this->free->id, $this->held->id]));
        $this->assertFalse(HeldSerialGuard::anyHeld([$this->free->id]));
        $this->assertFalse(HeldSerialGuard::anyHeld([]));
    }

    /** @test sales, transfers (isSellable / isAvailableBroken) and the sale dispatch step */
    public function sales_and_transfer_eligibility_rejects_a_held_serial(): void
    {
        $this->assertFalse($this->held->isSellable());
        $this->assertFalse($this->held->isAvailableBroken());
        $this->assertSame(0, ProductSerialNumber::query()->sellable()->whereKey($this->held->id)->count());
        $this->assertSame(0, ProductSerialNumber::query()->available()->whereKey($this->held->id)->count());
        $this->assertSame(0, ProductSerialNumber::query()->availableBroken()->whereKey($this->held->id)->count());
    }

    /** @test POS replacement */
    public function pos_replacement_cannot_use_a_held_serial(): void
    {
        $this->expectException(ValidationException::class);
        app(PosReturnReplacementGuard::class)->validateReplacementSerial($this->flow['product']->id, $this->held->id);
    }

    /** @test purchase serial reuse */
    public function purchase_receiving_rejects_reusing_a_held_serial(): void
    {
        $result = app(ReceivingSerialNumberValidationService::class)
            ->validateForReceiving($this->flow['product']->id, $this->held->serial_number);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('proses retur', $result['message']);
    }

    /** @test breakage / adjustment scanning */
    public function breakage_scan_rejects_a_held_serial(): void
    {
        $location = Location::find(1);
        $this->assertFalse(app(BreakageSerialPolicy::class)->isEligibleForScan($this->held, $this->flow['product'], $location, false));
    }

    /** @test adjustments, which accept serial ids from the client */
    public function stock_adjustment_rejects_a_held_serial_id(): void
    {
        $component = new AdjustmentProductTable();
        $component->products = [0 => [
            'id' => $this->flow['product']->id, 'serial_number_required' => true, 'serial_numbers' => [],
            'product_name' => 'x', 'product_code' => 'x',
        ]];
        $component->serialNumberErrors = [];

        $component->serialNumberSelected(0, ['id' => $this->held->id, 'serial_number' => $this->held->serial_number]);

        $this->assertArrayHasKey(0, $component->serialNumberErrors);
        $this->assertStringContainsString('custody', $component->serialNumberErrors[0]);
        $this->assertSame([], $component->products[0]['serial_numbers']);
    }

    /** @test Eloquent paths that bypass the scopes */
    public function eloquent_changes_to_a_held_serial_are_refused_until_it_is_released(): void
    {
        foreach ([
            ['status' => ProductSerialNumber::STATUS_ACTIVE],
            ['is_in_return_process' => false],
            ['dispatch_detail_id' => null],
            ['location_id' => 99],
            ['is_broken' => true],
            ['serial_number' => 'RENAMED'],
        ] as $change) {
            $serial = ProductSerialNumber::find($this->held->id);
            try {
                $serial->update($change);
                $this->fail('A held serial must not be changed: ' . json_encode($change));
            } catch (HeldSerialException $e) {
                $this->assertStringContainsString('ditahan', $e->getMessage());
            }
        }

        try {
            ProductSerialNumber::find($this->held->id)->delete();
            $this->fail('A held serial must not be deleted.');
        } catch (HeldSerialException $e) {
            $this->addToAssertionCount(1);
        }

        $stillHeld = $this->held->fresh();
        $this->assertSame(ProductSerialNumber::STATUS_RETURN_IN_PROCESS, $stillHeld->status);
        $this->assertTrue((bool) $stillHeld->is_in_return_process);

        // Unrelated serials and unrelated columns are unaffected.
        $this->free->update(['location_id' => 1]);
        $this->assertSame(1, (int) $this->free->fresh()->location_id);

        // After an authorized release the same change is allowed.
        Gate::define('saleReturns.inspect', fn () => true);
        $settlement = $this->settlementFor($this->return, Settlement::STATUS_APPROVED, 'CASH_REFUND', $this->held->id);
        $custody = SaleReturnCustody::query()->where('sale_return_id', $this->return->id)->sole();
        app(StandardReturnCustodyService::class)->release($this->return, [['custody_id' => $custody->id]], 'lepas');

        $released = ProductSerialNumber::find($this->held->id);
        $released->update(['location_id' => 1]);
        $this->assertTrue($released->fresh()->isSellable());
        $this->assertFalse(HeldSerialGuard::isHeld($released));
    }
}
