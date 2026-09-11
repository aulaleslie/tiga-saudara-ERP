## Why

The stock-transfer workflow currently exposes exact system stock, bucket allocations, serial provenance, and movement expectations through rendered pages, Livewire public state, and browser-facing errors to any user who can operate the workflow. Floor staff must be able to create and collaboratively edit transfers without receiving system-derived stock information that could be used to tailor entries or conceal discrepancies.

## What Changes

- Add the centrally managed `stockTransfers.view-system-stock` permission and use the existing application authorization flow, including the global Super Admin bypass.
- Preserve existing transfer workflow permissions and allow any authorized editor to see and revise all operator-entered values in an editable transfer draft, regardless of which authorized editor entered them.
- Provide blind and privileged server projections for transfer create, edit, detail, and existing dispatch, receipt, return, rejection/correction, and allocation-drift surfaces.
- Omit exact stock quantities, stock buckets, system-computed allocations, serial stock/tax provenance, approved movement expectations, and other protected values from unauthorized HTML, Livewire state and payloads, session/browser data, and browser-facing logs or exports in scope.
- Keep authoritative stock, allocation, conversion, condition, and serial validation on the server while returning neutral, non-quantitative feedback to blind operators.
- Add focused automated coverage for permission behavior, rendered output, Livewire payload leakage, crafted mutations, and privileged compatibility; browser behavior will be verified manually.

## Capabilities

### New Capabilities
- `stock-transfer-system-stock-visibility`: Defines the transfer-specific permission, protected system-derived information, collaborative draft visibility, blind and privileged projections, neutral feedback, and Super Admin behavior.

### Modified Capabilities
- `stock-transfer-entry-scanning`: Makes allocation previews and revealing validation feedback conditional on stock-visibility authority while preserving scanner-first entry and authoritative server validation for blind operators.

## Impact

- Central permission registry and its existing database synchronization and role-management UI.
- Stock-transfer Livewire form, product search, product table, serial-selection data, and edit-state mapping.
- Transfer controller/view projections and existing lifecycle feedback, including dispatch allocation-drift responses.
- Focused stock-transfer feature and Livewire tests. No movement-document redesign, inventory mutation change, PKP route-policy change, or new recount workflow is included.
