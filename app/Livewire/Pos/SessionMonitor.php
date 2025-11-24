<?php

namespace App\Livewire\Pos;

use App\Models\PosSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class SessionMonitor extends Component
{
    public array $sessions = [];

    public Collection $locations;

    public string $statusFilter = '';

    public string $locationFilter = '';

    public bool $alertsOnly = false;

    public int $idleThresholdMinutes = 0;

    public float $defaultCashThreshold = 0.0;

    protected array $alertedSessionIds = [];

    protected $queryString = [
        'statusFilter' => ['except' => ''],
        'locationFilter' => ['except' => ''],
        'alertsOnly' => ['except' => false],
    ];

    public function mount(): void
    {
        $settingId = session('setting_id');
        $setting = $settingId ? Setting::find($settingId) : null;

        $this->idleThresholdMinutes = (int) ($setting?->pos_idle_threshold_minutes ?? 0);
        $this->defaultCashThreshold = (float) ($setting?->pos_default_cash_threshold ?? 0);

        $this->locations = Location::query()
            ->where('setting_id', $settingId)
            ->orderBy('name')
            ->get(['id', 'name', 'pos_cash_threshold']);

        $this->refreshSessions();
    }

    public function updatedStatusFilter(): void
    {
        $this->refreshSessions();
    }

    public function updatedLocationFilter(): void
    {
        $this->refreshSessions();
    }

    public function updatedAlertsOnly(): void
    {
        $this->refreshSessions();
    }

    public function refreshSessions(): void
    {
        $settingId = session('setting_id');

        $query = PosSession::query()
            ->with(['cashier:id,name,email', 'location:id,name,setting_id,pos_cash_threshold'])
            ->withSum(['sales as sales_total' => function ($builder) use ($settingId) {
                if ($settingId) {
                    $builder->where('setting_id', $settingId);
                }
            }], 'total_amount')
            ->withSum(['payments as cash_payments_total' => function ($builder) {
                $builder->whereHas('paymentMethod', function ($method) {
                    $method->where('is_cash', true);
                });
            }], 'amount')
            ->withMax(['sales as latest_sale_at'], 'created_at')
            ->withMax(['payments as latest_payment_at'], 'created_at')
            ->latest('id');

        if ($settingId) {
            $query->where(function ($builder) use ($settingId) {
                $builder->whereHas('location', function ($locationQuery) use ($settingId) {
                    $locationQuery->where('setting_id', $settingId);
                })->orWhere(function ($subQuery) use ($settingId) {
                    $subQuery->whereNull('location_id')
                        ->whereHas('cashier.settings', function ($settings) use ($settingId) {
                            $settings->where('settings.id', $settingId);
                        });
                });
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->locationFilter !== '') {
            $query->where('location_id', $this->locationFilter);
        }

        $sessions = $query->get();

        $mapped = $sessions->map(function (PosSession $session) {
            $lastActivity = $this->resolveLastActivity($session);
            $threshold = $this->resolveCashThreshold($session);
            $estimatedCash = $this->calculateEstimatedCash($session);

            $alerts = [
                'idle' => $this->isIdle($session, $lastActivity),
                'cash' => $this->isOverCashThreshold($estimatedCash, $threshold),
            ];

            return [
                'id' => $session->id,
                'cashier' => $session->cashier?->name ?? '—',
                'device' => $session->device_name ?? '—',
                'location' => $session->location?->name ?? '—',
                'status' => $session->status,
                'cash_float' => (float) $session->cash_float,
                'sales_total' => (float) ($session->sales_total ?? 0),
                'cash_payments_total' => (float) ($session->cash_payments_total ?? 0),
                'estimated_cash' => $estimatedCash,
                'last_activity_at' => $lastActivity?->toDateTimeString(),
                'last_activity_for_humans' => $lastActivity?->diffForHumans() ?? '—',
                'threshold' => $threshold,
                'alerts' => $alerts,
                'started_at' => $session->started_at,
            ];
        });

        if ($this->alertsOnly) {
            $mapped = $mapped->filter(function ($session) {
                return $session['alerts']['idle'] || $session['alerts']['cash'];
            })->values();
        }

        $this->sessions = $mapped->all();

        $this->emitAlertToasts($mapped);
    }

    public function render()
    {
        return view('livewire.pos.session-monitor');
    }

    protected function resolveCashThreshold(PosSession $session): ?float
    {
        $threshold = optional($session->location)->pos_cash_threshold ?? $this->defaultCashThreshold;

        if ($threshold === null) {
            return null;
        }

        $threshold = round((float) $threshold, 2);

        return $threshold > 0 ? $threshold : null;
    }

    protected function calculateEstimatedCash(PosSession $session): float
    {
        if ($session->status === PosSession::STATUS_CLOSED && $session->actual_cash !== null) {
            return (float) $session->actual_cash;
        }

        $base = (float) ($session->cash_float ?? 0);
        $cashIn = (float) ($session->cash_payments_total ?? 0);

        return round($base + $cashIn, 2);
    }

    protected function resolveLastActivity(PosSession $session): ?Carbon
    {
        $timestamps = collect([
            $session->latest_sale_at,
            $session->latest_payment_at,
            $session->resumed_at,
            $session->started_at,
        ])->filter();

        if ($session->status === PosSession::STATUS_PAUSED && $session->paused_at) {
            $timestamps->push($session->paused_at);
        }

        if ($timestamps->isEmpty()) {
            return null;
        }

        return Carbon::parse($timestamps->max());
    }

    protected function isIdle(PosSession $session, ?Carbon $lastActivity): bool
    {
        if ($this->idleThresholdMinutes <= 0 || $session->status !== PosSession::STATUS_ACTIVE) {
            return false;
        }

        $cutoff = Carbon::now()->subMinutes($this->idleThresholdMinutes);

        if (! $lastActivity) {
            return true;
        }

        return $lastActivity->lessThan($cutoff);
    }

    protected function isOverCashThreshold(float $estimatedCash, ?float $threshold): bool
    {
        if ($threshold === null) {
            return false;
        }

        return $estimatedCash > $threshold;
    }

    protected function emitAlertToasts(Collection $sessions): void
    {
        $alerting = $sessions->filter(function ($session) {
            return $session['alerts']['idle'] || $session['alerts']['cash'];
        });

        $newAlerts = $alerting->reject(function ($session) {
            return in_array($session['id'], $this->alertedSessionIds, true);
        });

        foreach ($newAlerts as $session) {
            $problems = [];

            if ($session['alerts']['idle']) {
                $problems[] = 'idle terlalu lama';
            }

            if ($session['alerts']['cash']) {
                $problems[] = 'kas melebihi ambang';
            }

            $this->dispatchBrowserEvent('pos-session-alert', [
                'message' => sprintf(
                    'Sesi %s di %s %s.',
                    $session['cashier'] ?: 'Tanpa kasir',
                    $session['device'] ?: 'perangkat tidak dikenal',
                    implode(' dan ', $problems)
                ),
                'type' => 'warning',
            ]);
        }

        $this->alertedSessionIds = $alerting->pluck('id')->all();
    }
}
