## Why

Partial purchase completion currently treats any approved receiving-note header as sufficient evidence of delivery and refuses to delete an unreceived purchase line when that line has a zero-quantity receiving-detail record. Because zero-quantity rows are valid within a receival when at least one other row is positive, this can leave products that were never received on the completed purchase.

## What Changes

- Require shortfall completion to have at least one positive received quantity across approved receiving-note details, rather than relying only on an approved note header.
- Continue allowing zero-quantity rows in a receival when at least one row in that receival has a positive quantity.
- During shortfall completion, retain and normalize purchase-detail rows whose cumulative approved received quantity is positive.
- Remove purchase-detail rows whose cumulative approved received quantity is zero, including rows represented by zero-quantity details in approved receiving notes.
- Preserve the completion audit snapshot and keep previewed retained/removed outcomes consistent with persisted purchase details.
- Add focused regression verification for positive-row eligibility and zero-approved-quantity removal.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `partial-purchase-receiving-completion`: Clarify positive approved-quantity eligibility and require zero-approved-quantity purchase details to be removed even when zero-quantity receiving-detail rows exist.

## Impact

- `Modules/Purchase/Services/PurchaseReceivingCompletionService.php`
- Purchase receiving-completion unit or focused feature tests
- Existing `received_note_details` linked to removed zero-received purchase details through the current cascade behavior
- No new permissions, routes, UI workflows, or external dependencies
