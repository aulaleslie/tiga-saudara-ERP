<?php

namespace Modules\Pos\Tests\Unit;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosTerminalRuntimeResolver;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Tests\TestCase;

class PosTerminalRuntimeResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);
    }

    public function test_resolve_for_session_open_returns_active_terminal_with_policy(): void
    {
        $setting = $this->createSetting('BIZ A');
        $location = Location::create([
            'name' => 'LOC-A',
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'COUNTER-01',
            'name' => 'Kasir Utama',
            'location_id' => $location->id,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 10000,
            'cash_threshold' => 500000,
            'auto_open_drawer_on_session_open' => false,
            'auto_open_drawer_on_cash_sale' => false,
            'auto_open_drawer_on_pickup' => false,
            'auto_open_drawer_on_close' => false,
            'require_pickup_supervisor_approval' => true,
        ]);

        $resolved = app(PosTerminalRuntimeResolver::class)
            ->resolveForSessionOpen($setting->id, $terminal->id);

        $this->assertSame($terminal->id, $resolved->id);
        $this->assertTrue($resolved->relationLoaded('policy'));
        $this->assertSame(10000.0, (float) $resolved->policy->close_variance_approval_threshold);
    }

    public function test_resolve_for_session_open_throws_for_inactive_terminal(): void
    {
        $setting = $this->createSetting('BIZ A');
        $location = Location::create([
            'name' => 'LOC-A',
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'COUNTER-01',
            'name' => 'Kasir Utama',
            'location_id' => $location->id,
            'is_active' => false,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('inactive');

        app(PosTerminalRuntimeResolver::class)
            ->resolveForSessionOpen($setting->id, $terminal->id);
    }

    public function test_assert_location_allowed_for_setting_throws_when_not_allowed(): void
    {
        $settingA = $this->createSetting('BIZ A');
        $settingB = $this->createSetting('BIZ B');

        $locationB = Location::create([
            'name' => 'LOC-B',
            'setting_id' => $settingB->id,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not allowed');

        app(PosTerminalRuntimeResolver::class)
            ->assertLocationAllowedForSetting($settingA->id, $locationB->id);
    }

    public function test_resolve_policy_returns_policy_for_terminal_in_setting_scope(): void
    {
        $setting = $this->createSetting('BIZ A');
        $location = Location::create([
            'name' => 'LOC-A',
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'COUNTER-01',
            'name' => 'Kasir Utama',
            'location_id' => $location->id,
            'is_active' => false,
        ]);

        $policy = PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => false,
            'close_variance_approval_threshold' => 25000,
            'cash_threshold' => null,
            'auto_open_drawer_on_session_open' => true,
            'auto_open_drawer_on_cash_sale' => true,
            'auto_open_drawer_on_pickup' => false,
            'auto_open_drawer_on_close' => false,
            'require_pickup_supervisor_approval' => true,
        ]);

        $resolvedPolicy = app(PosTerminalRuntimeResolver::class)
            ->resolvePolicy($setting->id, $terminal->id);

        $this->assertSame($policy->id, $resolvedPolicy->id);
        $this->assertFalse($resolvedPolicy->allow_total_only_float_input);
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
        ]);
    }
}
