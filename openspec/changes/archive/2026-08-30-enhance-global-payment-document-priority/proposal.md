## Why

Users selecting receivables and payables in Global Payment cannot see the transaction header note beside the document identity, even though that note often contains the context needed to identify the correct invoice. The allocation form also presents candidates in an incidental order, so the document used to enter the workflow can be displaced instead of remaining the primary payment target.

## What Changes

- Display each sale or purchase transaction header note directly beneath its document number in the Global Payment workspace and payment-allocation form.
- Preserve text search by transaction header note in the standalone and embedded Global Payment lists.
- Pin the document used to open a Global Payment allocation form to the first row.
- Sort all remaining eligible documents by due date ascending with deterministic tie handling and undated documents after dated documents.
- Apply the same presentation and ordering rules to both sales and purchase Global Payment workflows without changing eligibility, balances, default allocation amounts, or payment submission behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `global-sales-multi-payment`: Expose searchable sale header notes beneath document numbers and prioritize the entry sale above due-date-ordered allocation candidates.
- `global-purchase-multi-payment`: Expose searchable purchase header notes beneath document numbers and prioritize the entry purchase above due-date-ordered allocation candidates.

## Impact

- Affects the shared Livewire sales and purchase tables only when rendered in Global Payment mode, including their customer/supplier embedded workspaces.
- Affects sales and purchase Global Payment candidate queries and allocation-table Blade templates.
- Requires no database migration, route change, API change, new dependency, or payment-ledger change.
- Verification is limited to focused Global Payment list rendering/search and allocation candidate ordering tests for the touched sales and purchase flows.
