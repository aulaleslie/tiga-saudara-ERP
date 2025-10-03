<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Adjustment\Entities\Transfer;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class TransferStockGuardTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private User $user;

    private array $origin;

    private array $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckUserRoleForSetting::class,
            VerifyCsrfToken::class,
        ]);

        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();

        $this->currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->user = User::factory()->create();

        $this->origin = $this->createSettingWithLocation('Origin', 'origin@example.com');
        $this->destination = $this->createSettingWithLocation('Destination', 'destination@example.com');
    }

    public function test_dispatch_shipment_blocks_non_origin_tenant(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin['location']->id,
            'destination_location_id' => $this->destination['location']->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'created_by'              => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->destination['setting']->id])
            ->post(route('transfers.dispatch', $transfer));

        $response->assertRedirect(route('transfers.show', $transfer->id));
        $this->assertSame(Transfer::STATUS_APPROVED, $transfer->fresh()->status);
    }

    public function test_receive_blocks_non_destination_tenant(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin['location']->id,
            'destination_location_id' => $this->destination['location']->id,
            'status'                  => Transfer::STATUS_DISPATCHED,
            'created_by'              => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->origin['setting']->id])
            ->post(route('transfers.receive', $transfer));

        $response->assertRedirect(route('transfers.show', $transfer->id));
        $this->assertSame(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);
    }

    public function test_dispatch_return_blocks_non_destination_tenant(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin['location']->id,
            'destination_location_id' => $this->destination['location']->id,
            'status'                  => Transfer::STATUS_RECEIVED,
            'created_by'              => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->origin['setting']->id])
            ->post(route('transfers.return-dispatch', $transfer));

        $response->assertRedirect(route('transfers.show', $transfer->id));
        $this->assertSame(Transfer::STATUS_RECEIVED, $transfer->fresh()->status);
    }

    public function test_receive_return_blocks_non_origin_tenant(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin['location']->id,
            'destination_location_id' => $this->destination['location']->id,
            'status'                  => Transfer::STATUS_RETURN_DISPATCHED,
            'created_by'              => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->destination['setting']->id])
            ->post(route('transfers.return-receive', $transfer));

        $response->assertRedirect(route('transfers.show', $transfer->id));
        $this->assertSame(Transfer::STATUS_RETURN_DISPATCHED, $transfer->fresh()->status);
    }

    private function createSettingWithLocation(string $name, string $email): array
    {
        $setting = Setting::create([
            'company_name'             => $name . ' Company',
            'company_email'            => $email,
            'company_phone'            => '1234567890',
            'default_currency_id'      => $this->currency->id,
            'default_currency_position'=> 'prefix',
            'notification_email'       => $email,
            'footer_text'              => 'Footer text',
            'company_address'          => '123 Street',
        ]);

        $location = Location::create([
            'setting_id' => $setting->id,
            'name'       => $name . ' Location',
        ]);

        return [
            'setting'  => $setting,
            'location' => $location,
        ];
    }
}
