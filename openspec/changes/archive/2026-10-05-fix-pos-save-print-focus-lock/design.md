# Design

## Context

- **Simpan dan Cetak** synchronously opens a blank tab on click and navigates it to the dedicated `pos.sell.transactions.print-receipt` route after saving. That route previously passed `autoPrint`, causing the receipt to call `window.print()` on load.
- Cashiers closing the linked receipt tab with Ctrl+W while Chrome print preview is open can leave POS unable to accept focus until reload. Opening the receipt without immediately starting print preview avoids that trigger during the normal save flow.
- Bootstrap 4 emits Cari Produk `shown.bs.modal` through jQuery. The previous native-only bindings did not run. The search-result keyboard setup also needs one listener per container, since results may survive modal reopen.

## Goals / Non-Goals

**Goals:**
- Show the saved draft receipt in a new tab without starting the print dialog automatically.
- Keep the dedicated receipt route and its current-transaction permissions and guards.
- Focus Cari Produk's keyword input on open and ensure Enter on a preserved card adds once.

**Non-Goals:**
- Changing checkout, draft, or reprint entry points.
- Preventing a cashier from manually printing and closing the tab while preview is open.
- Changing receipt layout or permissions.

## Decisions

1. **Keep the synchronous new-tab flow and dedicated print-receipt route.** The tab opens during the click to satisfy popup blockers; after save it navigates to the receipt URL. The route authorizes the active LOADED cart transaction with the three current-transaction permissions. Reusing `/pos/transactions/{id}/receipt` was rejected because it requires `pos.transactions.view`.
2. **Remove automatic printing from `printCurrentReceipt`.** The receipt is shown for review and the cashier uses its **Cetak Struk** button. A hidden iframe was rejected because it diverges from checkout and draft print flows. The receipt view's optional `autoPrint` block remains unchanged; this route no longer enables it. No other caller currently passes `autoPrint`.
3. **Bind Cari Produk `shown.bs.modal` through jQuery when present**, with a native fallback. Keyboard setup runs first; keyword-input focus runs second.
4. **Delegate result-card keydown handling once on the results container.** Reopening the modal may reset focus but cannot stack listeners on preserved cards.

## Risks / Trade-offs

- A cashier who manually clicks **Cetak Struk** and then closes the linked receipt tab during print preview may still hit the pre-existing focus lockup. This behavior is shared with checkout and draft printing and is outside this change.
- Popup blockers can still block the new tab; the existing warning remains.
- Browser behavior requires a human check in addition to focused source and route assertions.
