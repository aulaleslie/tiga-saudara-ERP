<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Pos\Exceptions\PosOpeningCashException;
use Modules\Setting\Entities\CashDenomination;

/**
 * Authoritative opening-cash validation and normalization for terminal POS sessions.
 *
 * Denomination mode (default) derives the total from globally enabled cash denominations
 * using integer rupiah arithmetic. Nominal mode requires the acting user to hold
 * `pos.sessions.open.total-only` and accepts a strictly positive manual total.
 */
class PosOpeningCashNormalizer
{
    public const MODE_DENOMINATIONS = 'denominations';

    public const MODE_NOMINAL = 'nominal';

    public const BYPASS_PERMISSION = 'pos.sessions.open.total-only';

    /** Upper bound matching decimal(15,2) storage for opening/expected cash. */
    public const MAX_TOTAL = 9_999_999_999_999;

    /** Upper bound for a single denomination quantity. */
    public const MAX_QUANTITY = 1_000_000;

    /**
     * @return array<int, string>
     */
    public static function modes(): array
    {
        return [self::MODE_DENOMINATIONS, self::MODE_NOMINAL];
    }

    /**
     * Globally enabled denominations in configured order.
     *
     * @return Collection<int, CashDenomination>
     */
    public function enabledDenominations(): Collection
    {
        return CashDenomination::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    public function canUseNominal(?User $actor): bool
    {
        return $actor !== null && $actor->can(self::BYPASS_PERMISSION);
    }

    /**
     * @return array{total: float, denominations: array<string, int>|null, mode: string|null}
     *
     * @throws PosOpeningCashException
     */
    public function normalize(
        bool $hasTerminal,
        ?string $mode,
        mixed $nominalTotal,
        mixed $denominations,
        ?User $actor
    ): array {
        if (! $hasTerminal) {
            return ['total' => 0.0, 'denominations' => null, 'mode' => null];
        }

        $mode = ($mode === null || $mode === '') ? self::MODE_DENOMINATIONS : $mode;

        if (! in_array($mode, self::modes(), true)) {
            throw new PosOpeningCashException('opening_cash_input_mode', 'Mode input saldo awal tidak dikenal.');
        }

        if ($mode === self::MODE_NOMINAL) {
            return $this->normalizeNominal($nominalTotal, $actor);
        }

        return $this->normalizeDenominations($denominations);
    }

    /**
     * @return array{total: float, denominations: null, mode: string}
     */
    private function normalizeNominal(mixed $nominalTotal, ?User $actor): array
    {
        if (! $this->canUseNominal($actor)) {
            throw new PosOpeningCashException(
                'opening_cash_input_mode',
                'Anda tidak memiliki izin untuk membuka sesi dengan total nominal. Hitung pecahan uang terlebih dahulu.'
            );
        }

        if (! is_numeric($nominalTotal)) {
            throw new PosOpeningCashException('opening_float_total', 'Total saldo awal wajib diisi dengan angka lebih dari nol.');
        }

        $total = round((float) $nominalTotal, 2);

        if ($total <= 0) {
            throw new PosOpeningCashException('opening_float_total', 'Total saldo awal harus lebih dari nol.');
        }

        if ($total > self::MAX_TOTAL) {
            throw new PosOpeningCashException('opening_float_total', 'Total saldo awal melebihi batas yang diizinkan.');
        }

        return ['total' => $total, 'denominations' => null, 'mode' => self::MODE_NOMINAL];
    }

    /**
     * @return array{total: float, denominations: array<string, int>, mode: string}
     */
    private function normalizeDenominations(mixed $denominations): array
    {
        $enabledValues = $this->enabledDenominations()
            ->pluck('value')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        if ($enabledValues === []) {
            throw new PosOpeningCashException(
                'opening_denominations',
                'Belum ada pecahan uang yang aktif. Hubungi admin untuk mengaktifkan pecahan uang pada konfigurasi.'
            );
        }

        if ($denominations !== null && ! is_array($denominations)) {
            throw new PosOpeningCashException('opening_denominations', 'Format jumlah pecahan uang tidak valid.');
        }

        $total = 0;
        $snapshot = [];

        foreach ($denominations ?? [] as $key => $quantity) {
            $keyString = (string) $key;

            if (! ctype_digit($keyString) || ! in_array((int) $keyString, $enabledValues, true)) {
                throw new PosOpeningCashException(
                    'opening_denominations',
                    'Pecahan uang tidak dikenal atau sudah dinonaktifkan. Muat ulang halaman untuk memakai konfigurasi terbaru.'
                );
            }

            $count = $this->normalizeQuantity($quantity);
            if ($count === 0) {
                continue;
            }

            $value = (int) $keyString;
            if ($count > intdiv(self::MAX_TOTAL - $total, $value)) {
                throw new PosOpeningCashException('opening_denominations', 'Total saldo awal melebihi batas yang diizinkan.');
            }

            $total += $count * $value;
            $snapshot[(string) $value] = ($snapshot[(string) $value] ?? 0) + $count;
        }

        if ($total <= 0) {
            throw new PosOpeningCashException(
                'opening_denominations',
                'Isi jumlah pecahan uang sehingga total saldo awal lebih dari nol.'
            );
        }

        krsort($snapshot, SORT_NUMERIC);

        return ['total' => (float) $total, 'denominations' => $snapshot, 'mode' => self::MODE_DENOMINATIONS];
    }

    private function normalizeQuantity(mixed $quantity): int
    {
        if ($quantity === null || $quantity === '') {
            return 0;
        }

        if (is_int($quantity)) {
            $count = $quantity;
        } elseif (is_string($quantity) && preg_match('/^\d+$/', trim($quantity)) === 1) {
            $count = (int) trim($quantity);
        } else {
            throw new PosOpeningCashException(
                'opening_denominations',
                'Jumlah pecahan uang harus berupa bilangan bulat tidak negatif.'
            );
        }

        if ($count < 0) {
            throw new PosOpeningCashException(
                'opening_denominations',
                'Jumlah pecahan uang harus berupa bilangan bulat tidak negatif.'
            );
        }

        if ($count > self::MAX_QUANTITY) {
            throw new PosOpeningCashException('opening_denominations', 'Jumlah pecahan uang melebihi batas yang diizinkan.');
        }

        return $count;
    }
}

