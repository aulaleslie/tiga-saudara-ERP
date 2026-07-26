## Context

Monetary storage in this codebase uses three distinct conventions that have accumulated over time:

| Convention | Where | Mechanism |
|---|---|---|
| Decimal rupiah | `sales`, `sale_details`, `sale_payments`, `sale_returns`, `purchases`, `purchase_details` | `decimal(15,2)`, no conversion |
| Cents | `purchase_payments`, `expenses`, `sale_return_payments`, `products` | `integer` + `×100`/`÷100` mutators |
| Minor units | POS `*_minor_units` columns | `integer`, explicit column naming, converts at boundaries |

Two migrations already moved tables from cents to decimal — `sale_payments` (2025-04-04) and `sale_returns` (2025-10-05, which included `DB::raw('col / 100')` backfills). Neither finished the job, and the half-migrated state has produced defects rather than merely inconsistency.

Three failure modes exist today:

1. **Missing setters.** `Quotation` and `QuotationDetails` define 13 `÷100` accessors with no matching mutators. Writes store rupiah; reads divide by 100. Every quotation value is wrong by 100×.
2. **Mixed units in one column.** `purchase_returns` and `purchase_return_payments` are `integer` columns with **no** mutators. Older rows hold cents, newer Livewire-era rows hold rupiah. Four report services reconstruct each row's unit at read time using `withExists('is_livewire')`, `PAY-RET/` reference prefixes, and creation timestamps.
3. **Accessor bypass.** `withSum`, `->sum('amount')`, `DB::table()`, and raw SQL read columns directly, skipping accessors. Each such site carries a hand-maintained `/100` — and some are inconsistent with their neighbours (`OperationalCashFlowReportService` divides in three methods but not in `getSalePayments`, correctly, because `sale_payments` is already decimal).

The deployment context is decisive: the application moves to a **fresh database populated by import after this change lands**. No production rows survive, so schema conversion carries none of its usual data-migration risk.

## Goals / Non-Goals

**Goals:**

- One monetary convention outside POS: `decimal(15,2)` storing rupiah.
- Eliminate all `×100` / `÷100` conversion for the affected entities — in mutators, scopes, aggregations, and raw SQL alike.
- Fix the Quotation read/write asymmetry (a correctness bug, not a refactor).
- Delete the legacy/Livewire unit-guessing heuristics, which become both unnecessary and actively wrong once units are uniform.
- Preserve every reported figure. The operational reports must produce identical output.

**Non-Goals:**

- **POS `*_minor_units` is untouched.** That convention is deliberate, self-documenting via column names, internally consistent, and converts at well-defined boundaries. Folding it into this change would multiply risk for no benefit.
- No changes to report requirements, filters, presentation, or export formats.
- No data backfill logic beyond what a fresh database requires.
- No introduction of a money value-object or currency abstraction. That is a larger design question; this change only unifies the storage unit.

## Decisions

### Decision 1: Convert storage rather than add mutators everywhere

**Chosen:** change columns to `decimal(15,2)` and delete the mutators.

**Alternative considered:** keep cents storage and add the *missing* mutators (fixing Quotation, adding them to `purchase_return*`).

Rejected because it preserves the accessor-bypass hazard. Every `withSum`, `DB::table()`, and raw-SQL read would still need a hand-maintained `/100`, which is precisely the class of error being eliminated. Making storage and the in-memory representation identical removes the failure mode structurally instead of requiring discipline at each call site. It also aligns the remaining tables with the majority that are already decimal.

### Decision 2: Migrations include `/100` backfills despite the fresh database

Each conversion migration follows the proven 2025-10-05 pattern: `->decimal(15,2)->change()` plus a `DB::raw('col / 100')` backfill.

On a fresh database the backfill is a no-op. It is included anyway because migrations must remain correct if run against a populated database — a developer machine restored from a snapshot, or a staging environment. Omitting it would make the migration silently destructive in exactly those cases. The cost is a few lines; the benefit is a migration that is unconditionally correct.

`purchase_returns` / `purchase_return_payments` are the exception: their rows have **mixed** units, so no single backfill expression is correct. Those migrations convert the column type without a backfill and document why. This is safe only because the target database is fresh — recorded here as an explicit constraint, not an oversight.

### Decision 3: Remove unit heuristics rather than adapt them

The `is_livewire` / reference-prefix / timestamp logic exists solely to disambiguate mixed-unit rows. Once every row is rupiah, the heuristic would misclassify legacy-looking rows and divide correct values by 100. It cannot be left in place, and adapting it would preserve dead complexity.

Deleting it removes the loops in `OperationalBalanceSheetReportService`, `OperationalMovementEventService`, and `OperationalCashFlowReportService` that fetch payment rows individually to inspect them, replacing per-row inspection with plain aggregate sums. This is a meaningful simplification and a performance improvement as a side effect.

### Decision 4: Sequence by risk, verify per group

Order: Quotation → `sale_return_payments` → `purchase_return*` → `purchase_payments` → `expenses` → `products`.

Rationale — the first two are small and self-contained, establishing the pattern. `purchase_return*` removes the heuristics that would otherwise interfere with later verification. `purchase_payments` unblocks the dependent Pembayaran Penjualan Global work. `products` is last and largest: ~72 non-test references spanning POS pricing, imports, unit-conversion pricing, average-cost recalculation, and valuation reports.

`products` deserves particular care because POS reads `product_price` and immediately multiplies by 100 to reach minor units (`PosCartService::1288-1291`). Those boundary conversions consume the *accessor* value, which is rupiah both before and after this change — so they should need no edit. Every such site is verified individually rather than assumed.

### Decision 5: Existing report tests are the safety net

The operational report specs are unit-agnostic and their tests assert concrete totals. Since this change must not move any figure, those tests failing means the refactor is wrong. `composer test:fresh-sqlite` runs against a fresh database — the exact production scenario — so the suite is a true signal rather than an approximation.

## Risks / Trade-offs

**Silent 100× errors are quiet, not loud** → A missed `/100` removal understates a balance by 100×, and in the eligibility-filter case (`live_due_amount > 0`) the symptom is rows *disappearing* from a list rather than showing a wrong number. Mitigation: enumerate every conversion site up front (they are listed in the proposal's Impact section and in tasks), remove them per entity group, and run the report test suite after each group rather than only at the end.

**`products` has the widest reach** → Average-cost recalculation, inventory valuation, POS pricing, unit-conversion pricing, and three import services all read `product_cost` / `product_price`. Mitigation: sequence it last, after the pattern is established and the test suite is known-green; treat each POS boundary conversion as an individual verification rather than a bulk edit.

**Mixed-unit tables cannot be safely backfilled** → `purchase_return*` migrations are correct only against an empty table. Mitigation: document the constraint in the migration itself so anyone running it against real data is warned; the fresh-database deployment plan is what makes this acceptable.

**Import ordering is load-bearing** → Imports write through Eloquent, so they inherit whatever convention the entities use at run time. Running an import before this change lands would store cents into columns that are about to be reinterpreted as rupiah. Mitigation: imports run only after this change is deployed, as stated in the proposal.

**Trade-off accepted: no money value object** → Uniform decimal storage still permits float-arithmetic rounding drift. Introducing a money abstraction would address that more thoroughly but is a substantially larger change touching every arithmetic site. Unifying the unit is the prerequisite for that work and is valuable on its own; the broader question is deliberately deferred.
