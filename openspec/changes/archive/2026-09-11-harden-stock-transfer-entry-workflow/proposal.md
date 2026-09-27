## Why

The stock-transfer form currently requires both locations and immediately creates a pending request, while product entry remains visible before an origin is selected and unrelated destination changes can discard entered rows. This makes incomplete operational preparation difficult and creates avoidable risks of choosing products from the wrong stock context or losing work when configuring a transfer.

## What Changes

- Introduce an explicit new-transfer draft workflow: users can save a transfer with an origin, mode, and valid product rows while leaving the destination empty, then submit it for approval only after selecting a valid destination.
- Require an active, tenant-owned origin before product search, barcode scanning, serial selection, or row entry becomes available.
- Replace the transfer location inputs with the searchable stock-opname dropdown interaction, extended for transfer-specific origin scope, cross-business destination scope, active-location filtering, origin exclusion, and business-aware labels.
- Maintain one explicit transfer mode—good stock or breakage stock—across the form and persisted draft; changing mode clears product rows so stock conditions cannot be mixed accidentally.
- Clear product rows and reset destination when origin changes, but preserve product rows when only the destination changes.
- Adapt transfer product entry to the hardened searchable/scannable patterns used by stock adjustment and breakage while retaining transfer-specific stock allocation, conversion, serial, and availability rules.
- Separate draft-save and submit-for-approval actions, with authoritative validation appropriate to each transition and focused automated coverage of the changed behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `stock-transfer-entry-scanning`: Change transfer creation from immediate pending submission to an explicit draft/submit workflow; add origin-gated entry, searchable transfer-aware location selection, single-mode enforcement, and deterministic row resets for origin and mode changes.

## Impact

- Affects stock-transfer Livewire form, location selection, product search/table components, form-state mapping, draft and lifecycle services, controller compatibility paths, and focused transfer feature/Livewire tests.
- Requires an additive migration so draft transfers can have a nullable destination and can persist their selected transfer mode.
- Reuses the stock-opname location dropdown and adjustment/breakage product-entry conventions, extending shared components only where transfer-specific filtering and event isolation require it.
- Preserves existing approval, dispatch, receipt, inventory allocation, serial availability, tax provenance, cross-tenant return, permission, and audit-history behavior unless stricter submission validation is required to protect the new draft boundary.
