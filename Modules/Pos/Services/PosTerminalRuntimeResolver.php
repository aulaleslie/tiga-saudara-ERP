<?php

namespace Modules\Pos\Services;

use DomainException;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Setting\Entities\SettingSaleLocation;

class PosTerminalRuntimeResolver
{
    public function resolveForSessionOpen(int $settingId, int $terminalId): PosTerminal
    {
        $terminal = PosTerminal::query()
            ->with('policy')
            ->where('setting_id', $settingId)
            ->find($terminalId);

        if (! $terminal) {
            throw new DomainException('POS terminal not found for current setting.');
        }

        $this->assertLocationAllowedForSetting($settingId, (int) $terminal->location_id);

        if (! $terminal->is_active) {
            throw new DomainException('POS terminal is inactive.');
        }

        if (! $terminal->policy) {
            throw new DomainException('POS terminal policy is missing.');
        }

        return $terminal;
    }

    public function resolvePolicy(int $settingId, int $terminalId): PosTerminalPolicy
    {
        $terminal = PosTerminal::query()
            ->with('policy')
            ->where('setting_id', $settingId)
            ->find($terminalId);

        if (! $terminal) {
            throw new DomainException('POS terminal not found for current setting.');
        }

        $this->assertLocationAllowedForSetting($settingId, (int) $terminal->location_id);

        if (! $terminal->policy) {
            throw new DomainException('POS terminal policy is missing.');
        }

        return $terminal->policy;
    }

    public function assertLocationAllowedForSetting(int $settingId, int $locationId): void
    {
        $allowed = SettingSaleLocation::query()
            ->where('setting_id', $settingId)
            ->where('location_id', $locationId)
            ->exists();

        if (! $allowed) {
            throw new DomainException('Terminal location is not allowed for current setting.');
        }
    }
}
