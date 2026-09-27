## Context

Sales import currently reconciles invoice-level payment fields before creating owner documents. That source-invoice phase calculates owner group gross totals, applies document `Diskon` and `Biaya Pengiriman`, resolves `Status Hari Ini`, and allocates paid/due/deduction components across owner groups.

The same owner group is later processed by `processInvoiceGroup()`, which recomputes `totalWithTax` from raw line DPP and tax values, subtracts the allocated document discount, adds shipping and drift, then validates `paid_amount + due_amount == adjustedTotalWithTax`. The two paths round at different moments. A real example is a `Lunas` sales row where `source_total` and `pembayaran` round to `550000.00`, while raw DPP + CSV tax - CSV discount recomputes to `550000.02`; this falsely invalidates the invoice even though source money fields round to a paid document.

Existing requirements already make CSV `Total` authoritative for sales imports when status mapping is available, preserve near-zero scientific notation handling, and require owner-split allocations to balance. This change narrows the implementation so all later persistence and validation consume the canonical totals produced by source-invoice reconciliation.

## Goals / Non-Goals

**Goals:**

- Use one canonical adjusted owner document total for sales import header persistence, payment status, settlement validation, payment rows, and owner-split allocation.
- Accept sub-cent and one-cent source/export precision artifacts when current sales status can derive settlement from source `Total`.
- Preserve strict invalidation for real mismatches: conflicting repeated fields, missing required source totals for status-based reconciliation, over-settlement, and source deltas outside existing precision limits.
- Cover the known regression shape with focused automated tests.

**Non-Goals:**

- No schema changes or historical data rewrites.
- No change to purchase import behavior unless a shared helper must remain backward-compatible.
- No reinterpretation of CSV `Diskon %`; fixed CSV `Diskon` remains authoritative for document discount math.
- No broad import refactor beyond the sales import reconciliation path needed for this fix.

## Decisions

### Decision 1: Source-invoice reconciliation produces canonical owner totals

The sales import source-invoice phase will determine each owner group's final adjusted document total after document discount, shipping, and any accepted source-total precision adjustment. `processInvoiceGroup()` will receive that canonical total, or enough canonical adjustment data to persist exactly that total, and its final validation will compare settlement against the canonical total rather than a separately recomputed raw-total variant.

Rationale: settlement allocation already happens at source-invoice scope. Allowing the persistence phase to derive a different total undermines the source-of-truth rule and creates false mismatches.

Alternative considered: increase the final validation tolerance. This would import the failing row but would also hide genuine two-cent or larger inconsistencies without fixing the inconsistent total model.

### Decision 2: Treat exact one-cent source-total adjustments as allocatable precision drift

Sales import should allocate a one-cent source-total delta when CSV status mapping makes source `Total` authoritative and the delta is within existing precision limits. The current strict `> 0.01` gate leaves an exact one-cent difference unallocated, even though all persisted money is two-decimal and one cent is the smallest possible adjustment.

Rationale: the generated owner totals must sum to source `Total` once the source is authoritative. A one-cent residual is a valid money adjustment, not a reason for divergent downstream recomputation.

Alternative considered: leave one-cent deltas untouched. This preserves current behavior but keeps the known false failure and makes source-total authority incomplete.

### Decision 3: Keep precision limits narrow

The change should keep existing absolute and relative precision drift limits. Rows whose source `Total` is materially inconsistent with line totals after document-level adjustments must still fail.

Rationale: this is a hardening change for export precision artifacts, not a data-cleaning bypass for bad invoices.

Alternative considered: always trust source `Total` for `Lunas` rows. That would be simpler but risks importing documents whose line composition does not reasonably explain the source total.

## Risks / Trade-offs

- [Risk] Canonical total plumbing can diverge from detail row subtotals shown in the UI. -> Mitigation: constrain the adjustment to sale header/document-level total reconciliation and preserve line detail values as imported; add tests proving header/payment totals balance.
- [Risk] Owner-split invoices may allocate a rounding cent to a small or zero-total owner incorrectly. -> Mitigation: reuse existing proportional allocation helpers and preserve zero-total group rules.
- [Risk] Changing one-cent drift handling may alter a small number of previously invalid imports to processed. -> Mitigation: only allow the change when source status mapping and precision limits already establish the source `Total` as authoritative.
- [Risk] Purchase import could be affected if shared helper behavior changes. -> Mitigation: prefer sales-service-local changes or add purchase regression coverage before touching shared helpers.

## Migration Plan

No database migration is required. Deploy code and tests normally. Rollback is the previous import behavior; no persisted data transformation is needed.

## Open Questions

- Should the canonical total be passed as an explicit `allocatedTotal` argument to `processInvoiceGroup()`, or should it be represented as a computed drift adjustment that makes the existing recomputation land on the canonical total? The explicit argument is clearer; the adjustment approach is smaller but keeps two total calculations alive.
