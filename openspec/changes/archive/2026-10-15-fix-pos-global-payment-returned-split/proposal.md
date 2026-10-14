# Proposal

## Why

A POS return can reduce one generated split Sale to zero quantity and zero bill while another split Sale still has outstanding debt. Global POS payment currently rejects the entire transaction when the zero-value Sale is fully returned or archived, preventing collection of the remaining valid debt.

## What Changes

- Permit a fully returned split Sale with zero total and zero canonical live due to remain linked without blocking payment of eligible sibling Sales, including when that returned Sale is archived.
- Make the exception available once return quantity and financial corrections are committed, without waiting for return completion or archival.
- Apply one consistent rule to register payment actions, candidate selection, form access, preview, and locked submission; allocate no new payment to the exempt Sale.
- Preserve rejection of invalid mappings, non-exempt ineligible Sales, overpayments, and invalid concurrent changes.
- Verify the change using focused projection, payment service, and route/Livewire regression tests only.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `global-pos-multi-payment`: Allow a financially cleared, fully returned split Sale to coexist with payable sibling Sales throughout global POS payment eligibility and settlement.

## Impact

Affected areas include `PosSettlementProjectionService`, `GlobalPosMultiPaymentService`, global POS payment controller/Livewire consumers, and their focused tests. Existing Sale payment ledger, owner priority, authorization, canonical settlement data, and audit links remain authoritative. No database migration, historical backfill, or return/archive lifecycle modification is planned.
