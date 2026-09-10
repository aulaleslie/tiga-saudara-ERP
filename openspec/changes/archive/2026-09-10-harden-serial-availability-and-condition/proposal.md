## Why

Stock Opname can now mark an omitted serial as `MISSING` and can retain a physically present broken serial as `ACTIVE` with `is_broken = true`, but downstream product, report, autocomplete, transfer, and POS queries use inconsistent definitions of availability. This currently makes missing serials appear as `Siap Jual` and can allow active-but-broken serials into sales selection, so availability and user-facing condition labels must be standardized before these states are relied on operationally.

## What Changes

- Define one canonical separation between serial lifecycle status and physical condition: lifecycle status determines whether a serial remains operationally available, while `is_broken` distinguishes sellable from broken inventory.
- Add reusable Eloquent query scopes for available, sellable, and available-broken serials, including an explicit decision for legacy `NULL` status rows.
- Exclude `MISSING`, `SOLD`, `RETURNED`, `RETURN_IN_PROCESS`, dispatched, and return-in-process serials from operational availability while retaining them in history and explicit unavailable views.
- Correct Product Detail tabs and Bahasa Indonesia labels so sellable, broken, missing, returning, and historical serial states are not conflated; an `ACTIVE` broken serial is presented as available broken inventory, not as ready for sale.
- Correct Stok Lintas Bisnis serial counts and dialogs so they reconcile with good/broken `ProductStock` buckets and never classify missing serials as `Siap Jual`.
- Harden POS serial search, exact scan, cart assignment, bundle-component assignment, preflight, and locked final posting so only active, good-condition, undispatched serials can be sold.
- Apply the same canonical availability rules to genuine operational autocomplete and stock-transfer selectors while preserving audit/history and return workflows that intentionally need unavailable serials.
- Add focused automated verification and a Bahasa Indonesia human browser checklist; no full-suite or automated browser run is planned.

## Capabilities

### New Capabilities
- `serial-availability-and-condition`: Canonical lifecycle/condition semantics, reusable query scopes, Product Detail presentation, and operational-consumer rules for available, sellable, broken, missing, and historical serials.

### Modified Capabilities
- `cross-business-stock-inventory`: Require Good/Bad serial dialogs and counts to use canonical sellable/available-broken rules and exclude unavailable lifecycle states.
- `pos-checkout-serial-stock-validation`: Require every POS selection and posting boundary to reject missing, broken, returning, dispatched, sold, or otherwise unavailable serials.
- `stock-transfer-entry-scanning`: Require scanner and selector paths to use canonical availability and the explicitly selected good/broken transfer mode without admitting missing serials.

## Impact

- Affects `ProductSerialNumber`, Product Detail Livewire tables, Stok Lintas Bisnis query/display code, generic serial autocomplete, POS scan/cart/preflight/posting services, and stock-transfer serial resolution.
- Existing Stock Opname persistence and approval results remain unchanged; `MISSING` continues to retain its last location as provenance while being operationally unavailable.
- Purchase-return, sales-return, history, and audit screens must be audited but changed only when they incorrectly act as availability selectors.
- No new external dependency is required. Verification is limited to focused model, Livewire, report, POS, transfer, and regression tests, with browser execution performed manually.
