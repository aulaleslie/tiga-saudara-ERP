## 1. Global payment filter panels (purchase + sale)

- [x] 1.1 Translate BS5-only classes in `resources/views/livewire/purchase/purchase-table.blade.php` filter panel and pagination to CoreUI 3/BS4 dialect (`form-select` → `custom-select`, `fw-bold`/`me-*`/`ms-*`/`text-end` → BS4 equivalents where Tailwind does not cover them); fix duplicate `class` attribute on the purchase reference link
- [x] 1.2 Apply the same class translation to `resources/views/livewire/sale/sale-table.blade.php`
- [x] 1.3 Extract card-filter derivation in `app/Livewire/Purchase/PurchaseTable.php` into a shared `applyCardFilterType(?string $type)` method called from both the `purchase-filter` event handler and `mount()` when `selectedCardFilter` is restored from the URL
- [x] 1.4 Do the same for `app/Livewire/Sale/SaleTable.php` and its card-filter event
- [x] 1.5 Pass applied filters and `selectedCardFilter` from the request into the summary-cards components in `Modules/Purchase/Resources/views/payments/global-index.blade.php` and the sales global payments index so cards mount with matching state
- [x] 1.6 Add feature tests: mounting the global purchase and sales tables with URL-encoded `selectedCardFilter` (plus business/date filters) applies the derived query flags, and summary cards mounted with those props compute filtered totals and selection

## 2. Receiving-completion modal styling and refresh

- [x] 2.1 Translate BS5 classes in `Modules/Purchase/Resources/views/livewire/modals/purchase-receiving-completion-modal.blade.php` to CoreUI 3/BS4 (`btn-close` → `close` with `&times;`, `data-bs-dismiss` → `data-dismiss`, `visually-hidden` → `sr-only`, `fs-5`/`bg-opacity-10`/`fw-bold` equivalents)
- [x] 2.2 Add an `#[On('purchaseReceivingCompleted')]` listener on `PurchaseTable` that resets the page/re-renders so the completed purchase's status and actions update immediately
- [x] 2.3 Replace the modal's `session()->flash` success path with a feedback mechanism that renders without navigation (inspect existing modal patterns; default to a dispatched event rendering a dismissible inline alert)
- [x] 2.4 Add feature test: successful completion dispatches `purchaseReceivingCompleted` and the purchase table re-renders showing the purchase as `RECEIVED`

## 3. Purchase correction page rebuild

- [x] 3.1 Rebuild `Modules/Purchase/Resources/views/corrections/edit.blade.php` markup with CoreUI cards, `form-group`/`form-control` inputs, and standard `btn` buttons, preserving all element `id`/`name` attributes and the `.correction-input` class used by the JS
- [x] 3.2 Replace `alert()` success/error dialogs with the app's standard flash-on-redirect (success) and inline alert region (errors)
- [x] 3.3 Debounce `input`-driven preview requests (~400ms) while keeping immediate refresh on payment-select `change`
- [x] 3.4 Manually verify preview and submission flows still work for zero-, single-, and multi-payment purchases (existing feature tests for the correction workflow must keep passing)

## 4. Pelunasan summary card scaling fix

- [x] 4.1 Remove the legacy `/100` division in the local-mode branch of `Modules/Purchase/Livewire/PurchaseSummaryCards.php::getPelunasanProperty()`
- [x] 4.2 Add a test asserting the local-mode Pelunasan card total equals the stored rupiah payment sum

## 5. Verification and spec sync

- [x] 5.1 Grep the touched views for remaining BS5-only tokens (`form-select`, `btn-close`, `data-bs-`, `visually-hidden`, `form-switch`, `fs-[1-6]`, `bg-opacity-`) and confirm none remain
- [x] 5.2 Run focused test suites: global purchase/sale payment filters+tables, purchase correction workflow, receiving completion guards/service, summary cards
- [x] 5.3 Sync delta specs to `openspec/specs/` (including establishing the missing `partial-purchase-receiving-completion` main spec in Purpose + Requirements format)
