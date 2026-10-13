# Proposal

## Why

Expense approval can appear to do nothing for a non-admin user until a five-minute cache claim expires. Approval forms submit the same body for different expenses, while the current idempotency fallback omits the expense ID from its key; a rejected request redirects back with an error the expense screens do not display.

## What Changes

- Scope expense approval idempotency to the target expense and user so approvals of distinct submitted expenses can proceed consecutively.
- Keep protection against repeated or concurrent approval of the same expense, and release a claim when approval fails before completion.
- Show a clear message when an approval request is rejected as a duplicate.
- Add focused approval-flow verification only; do not require a full test-suite run.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `expense-approval-workflow`: Define distinct-expense approval behavior, duplicate request handling, failure recovery, and visible feedback.

## Impact

The expense approval route/form, idempotency claim lifecycle, expense review/list feedback, and focused feature tests are affected. No database migration or external API change is expected. The implementation should keep other routes' idempotency behavior unchanged.
