<?php

namespace App\Livewire\Pos;

use App\Models\CashierCashMovement;
use App\Support\PosSessionManager;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Throwable;

abstract class CashMovementComponent extends Component
{
    /** @var array<string, int|string|null> */
    public array $denominations = [];

    /** @var array<int, string|null> */
    public array $supportingDocuments = [];

    public $manualAdjustment = null;

    public $expectedTotal = null;

    public string $notes = '';

    public string $currencySymbol = 'Rp';

    /**
     * @var array<int, float|int>
     */
    protected array $denominationOptions = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50];

    public function mount(): void
    {
        $this->currencySymbol = $this->resolveCurrencySymbol();
        $this->resetDenominations();
        $this->supportingDocuments = [''];
        $this->manualAdjustment = null;
        $this->expectedTotal = null;
        $this->notes = '';
    }

    public function updatedDenominations($value): void
    {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $denomination => $count) {
            $this->denominations[$denomination] = $this->sanitizeCount($count);
        }
    }

    public function updatedManualAdjustment($value): void
    {
        $this->manualAdjustment = $this->sanitizeFloat($value, allowNegative: false);
    }

    public function updatedExpectedTotal($value): void
    {
        $this->expectedTotal = $this->sanitizeFloat($value, allowNegative: true);
    }

    public function addDocumentField(): void
    {
        $this->supportingDocuments[] = '';
    }

    public function removeDocumentField(int $index): void
    {
        if (!isset($this->supportingDocuments[$index])) {
            return;
        }

        unset($this->supportingDocuments[$index]);
        $this->supportingDocuments = array_values($this->supportingDocuments);

        if (empty($this->supportingDocuments)) {
            $this->supportingDocuments = [''];
        }
    }

    public function getDenominationTotalProperty(): float
    {
        $total = 0.0;

        foreach ($this->denominations as $value => $count) {
            $total += ((float) $value) * $this->sanitizeCount($count);
        }

        return round($total, 2);
    }

    public function getCountedTotalProperty(): float
    {
        return round($this->denominationTotal + $this->sanitizeFloat($this->manualAdjustment, allowNegative: false, default: 0.0), 2);
    }

    public function getVarianceProperty(): ?float
    {
        $expected = $this->sanitizeFloat($this->expectedTotal, allowNegative: true, default: null);

        if ($expected === null) {
            return null;
        }

        return round($this->countedTotal - $expected, 2);
    }

    protected function sanitizeCount($value): int
    {
        if ($value === '' || $value === null) {
            return 0;
        }

        if (!is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    protected function sanitizeFloat($value, bool $allowNegative = false, ?float $default = 0.0): ?float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        if (!is_numeric($value)) {
            return $default;
        }

        $numeric = (float) $value;

        if (!$allowNegative) {
            $numeric = max(0.0, $numeric);
        }

        return round($numeric, 2);
    }

    protected function resetForm(bool $resetExpected = true): void
    {
        $this->resetDenominations();
        $this->supportingDocuments = [''];
        $this->manualAdjustment = null;
        $this->notes = '';

        if ($resetExpected) {
            $this->expectedTotal = null;
        }

        $this->resetValidation();
        $this->resetErrorBag();
    }

    protected function resetDenominations(): void
    {
        $this->denominations = [];

        foreach ($this->denominationOptions as $value) {
            $this->denominations[(string) $value] = '';
        }
    }

    protected function resolveCurrencySymbol(): string
    {
        try {
            if (function_exists('settings')) {
                $settings = settings();

                if ($settings && $settings->currency && $settings->currency->symbol) {
                    return (string) $settings->currency->symbol;
                }
            }
        } catch (Throwable $exception) {
            // Ignore and fallback
        }

        return config('app.currency_symbol', 'Rp');
    }

    protected function persistMovement(string $type, array $overrides = []): CashierCashMovement
    {
        $userId = Auth::id();

        $posSession = app(PosSessionManager::class)->ensureActive();
        $posSessionId = $posSession->id ?? null;

        abort_if(!$userId, 403, 'Pengguna tidak terautentikasi.');

        $cashTotal = $this->sanitizeFloat($overrides['cash_total'] ?? $this->countedTotal, allowNegative: true, default: 0.0) ?? 0.0;
        $expectedTotal = $this->sanitizeFloat($overrides['expected_total'] ?? $this->expectedTotal, allowNegative: true, default: null);

        $variance = $overrides['variance'] ?? ($expectedTotal === null ? null : round($cashTotal - $expectedTotal, 2));
        $variance = $variance === null ? null : $this->sanitizeFloat($variance, allowNegative: true, default: null);

        $denominations = $overrides['denominations'] ?? $this->prepareDenominations();
        $documents = $overrides['documents'] ?? $this->prepareDocuments();
        $metadata = $overrides['metadata'] ?? null;

        if ($posSessionId) {
            if (! is_array($metadata)) {
                $metadata = $metadata ? (array) $metadata : [];
            }

            $metadata['pos_session_id'] = $posSessionId;
        }

        if (is_array($metadata)) {
            $metadata = array_filter($metadata, static fn ($value) => $value !== null && $value !== '');
            $metadata = empty($metadata) ? null : $metadata;
        }

        return CashierCashMovement::create([
            'user_id' => $userId,
            'movement_type' => $type,
            'cash_total' => round($cashTotal, 2),
            'expected_total' => $expectedTotal === null ? null : round($expectedTotal, 2),
            'variance' => $variance === null ? null : round($variance, 2),
            'denominations' => $denominations,
            'documents' => $documents,
            'notes' => $this->notes ? trim($this->notes) : null,
            'metadata' => $metadata,
            'recorded_at' => now(),
        ]);
    }

    protected function prepareDenominations(): ?array
    {
        $filtered = [];

        foreach ($this->denominations as $value => $count) {
            $count = $this->sanitizeCount($count);

            if ($count <= 0) {
                continue;
            }

            $filtered[$value] = $count;
        }

        return empty($filtered) ? null : $filtered;
    }

    protected function prepareDocuments(): ?array
    {
        $documents = [];

        foreach ($this->supportingDocuments as $document) {
            $document = $document === null ? null : trim((string) $document);

            if (!$document) {
                continue;
            }

            $documents[] = $document;
        }

        return empty($documents) ? null : $documents;
    }
}
