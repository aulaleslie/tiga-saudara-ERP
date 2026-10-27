# Proposal

## Why

POS currently combines full payment and Kasbon behind one payment button and a debt toggle, making intent and payment reset behavior unclear. A production Kasbon checkout for Rp52,985,000 with a Rp30,000,000 down payment also exposed integer overflow in proportional payment allocation.

## What Changes

- Give Bayar Lunas a larger primary button, labeled with Tunai / Transfer / Multi Pembayaran, beside a vertical stack of Simpan dan Buka Baru, Simpan dan Cetak, and Kasbon.
- Hide actions without permission and collapse unused layout space; preserve server authorization and prerequisite guards.
- Make full payment and Kasbon explicit flows with switching available from the main actions and inside the modal.
- Confirm switching when payment progress exists; clear payment-specific browser/server state only after confirmation, preserving cart, customer, and transaction notes.
- Support multiple down-payment entries and explicit Kasbon completion; combined down payments must remain below the total.
- Fix overflow in proportional allocation without losing exact minor-unit reconciliation or deterministic rounding.
- Require focused PHP, JavaScript, and visual verification only; do not plan a full-suite test run.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-checkout-ui`: Prominent full-payment action, stacked secondary actions, and permission-adaptive layout.
- `pos-staged-checkout-wiring`: Explicit flow identity, confirmed switching, payment reset, and same-flow recovery.
- `pos-debt-checkout`: Dedicated Kasbon entry, multiple down payments, and large-value allocation correctness.

## Impact

Affected areas include Modules/Pos/Resources/views/sell/shell/payment.blade.php, sell.blade.php, staged_checkout.blade.php, related POS styles, public/js/pos-staged-payment.js, payment-chain routes/controller handling, and PosCheckoutPaymentSplitService. Reuse existing posting, payment evidence, approval, customer, term, and permission services. No historical transaction rewrite or new financial ledger is intended.
