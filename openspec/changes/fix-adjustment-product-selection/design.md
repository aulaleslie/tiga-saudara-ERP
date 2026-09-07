## Context

Adjustment create/edit and breakage create/edit embed `purchase.search-product`. Its `selectProduct()` dispatches `productSelected` with `->to(ProductCart::class)`, which resolves to `purchase.product-cart`. The adjustment listeners are therefore bypassed. The Alpine search clears its results before invoking the selection method, leaving an empty table without a visible selection outcome.

Read-only investigation against `.env`-configured MySQL exposed by `mysql-local` found the reported ACER ASPIRE AL14 product (ID 3808, SKU-5CA877B8), stock-managed with aggregate stock 25. Serial tracking is disabled. The emitted event targeted the purchase cart; invoking the adjustment table listener directly with this product and location 1 successfully added a row. This is an event delivery defect; the reproduction IDs are evidence, not implementation or test constants.

## Goals / Non-Goals

**Goals:**
- Restore product additions on all four affected forms.
- Preserve default purchase selection routing and the current product payload.
- Retain the tables' location prerequisite, duplicate protection, stock initialization, and serial handling.
- Verify the fix with focused automated checks and a local browser smoke check.

**Non-Goals:**
- Change product search filters, APIs, pricing, stock posting, or adjustment submission rules.
- Correct database records, add migrations, or redesign the search UI.
- Run the full test suite.

## Decisions

### Configure the recipient in the reused search component

Add an optional recipient setting, `selectionTarget`, initialized through the component's existing mount method with `ProductCart::class` as the default. Preserve `selectedSettingId` initialization and existing callers. Use a Livewire `#[Locked]` public string property initialized at mount to retain the recipient across requests while preventing client-side changes. Dispatch the unchanged `productSelected` payload to this recipient.

An unrestricted broadcast would reach unrelated listeners if components coexist. A separate adjustment search would duplicate the existing search UI and API integration. Explicit routing keeps the existing targeted behavior and limits the change to the missing configuration.

### Bind the recipient on each affected page

Pass `AdjustmentProductTable::class` from `Modules/Adjustment/Resources/views/create.blade.php` and `edit.blade.php`. Pass `BreakageProductTable::class` from `create-breakage.blade.php` and `edit-breakage.blade.php`. Use Blade's bound `selection-target` attribute with fully qualified classes. Purchase callers omit this setting and retain their current target.

The receiving tables remain responsible for selection guards and row initialization. No payload transformation or listener rewrite is needed.

### Verify the complete selection boundary with focused checks

Use isolated fixtures and the project's existing Livewire test conventions. Check default purchase dispatch and both configured dispatch targets, including unchanged payload fields needed by the tables. Check each of the four forms renders the correct recipient configuration so component-only tests cannot miss absent page wiring. Exercise receiving tables with a selected location, no location, and a duplicate product; assert resulting rows and existing feedback. Verify serial-required payload preservation without expanding into serial inventory lifecycle tests.

Run only the new focused test class(es) and any directly relevant existing selection tests with `php artisan test` and explicit paths or filters, using an isolated test database. A local browser smoke check selects the reported ACER product on `/adjustments/create` after selecting its location and confirms a visible row without submitting the adjustment. Tests must not reset or mutate the user's `mysql-local` data.

## Risks / Trade-offs

- [A page is missed] → Cover all four page bindings in focused regression checks.
- [Recipient configuration changes after Livewire hydration] → Use a locked public property and verify selection on a subsequent Livewire request.
- [Purchase behavior regresses] → Keep the purchase target as the default and assert its dispatch contract.
- [Direct listener checks pass despite broken browser wiring] → Verify dispatch target and rendered page configuration, then perform the local browser smoke check when available.

## Migration Plan

Deploy the component and four view changes together using the existing application deployment process. No data migration or backfill is needed. Roll back these code changes together if routing regresses.

## Open Questions

None blocking implementation.
