# Rollout And Rollback Notes

## Scope

This rollout hardens POS supervised cart/session flow with:

- Role-aware terminal/session opening policy.
- Deterministic approval lifecycle for restricted cart actions.
- Async approval support for price override.
- Completed checkout representation in POS transaction history.
- Draft-only mutation guard on loaded transaction carts.

## Migration Notes

Run application migrations before enabling traffic:

```bash
php artisan migrate
```

New invariant introduced:

- One active POS session per `(setting_id, cashier_user_id)` via `pos_sessions_user_active_unique`.

Operational expectation:

- Existing duplicate active sessions for the same user in a setting must be resolved before migration.

## Validation Checklist

1. Cashier session open without terminal must be rejected.
2. Floor/Manager session open without terminal must succeed.
3. Restricted actions show approval states: `pending`, `approved`, `rejected`.
4. Approved token can be consumed once; cancelled approval does not mutate cart.
5. Checkout from active cart (without loaded draft) creates a completed `pos_transactions` row.
6. `/pos/transactions/data` with empty status filter includes completed transactions.

## Session Opening Cash Workflow (Denomination Count & Nominal Bypass)

- **Default denomination-quantity workflow (terminal sessions)**:
  1. When a terminal is selected on `/pos/sessions/open`, the **Saldo Awal** section displays globally enabled cash denominations (`Modules\Setting\Entities\CashDenomination`), grouped by coin (`Uang Logam`, `keping`) and banknote (`Uang Kertas`, `lembar`).
  2. Cashiers enter whole-number quantities per denomination (blank inputs normalize to `0`). Each row shows its live subtotal and the footer displays the calculated total (`Total Saldo Awal`).
  3. On submission (`opening_cash_input_mode = denominations`, the default when omitted), the server re-validates submitted denomination keys against currently enabled global denominations, calculates a strictly positive rupiah total using integer arithmetic, ignores any client-submitted `opening_float_total`, and persists the nonzero value-to-quantity snapshot on the `OPEN_FLOAT` cash event (`denominations` and `metadata.opening_cash_input_mode = denominations`).
  4. If all global denominations are disabled, counted opening is blocked with guidance to contact an administrator to enable denominations in **Konfigurasi Pecahan Uang**.
- **Authorized nominal entry (`pos.sessions.open.total-only`)**:
  1. Only users holding both `pos.sessions.open` and `pos.sessions.open.total-only` see the switch **Input total nominal tanpa hitung pecahan** on the session-open form (which still defaults to denomination counts on a fresh load).
  2. When switched to `nominal` (`opening_cash_input_mode = nominal`), the user enters a formatted positive rupiah total (`opening_float_total`). Any stale denomination inputs are disabled in the browser and ignored by the server, and `OPEN_FLOAT` records `denominations = null` with `metadata.opening_cash_input_mode = nominal`.
  3. The legacy terminal policy column `allow_total_only_float_input` is retired from the terminal configuration UI and no longer authorizes or blocks nominal entry.
- **Terminal-free sessions**:
  - When no terminal is selected (or for floor-staff sessions), the opening cash section stays hidden and opening cash normalizes to `0` with `denominations = null`.

## Rollback

1. Revert application code to previous release.
2. Roll back migrations if needed:

```bash
php artisan migrate:rollback --step=1
```

3. Clear caches:

```bash
php artisan optimize:clear
```

If rollback is blocked by data shape changes, keep the migration and roll back only the application code.
