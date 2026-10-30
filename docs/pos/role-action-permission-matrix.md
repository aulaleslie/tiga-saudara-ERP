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

- Terminal requirement is controlled by session-open terminal policy (`canCheckout` requires an assigned terminal except for manager checkout roles).
- Shared checkout authority (`hasCheckoutAuthority`) is satisfied when a user holds at least one checkout flow permission (`pos.checkout.payment`, `pos.checkout.debt`, or `pos.checkout.debt.request`).
- Checkout flows are authorized independently:
  - **Bayar Lunas (`full_payment`)**: Controlled exclusively by `pos.checkout.payment`. Holding `pos.checkout.payment` alone no longer grants implicit access to Kasbon staging or Kasbon supervisor approval requests.
  - **Kasbon (`kasbon` — direct completion)**: Controlled by `pos.checkout.debt`. Allows staging and finalizing Kasbon transactions directly without supervisor approval. When a user holds both `pos.checkout.debt` and `pos.checkout.debt.request`, direct Kasbon authority (`pos.checkout.debt`) takes precedence.
  - **Kasbon (`kasbon` — approval-required)**: Controlled by `pos.checkout.debt.request` (`Ajukan Checkout sebagai Utang`, grouped under `Pengecualian Hak Akses`). Allows opening the Kasbon flow (labeled `Kasbon · Perlu Persetujuan`), staging down payments, and submitting a `CHECKOUT_AS_DEBT` supervisor approval request, while still requiring a valid supervisor approval token at finalization.
- **Flow Switching**: The in-modal flow switch (`Ganti ke Kasbon` / `Ganti ke Bayar Lunas`) is available only when the user holds both `pos.checkout.payment` AND (`pos.checkout.debt` OR `pos.checkout.debt.request`).
- **Administrator Assignment**: `pos.checkout.debt.request` is not automatically granted by default bundles (`floor_staff`, `cashier`, `manager`). Administrators must explicitly assign `pos.checkout.debt.request` (or `pos.checkout.debt`) to roles/users who need Kasbon checkout access.
- `pos.sell` controls POS shell/cart access, not payment authority.
- Price override direct bypass is controlled only by `pos.overrides.price`.
- **Opening cash (terminal sessions)**: Opening a session with a terminal defaults to counting banknotes/coins using the globally enabled cash denominations (`Konfigurasi Pecahan Uang`). The server derives the opening total from the counts; any client-submitted total is ignored in this mode.
  - **Nominal entry (`pos.sessions.open.total-only`, `Buka Sesi dengan Total Nominal`, grouped under `Pengecualian Hak Akses`)**: Allows a user who also holds `pos.sessions.open` to explicitly switch to entering a total without counts. It is not granted by default bundles (`floor_staff`, `cashier`, `manager`) and is checked against the acting user at submission time. Holding it alone does not allow opening a session.
  - The terminal policy flag `allow_total_only_float_input` is retired for opening cash: its checkbox is removed from terminal configuration, it neither grants nor denies nominal entry, and the stored column is retained only for compatibility/rollback.
  - Request contract: `opening_cash_input_mode` is `denominations` (default when omitted) or `nominal`. Total-only requests without the permission and explicit `nominal` mode are rejected.
  - Terminal-free sessions remain unchanged: no opening cash is required and it normalizes to zero.
