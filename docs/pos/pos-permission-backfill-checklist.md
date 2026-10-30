# POS Permission Backfill Checklist

Use this checklist before deploying permission-driven POS capabilities to production.

## 1. Sync Permission Registry

- [ ] Deploy code containing updated `app/Config/Permissions.php`.
- [ ] Run `php artisan permissions:sync` (or `php artisan db:seed --class=PermissionsTableSeeder`) on each environment.
- [ ] Verify these permissions exist in `permissions` table:
  - `pos.checkout.payment`
  - `pos.checkout.debt`
  - `pos.checkout.debt.request`
  - `pos.sessions.require-terminal`
  - `pos.cart.clear`
  - `pos.cart.line.remove`
  - `pos.cart.line.reduce`
  - `pos.sessions.open.total-only`

## 2. Assign Live Bundles

- [ ] Helper role has: `pos.access`, `pos.sell`, `pos.sessions.open`, `pos.transactions.save`.
- [ ] Helper role does not have: `pos.checkout.payment`, `pos.checkout.debt`, `pos.checkout.debt.request`, `pos.sessions.require-terminal`.
- [ ] Cashier role has: helper permissions + `pos.checkout.payment` (for Bayar Lunas).
- [ ] Explicitly assign `pos.checkout.debt.request` (under **Pengecualian Hak Akses**) to cashier roles/users who are permitted to initiate Kasbon transactions with supervisor approval. Note that `pos.checkout.payment` alone no longer permits initiating or requesting approval for Kasbon checkouts, and `pos.checkout.debt.request` is not auto-granted to existing roles or default bundles.
- [ ] Explicitly assign `pos.sessions.open.total-only` (under **Pengecualian Hak Akses**) only to roles/users allowed to open terminal sessions with a nominal total instead of a denomination count. It is not auto-granted to existing roles or default bundles.
- [ ] Do not rely on the terminal checkbox "Izinkan input total saldo awal saja" (`allow_total_only_float_input`); it has been removed from terminal configuration and no longer authorizes nominal opening.
- [ ] Manager role has: cashier permissions + `pos.checkout.debt` (direct Kasbon without approval) + supervisor/direct-action permissions as required by SOP.

## 3. Live-Style Verification

- [ ] Helper account can open session without terminal.
- [ ] Helper account can enter `/pos/sell` and save draft.
- [ ] Helper account gets `403` on:
  - `GET /pos/sell/payment-methods/search`
  - `POST /pos/sell/checkout/stage-payment`
  - `POST /pos/sell/checkout/finalize`
- [ ] Cashier account must choose terminal + opening float on session open.
- [ ] Verify at least one cash denomination is enabled before cashier rollout (counted opening is blocked when all are disabled).
- [ ] Cashier without `pos.sessions.open.total-only` opens a terminal session by entering denomination quantities; the saved total equals the count sum and the OPEN_FLOAT event stores the breakdown with `opening_cash_input_mode = denominations`.
- [ ] Cashier without `pos.sessions.open.total-only` does not see the nominal switch, and a crafted `opening_cash_input_mode=nominal` request is rejected.
- [ ] User with `pos.sessions.open.total-only` can switch to nominal entry; the OPEN_FLOAT event stores null denominations with `opening_cash_input_mode = nominal`.
- [ ] Cashier account can complete staged payment and finalize checkout.
- [ ] Non-manager role with explicit `pos.overrides.price` can override price directly without supervisor token.

## 4. Rollout Gate

- [ ] Complete test suite subset for POS permission bundle changes.
- [ ] Confirm no role-name-based fallback remains in POS runtime authorization paths.
- [ ] Business owner signs off helper/cashier/manager behavior matrix.

## 5. Rollback Notes (Opening Cash Denomination Count)

- Rolling back application code restores the previous total-only opening form; no data migration is required.
- Keep the `pos.sessions.open.total-only` permission record and its assignments; older code ignores it.
- Historical OPEN_FLOAT `denominations` and `metadata.opening_cash_input_mode` evidence is retained and is not rewritten.
- The `allow_total_only_float_input` column is retained, so older code that reads it continues to work.

