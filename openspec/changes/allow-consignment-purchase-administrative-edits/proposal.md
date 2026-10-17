# Proposal

## Why

Consignment-generated Purchases block routine administrative corrections that standard received Purchases support. Operators need to maintain dates, invoice references, notes, and attachments after conversion without reopening commercial terms or changing consignment source evidence.

## What Changes

- Allow reporting-date overrides and due-date adjustments using standard Purchase permissions, validation, audit history, and report semantics, including applicable Global Purchase Payments surfaces.
- Treat the displayed document date according to the existing standard reporting-date mechanism; preserve the original transaction date rather than introduce a separate date rewrite workflow.
- Allow editing notes, supplier purchase numbers, and tax invoice numbers under existing Purchase rules.
- Preserve attachment upload support and allow deletion under standard Purchase rules, including attachments originally copied from consignment billing.
- Apply these capabilities to existing and future consignment-generated Purchases; explain the administrative editing scope in the UI.
- Preserve original confirmation snapshots, conversion audit, allocation lineage, commercial amounts, and inventory behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `consignment-supplier-billing`: Permit standard Purchase administrative maintenance after conversion while retaining commercial and lifecycle protections.

## Impact

- Purchase header immutability guard, source guard, Purchase date policies, shared date adjustment services, and inline metadata editors.
- Purchase detail and applicable global payment views, attachment deletion, and consignment informational copy.
- No new permissions, database migration, commercial edit workflow, or source-history backfill is expected.
- Verification is limited to focused affected-area Laravel tests, OpenSpec validation, and diff checks; no full suite run is planned.
