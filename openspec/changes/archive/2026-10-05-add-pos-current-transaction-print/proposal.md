# Proposal

## Why

Cashiers sometimes need to hand a customer a draft POS receipt before payment while continuing to edit the same transaction. Today they must save and open a new cart, print the saved draft, then manually load it again.

## What Changes

- Add **Simpan dan Cetak** beside the existing save action in the POS payment area, with **Pilih Pembayaran** remaining the primary full-width action below.
- Save the current cart as a draft and keep that same draft loaded in the active POS session, including its transaction code. Later prints update the same draft.
- Open the existing draft receipt in a new window and invoke browser printing after the save and reload succeed.
- Require `pos.transactions.save`, `pos.transactions.load`, and a new `pos.transactions.print-current` permission at both the UI and server action.
- Show all user-facing labels and errors in Bahasa Indonesia; prevent duplicate clicks and avoid printing after a failed save or reload.

## Capabilities

### New Capabilities

- `pos-current-transaction-print`: Save, retain, and print the current POS transaction as an authorized draft receipt.

### Modified Capabilities

- None. The existing draft receipt format and route behavior remain the source of the printed document.

## Impact

- POS transaction service/controller and sell screen, permission registry and POS role bundle surfaces, and focused POS feature tests.
- A new guarded POST action may be added for the combined save-and-retain operation. No schema change or external dependency is expected.
