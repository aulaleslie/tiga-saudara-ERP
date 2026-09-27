# POS Permission Bundle Matrix

This document defines the permission-driven POS bundle model used by runtime authorization.

## Live Bundles

### Helper / Handoff Operator

Required permissions:

- `pos.access`
- `pos.sell`
- `pos.sessions.open`
- `pos.transactions.save`
- `pos.transactions.load` (if draft continuation is required)

Must NOT have:

- `pos.checkout.payment`
- `pos.sessions.require-terminal`

Expected behavior:

- Can open session without terminal
- Can enter POS shell
- Can use `Simpan dan Buka Baru`
- Cannot search payment methods, stage payment, or finalize checkout

### Cashier / Checkout Operator

Required permissions:

- `pos.access`
- `pos.sell`
- `pos.sessions.open`
- `pos.sessions.require-terminal`
- `pos.checkout.payment`
- `pos.transactions.save`

Expected behavior:

- Must select terminal + opening float on session open
- Can stage payment, recover/reset payment chain, and finalize checkout
- Can still use `Simpan dan Buka Baru`

### Manager / Supervisor POS

Required permissions:

- Cashier bundle
- `pos.supervisor.approval`
- `pos.sessions.view`
- Direct cart-action permissions as needed:
  - `pos.cart.clear`
  - `pos.cart.line.remove`
  - `pos.cart.line.reduce`
  - `pos.overrides.price`

Expected behavior:

- All cashier flow permissions
- Can access supervisor queue
- Direct privileged cart actions follow explicit direct permissions

## Core Policy Notes

- Terminal requirement is controlled only by `pos.sessions.require-terminal`.
- Payment flow access is controlled only by `pos.checkout.payment`.
- `pos.sell` controls POS shell/cart access, not payment authority.
- Price override direct bypass is controlled only by `pos.overrides.price`.
