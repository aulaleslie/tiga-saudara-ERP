## Why

An authorized blind editor cannot save a destination-less transfer after adding a destination when the draft contains serialized products. The edit projection correctly omits protected serial tax/condition provenance and stock buckets, but form validation then treats those omitted values as non-tax/zero stock; independently, the nested destination dropdown can accept a selection that is absent again from the subsequent parent save snapshot.

## What Changes

- Preserve an accepted destination selection through the real nested Livewire dropdown-to-parent save sequence.
- Treat blind serialized rows as identity-only operator intent rather than inferring tax, condition, allocation, or availability from omitted client fields.
- Rehydrate every selected serial and compute its authoritative provenance and stock allocation on the server at save and submission boundaries.
- Keep lightweight client-facing validation for requested quantity and distinct serial count without requiring protected stock or allocation state.
- Preserve the existing privileged edit experience and all tenant, lifecycle, location, condition, custody, duplication, and availability enforcement.
- Add focused Livewire regressions for destination-less serialized drafts edited by blind and privileged users.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `stock-transfer-entry-scanning`: Strengthen saved-draft editing so a destination selected through the nested component remains authoritative through save, including for existing serialized rows.
- `stock-transfer-system-stock-visibility`: Require blind serialized mutations to rehydrate protected serial provenance and allocation exclusively from authoritative server records instead of interpreting omitted fields as values.

## Impact

- Affects the transfer Livewire form, destination dropdown integration, serialized-row validation/preparation, and focused Livewire tests.
- Reuses existing transfer draft services and authorization boundaries; no database migration, route change, new permission, inventory mutation, or legacy data repair is required.
- Existing transfer 4 data is valid and needs no correction.
