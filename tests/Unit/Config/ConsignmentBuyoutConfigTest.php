<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/** The buyout activation switches ship OFF and are driven only by their documented environment names. */
class ConsignmentBuyoutConfigTest extends TestCase
{
    private const VARS = ['CONSIGNMENT_BUYOUTS_UI_ENABLED', 'CONSIGNMENT_BUYOUTS_FINALIZATION_ENABLED'];

    private function load(array $env): array
    {
        foreach (self::VARS as $var) {
            $value = $env[$var] ?? null;
            if ($value === null) {
                putenv($var);
                unset($_ENV[$var], $_SERVER[$var]);
            } else {
                putenv("{$var}={$value}");
                $_ENV[$var] = $_SERVER[$var] = $value;
            }
        }
        try {
            return (require base_path('config/consignment.php'))['buyouts'];
        } finally {
            foreach (self::VARS as $var) {
                putenv($var);
                unset($_ENV[$var], $_SERVER[$var]);
            }
        }
    }

    /** @test */
    public function both_switches_default_to_off(): void
    {
        $this->assertSame(['ui_enabled' => false, 'finalization_enabled' => false], $this->load([]));
        $this->assertFalse(config('consignment.buyouts.ui_enabled'));
        $this->assertFalse(config('consignment.buyouts.finalization_enabled'));
    }

    /** @test */
    public function each_switch_is_driven_by_its_own_environment_name(): void
    {
        $this->assertSame(['ui_enabled' => true, 'finalization_enabled' => false], $this->load(['CONSIGNMENT_BUYOUTS_UI_ENABLED' => 'true']));
        $this->assertSame(['ui_enabled' => false, 'finalization_enabled' => true], $this->load(['CONSIGNMENT_BUYOUTS_FINALIZATION_ENABLED' => 'true']));
        $this->assertSame(['ui_enabled' => false, 'finalization_enabled' => false], $this->load([
            'CONSIGNMENT_BUYOUTS_UI_ENABLED' => 'false', 'CONSIGNMENT_BUYOUTS_FINALIZATION_ENABLED' => 'false']));
    }
}
