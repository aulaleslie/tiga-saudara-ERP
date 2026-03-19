# POS Permission Backfill Checklist

Use this checklist before deploying permission-driven POS capabilities to production.

## 1. Sync Permission Registry

- [ ] Deploy code containing updated `app/Config/Permissions.php`.
- [ ] Run `php artisan permissions:sync` on each environment.
- [ ] Verify these permissions exist in `permissions` table:
  - `pos.checkout.payment`
  - `pos.sessions.require-terminal`
  - `pos.cart.clear`
  - `pos.cart.line.remove`
  - `pos.cart.line.reduce`

## 2. Assign Live Bundles

- [ ] Helper role has: `pos.access`, `pos.sell`, `pos.sessions.open`, `pos.transactions.save`.
- [ ] Helper role does not have: `pos.checkout.payment`, `pos.sessions.require-terminal`.
- [ ] Cashier role has: helper permissions + `pos.checkout.payment`, `pos.sessions.require-terminal`.
- [ ] Manager role has: cashier permissions + supervisor/direct-action permissions as required by SOP.

## 3. Live-Style Verification

- [ ] Helper account can open session without terminal.
- [ ] Helper account can enter `/pos/sell` and save draft.
- [ ] Helper account gets `403` on:
  - `GET /pos/sell/payment-methods/search`
  - `POST /pos/sell/checkout/stage-payment`
  - `POST /pos/sell/checkout/finalize`
- [ ] Cashier account must choose terminal + opening float on session open.
- [ ] Cashier account can complete staged payment and finalize checkout.
- [ ] Non-manager role with explicit `pos.overrides.price` can override price directly without supervisor token.

## 4. Rollout Gate

- [ ] Complete test suite subset for POS permission bundle changes.
- [ ] Confirm no role-name-based fallback remains in POS runtime authorization paths.
- [ ] Business owner signs off helper/cashier/manager behavior matrix.
