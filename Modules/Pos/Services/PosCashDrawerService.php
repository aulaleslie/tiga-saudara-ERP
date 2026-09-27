<?php

namespace Modules\Pos\Services;

use Illuminate\Support\Facades\Log;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Services\Contracts\PosCashDrawerAdapter;
use Throwable;

class PosCashDrawerService
{
    public const TRIGGER_SESSION_OPEN = 'session_open';

    public const TRIGGER_CASH_SALE = 'cash_sale';

    public const TRIGGER_PICKUP = 'pickup';

    public const TRIGGER_CLOSE = 'close';

    public function __construct(
        private readonly PosCashDrawerAdapter $drawerAdapter
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     triggered:bool,
     *     success:bool,
     *     message:string|null
     * }
     */
    public function triggerDrawerOpen(
        string $trigger,
        int $terminalId,
        int $settingId,
        array $context = [],
        ?PosTerminal $loadedTerminal = null
    ): array {
        $terminal = $loadedTerminal ?? PosTerminal::query()
            ->with('policy')
            ->where('id', $terminalId)
            ->where('setting_id', $settingId)
            ->first();

        if (! $terminal || ! $terminal->policy) {
            return [
                'triggered' => false,
                'success' => false,
                'message' => 'Terminal or policy not found.',
            ];
        }

        $shouldOpen = match ($trigger) {
            self::TRIGGER_SESSION_OPEN => (bool) $terminal->policy->auto_open_drawer_on_session_open,
            self::TRIGGER_CASH_SALE => (bool) $terminal->policy->auto_open_drawer_on_cash_sale,
            self::TRIGGER_PICKUP => (bool) $terminal->policy->auto_open_drawer_on_pickup,
            self::TRIGGER_CLOSE => (bool) $terminal->policy->auto_open_drawer_on_close,
            default => false,
        };

        if (! $shouldOpen) {
            return [
                'triggered' => false,
                'success' => true,
                'message' => "Drawer open not configured for trigger: {$trigger}",
            ];
        }

        $operationContext = array_merge([
            'trigger' => $trigger,
            'terminal_id' => $terminalId,
            'setting_id' => $settingId,
        ], $context);

        try {
            $result = $this->drawerAdapter->openDrawer($operationContext);

            if (! ((bool) ($result['success'] ?? false))) {
                Log::warning("POS cash drawer action failed for trigger: {$trigger}.", [
                    'context' => $operationContext,
                    'result' => $result,
                ]);

                return [
                    'triggered' => true,
                    'success' => false,
                    'message' => $result['message'] ?? 'Drawer operation failed.',
                ];
            }

            return [
                'triggered' => true,
                'success' => true,
                'message' => $result['message'] ?? 'Drawer operation succeeded.',
            ];
        } catch (Throwable $exception) {
            Log::error("POS cash drawer adapter threw an exception for trigger: {$trigger}.", [
                'context' => $operationContext,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [
                'triggered' => true,
                'success' => false,
                'message' => 'Drawer adapter encountered an internal error.',
            ];
        }
    }
}
