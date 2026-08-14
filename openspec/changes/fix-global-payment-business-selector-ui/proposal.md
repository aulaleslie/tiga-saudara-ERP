## Why

The multi-business filters on the Global Sales Payment and Global Purchase Payment pages use a native Bootstrap 5-style multi-select that is visually broken in the application's CoreUI/Bootstrap 4 interface. The existing Laporan Laba Rugi business selector already provides a clear Select2/CoreUI pattern that should be reused while retaining Global Payment's established draft, apply, reset, and URL-restoration behavior.

## What Changes

- Replace the native multi-business controls on both Global Payment pages with consistently styled searchable Select2/CoreUI multi-selects based on the Laporan Laba Rugi selector pattern.
- Keep selector changes in draft state until the user selects `Terapkan Filter`.
- Restore selected businesses visibly from URL-backed applied state after a page refresh.
- Ensure `Reset semua filter` clears both Livewire filter state and the client-side selector display.
- Preserve the existing meaning of an empty selection as all businesses and keep table and summary-card filtering aligned.
- Reuse a configurable selector integration across Global Sales Payment and Global Purchase Payment rather than duplicating divergent JavaScript behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `global-sales-multi-payment`: Require a searchable, CoreUI-compatible multi-business selector whose visible state follows the existing draft/apply/reset and URL-restoration lifecycle.
- `global-purchase-multi-payment`: Require a searchable, CoreUI-compatible multi-business selector whose visible state follows the existing draft/apply/reset and URL-restoration lifecycle.

## Impact

- Affects the Livewire Blade views for the global sales and purchase payment tables and their business-selector integration.
- May generalize or extract the existing report business-source Select2 partial so it can bind to a configurable Livewire property without changing report behavior.
- Uses the Select2 and CoreUI theme assets already loaded globally; no new package, API, database schema, permission, or route changes are required.
- Requires focused tests for selector rendering, draft/application semantics, restored selections, visible reset behavior, and unchanged table/summary filtering contracts.
