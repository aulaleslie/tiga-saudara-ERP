## Why

The three most recent feature deliveries (global payment filters, privileged purchase corrections, partial-receiving shortfall completion) were written against CSS dialects the app does not load — Bootstrap 5 and raw Tailwind — while the app's foundation is CoreUI 3 (Bootstrap 4) plus a utilities-only Tailwind v4 layer. This produces unstyled dropdowns, dead dismiss buttons, and a visually alien correction page. Alongside the styling drift, several state-sync defects make actions appear "not reflected": the global payment page desyncs on refresh, the receiving-completion modal never refreshes the purchase list, and the local-mode Pelunasan summary card shows 1/100th of the real amount due to a leftover 100× scaling divide.

## What Changes

- Translate Bootstrap 5-only classes in recent views to the CoreUI 3 / Bootstrap 4 dialect actually loaded by the app:
  - Global payment filter panels in `resources/views/livewire/purchase/purchase-table.blade.php` and `resources/views/livewire/sale/sale-table.blade.php` (`form-select` → styled select consistent with existing pages, per-page selectors included).
  - Receiving-completion modal (`btn-close`, `data-bs-dismiss`, `visually-hidden`, `fs-5`, `bg-opacity-10`, `form-switch` equivalents).
  - Remove the duplicate `class` attribute on the purchase reference link.
- Rebuild the purchase correction page (`Modules/Purchase/Resources/views/corrections/edit.blade.php`) using the app's standard CoreUI card/form/button patterns instead of raw Tailwind; replace `alert()` dialogs with the app's standard feedback patterns; debounce the payment-preview fetch instead of firing per keystroke.
- Fix global payment page state restoration on refresh:
  - `PurchaseTable`/`SaleTable` recompute derived card-filter query flags from the URL-restored `selectedCardFilter` during mount.
  - Pages pass applied filters and card selection into the summary-cards components at mount so totals and highlight match the table.
- Make shortfall completion reflect immediately: purchase table listens for `purchaseReceivingCompleted` and refreshes; success feedback is shown without requiring a manual page reload.
- Fix `PurchaseSummaryCards::getPelunasanProperty()` local-mode `/100` scaling leftover so the Pelunasan card shows correct rupiah totals.

## Capabilities

### New Capabilities

- `partial-purchase-receiving-completion`: Main spec is absent from `openspec/specs/` (archived change was not synced); this change re-establishes it including the new post-completion list refresh and feedback requirements.

### Modified Capabilities

- `global-purchase-multi-payment`: Applied filters and summary-card selection restored from the URL must be fully reflected in both the table results and the summary cards after a page refresh.
- `global-sales-multi-payment`: Same refresh-state restoration requirement for the sales side.
- `purchase-summary-cards`: Local-mode Pelunasan totals must be reported in stored rupiah without legacy 100× rescaling.
- `privileged-received-purchase-corrections`: Correction form must use the application's standard UI patterns for feedback (no blocking browser alerts) and debounce preview requests.

## Impact

- Views: `resources/views/livewire/purchase/purchase-table.blade.php`, `resources/views/livewire/sale/sale-table.blade.php`, `Modules/Purchase/Resources/views/corrections/edit.blade.php`, `Modules/Purchase/Resources/views/livewire/modals/purchase-receiving-completion-modal.blade.php`, `Modules/Purchase/Resources/views/payments/global-index.blade.php`, `Modules/Sale` global payments index.
- Components: `app/Livewire/Purchase/PurchaseTable.php`, `app/Livewire/Sale/SaleTable.php`, `Modules/Purchase/Livewire/PurchaseSummaryCards.php`, `Modules/Sale/Livewire/SaleSummaryCards.php`, `Modules/Purchase/Livewire/Modals/PurchaseReceivingCompletionModal.php`.
- No schema/database changes; no route changes. Existing feature tests for global payment filters/tables must keep passing; new tests cover mount-time card-filter restoration, list refresh on completion, and Pelunasan totals.
