## Why

The current POS transaction flow still couples draft ownership to destructive authority. That blocks the intended handoff workflow because users with `pos.transactions.load` cannot reliably continue each other's drafts, while transaction cancel remains available through owner-based checks even when `pos.void` is not assigned.

## What Changes

- Change POS draft handoff semantics so `pos.transactions.load` means a user may load any mutable POS draft within the same setting, not only drafts they created themselves.
- Remove owner-based draft takeover as the primary runtime rule for POS draft loading, and narrow manager-grade override authority so it no longer acts as the normal collaboration path.
- Introduce explicit POS transaction cancel authority so mutable transaction cancellation requires either direct `pos.void` permission or an approved supervisor authorization token.
- Add a POS transaction cancel approval flow that mirrors the existing clear-cart interaction model: request approval, wait for approval outcome, then continue or cancel the action using the approval token.
- Align transaction list/detail UI, runtime authorization, approval request plumbing, and regression coverage with the new split between collaborative load authority and destructive cancel authority.

## Capabilities

### New Capabilities
- `pos-transaction-cancel-authorization`: define how mutable POS transaction cancellation is authorized directly or through supervisor approval.

### Modified Capabilities
- `pos-transaction-handoff-visibility`: change draft handoff requirements so users with `pos.transactions.load` can load any mutable draft in the current setting for continuation.

## Impact

- Affected code: POS transaction policy/service/controller flow, POS transaction list/detail views, POS supervisor approval request plumbing, and approval action enums/services.
- Affected tests/specs: POS draft handoff tests, transaction cancel tests, role matrix coverage, and any approval-flow tests that need to recognize transaction cancel authorization.
- Affected operations: role assignments using `pos.transactions.load`, `pos.transactions.edit.any`, and `pos.void` will need review so collaboration and destructive authority are separated cleanly.
