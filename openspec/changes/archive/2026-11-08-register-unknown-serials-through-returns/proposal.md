# Proposal

## Why

Products sold without recorded serials may now require serial tracking, preventing customers from returning legitimate older goods through Sales Return or POS Return. Staff need to register the physical returned serial through receiving while retaining existing serial lookup, original sale history, and quantity limits.

## What Changes

- Extend the existing serial entry/lookup surfaces with proposed unknown serials after a source line is selected; retain all existing checks for registered serials.
- Permit this extension only for source fulfillment occurrences without recorded serials, including bundle components, when the product currently requires serial tracking. Historically serialized occurrences must still match their recorded serials.
- Require one normalized, distinct serial per received unit and validate remaining entitlement against the original fulfillment quantity.
- Persist proposed text in drafts without creating serial inventory. Register serials atomically when physical receiving commits; stop for review if a serial has since been registered.
- Preserve standard held custody, POS good/bad disposition, owner/location/tax identity, and subsequent customer-held serial lineage without creating extra entitlement.
- Keep currently non-serial products on their existing quantity-only flow and preserve existing permissions and workflow ownership.

## Capabilities

### New Capabilities

- `return-serial-registration`: Registration of previously unknown serials through source-linked Sales Return and POS Return receiving, including eligibility, draft entry, atomic registration, custody, and subsequent occurrence tracking.

### Modified Capabilities

None. The new capability extends the existing workflow, custody, and POS serial-claim contracts without replacing their requirements; its scenarios explicitly cover their interaction with newly registered serials.

## Impact

- Standard return Livewire forms, serial loader, eligibility/claim services, receiving custody, settlement coverage, inspection and dispatch.
- POS return forms, snapshots, submission, approval preview/plan persistence, lifecycle receiving, bundle expansion, quantity guards, and serial replacement chains.
- Additive persistence for historical tracking classification, pending serial text, receiving registration evidence, and quantity-to-serial occurrence attribution. Reuse ProductSerialNumber normalization and product-scoped uniqueness.
- Focused Laravel/Livewire feature tests and database race/rollback coverage. No new external dependency, historical sale rewrite, broad stock reconciliation, or implementation in this planning change.
