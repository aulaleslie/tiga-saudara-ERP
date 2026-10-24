# Proposal

## Why

POS returns can reconstruct untouched rounded checkout lines from truncated unit prices, introducing fractional differences into the sale balance. Transaction 9218 demonstrates a Rp0.40 drift on an untouched marker line; future returns must preserve checkout authority and apply configured rounding only to the return amount.

## What Changes

- Value future cash-return requests from authoritative persisted commercial line totals, including requests against older sales.
- Round cumulative cash-return valuation with the existing half-up rounding calculator; subtract previously committed return amounts to obtain the current return amount.
- Freeze the POS business rounding increment at the first successfully committed cash return for a sale, and reuse it for later partial returns.
- Subtract the committed return amount from the stored balance without independently rounding the remainder or recalculating untouched line prices.
- Use one monetary amount consistently in previews, source-sale reductions, refund/debt settlement, and audit records; final full return consumes the exact residual.
- Preserve historical completed returns and balances, including transaction 9218. No historical repair or backfill.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-return-approval-execution`: Define cumulative rounded cash-return valuation, frozen configuration, exact residuals, consistent settlement, and prospective execution.
- `pos-rounded-amount-consistency`: Permit commercial POS cash-return rounding while retaining reconciliation to captured charges.
- `transaction-row-total-rounding`: Allow explicit configured rounding of POS commercial cash-return amounts while preserving all other return and internal-allocation exclusions.

## Impact

Primarily POS return valuation, snapshot/submission, approval preview, lifecycle correction, and settlement paths in `Modules/Pos`, with additive persistence for rounding context. Reuse `App/Support/RowTotalRoundingCalculator`; keep generic Sale normalization behavior and non-POS returns unchanged. Verify with focused automated cases only; no full-suite test task. Application implementation is outside this proposal step.
