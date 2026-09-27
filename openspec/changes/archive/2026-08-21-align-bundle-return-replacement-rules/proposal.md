## Why

POS Return currently blocks every component-only bundle action and automatically moves stock for non-serial replacements. Business policy instead permits independent parent or component replacement, prohibits independent bundle refunds, and requires automated physical movement only when serial identity can prove the returned and replacement units.

## What Changes

- Require a cash refund for bundled merchandise to return the selected whole-bundle quantity: parent plus every originally fulfilled component.
- Continue blocking cash refunds for a bundle parent alone or a bundle component alone.
- Permit independent product replacement of either the bundle parent or an individual bundle component.
- For serial-tracked replacement, require returned and replacement serial identity, receive the original serial into its source owner/location as immediately active/saleable stock, dispatch the replacement serial, and preserve exact lineage and HPP effects.
- For every non-serial replacement, including ordinary products and bundle parents/components, persist an approved replacement note/audit trail only; do not create receiving, dispatch, stock, inventory-transaction, or HPP effects because staff handles physical exchange and later breakage separately.
- Preserve original Sale revenue, quantity, allocation, tax, and payment for all replacements.
- Add focused coverage for whole-bundle refunds, prohibited partial refunds, serial parent/component replacements, non-serial note-only replacements, cross-owner lineage, retries, and rollback.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-return-draft-resolutions`: Distinguish forbidden bundle cash-refund selections from independently selectable parent/component replacements, and capture serial replacement intent versus non-serial note-only intent.
- `pos-return-approval-preview`: Show whether a replacement will execute serial inventory movement or remain a non-serial note-only action before approval.
- `pos-return-approval-execution`: Enforce whole-bundle refund completeness, allow independent parent/component replacement, execute serial-only replacement movements, and suppress automated physical/HPP effects for non-serial replacements.

## Impact

- Affects POS Return snapshot/eligibility, draft forms and validation, approval planning/preview/persistence, lifecycle execution, serial lineage, dispatch, inventory transactions, and replacement HPP.
- Changes existing non-serial replacement behavior from automatic receiving/dispatch to note-only (**BREAKING** for operators who currently expect automatic stock changes).
- Reuses existing serial, Sale Return, dispatch, inventory, and audit structures; no additional quarantine state is introduced.
- Does not change normal Sales/POS sale posting, bundle pricing, cash-refund amounts, customer payments, or the external breakage workflow.
