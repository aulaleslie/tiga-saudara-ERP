## Context

The current "Simpan dan Buka Baru" button simply saves the transaction (as a `PosTransaction` with DRAFT status) and clears the cart session. There is no on-screen confirmation of the saved transaction code, nor is there an immediate way to print a draft receipt. The user currently has to navigate to the transaction list to see the code or print details, which is a significant bottleneck in high-throughput POS scenarios. Additionally, thermal printers used in some locations have legibility issues with the default font weight.

## Goals / Non-Goals

**Goals:**
- Provide immediate visual confirmation of the saved transaction number (TRX ID).
- Enable one-click printing of a draft receipt (without payment) directly from the POS interface.
- Improve receipt legibility by increasing font weights in the thermal printer-optimized CSS.
- Ensure the workflow keeps the cart clear and ready for the next customer after saving.

**Non-Goals:**
- Allowing payment processing in the save-and-new modal (this is only for DRAFT saving).
- Adding complex multi-template receipt support (reusing the existing receipt template).
- Modifying the finalized checkout receipt flow (only adding a draft-only equivalent).

## Decisions

- **Modal Implementation**: Add a custom Bootstrap modal to `sell.blade.php` instead of using generic browser alerts to maintain consistent POS aesthetics and branding.
- **Route for Draft Receipt**: Create a new controller method and route specifically for `PosTransaction` receipts. This ensures we don't break existing `PosCheckout`-based receipt logic and allows for a "DRAFT" label in the printout.
- **Reuse Receipt Template**: Leverage the existing `receipt.blade.php` to ensure visual parity. Data mapping will be handled in `PosReceiptService`.
- **Styling Change**: Update the CSS in `receipt.blade.php` to use `font-weight: 600` or higher for thermal printer compatibility.

## Risks / Trade-offs

- **Printer Differences**: Bold font rendering may vary across different thermal printers. A moderate weight increase is preferred over extreme values.
- **Draft Identification**: It is critical that draft receipts are clearly labeled "DRAFT" or "PRO-FORMA" to prevent them from being mistaken for valid finalized payment receipts.
- **Performance**: Fetching transaction data for printing should be efficient, as this is a high-speed workflow.
