## Purpose

Provide consistent current outstanding balances for purchase and sales documents across operational and Global Payment lists and detail pages.

## Requirements

### Requirement: Outstanding balances follow active settlement evidence
Purchase and sales outstanding amounts SHALL be the nonnegative difference between the current document total and effective active settlement. Sales settlement SHALL include customer-credit applications attached to active payments. Invalidated payments and their associated credits MUST NOT reduce outstanding. Existing currency precision and storage conversions SHALL apply exactly once.

#### Scenario: Stale header differs from active payments
- **WHEN** a purchase or sale totals Rp1.000.000, active settlement is Rp400.000 and stored outstanding is Rp900.000
- **THEN** its displayed outstanding is Rp600.000 regardless of the stored header balance

#### Scenario: Sales credit reduces outstanding
- **WHEN** a sale totals Rp1.000.000 with Rp300.000 active monetary payments and Rp200.000 credit applied through active payments
- **THEN** its outstanding is Rp500.000
- **AND** credit associated only with invalidated payments is excluded

#### Scenario: No active payments or fully settled document
- **WHEN** a document has no effective active settlement
- **THEN** outstanding equals its nonnegative current total
- **AND** when effective settlement meets or exceeds the total, outstanding is zero rather than negative

#### Scenario: Settlement changes after a page was opened
- **WHEN** an existing authorized workflow posts, deletes or invalidates payment evidence, applies credit or changes the document total
- **THEN** the next page load or table refresh reflects the resulting live balance
- **AND** automatic background refresh is not required

### Requirement: Detail pages expose outstanding beneath document totals
Normal and Global Payment purchase and sales detail pages SHALL show outstanding immediately beneath Total Keseluruhan. Purchases SHALL label it Sisa Pembayaran and sales SHALL label it Sisa Tagihan. Each SHALL use the detail page's existing currency formatting and show zero explicitly for settled documents.

#### Scenario: Open detail through either entry point
- **WHEN** an authorized user opens the same purchase or sale through its normal list or Global Payment list
- **THEN** the detail summary shows the same current outstanding under the document total
- **AND** no payment-create permission is required merely to see that balance

### Requirement: List rows agree with detail outstanding
Normal and Global Payment purchase and sales list rows SHALL display the same live outstanding as their respective detail pages. Supplier/customer embedded workspaces reusing those lists SHALL follow the same rule. Existing row labels and currency formatting SHALL remain consistent with their current interfaces.

#### Scenario: One document appears in multiple contexts
- **WHEN** the same eligible document is shown in a normal list, Global Payment list and embedded supplier/customer list with unchanged settlement evidence
- **THEN** each row and its detail page display the same outstanding despite stale stored balances

### Requirement: Balance-based list behavior uses displayed outstanding
Existing outstanding-only and overdue balance predicates SHALL use live outstanding in normal and Global Payment tables. Existing outstanding-column sorting SHALL order by that same nonnegative amount before pagination, retaining deterministic identifier tie-breaks. Existing non-balance constraints SHALL still apply; this change MUST NOT add new sort controls or redefine summary-card metrics.

#### Scenario: Stored balance incorrectly reports paid or unpaid
- **WHEN** outstanding-only or overdue filtering is applied
- **THEN** a document with a positive live balance is eligible for the balance predicate even if stored outstanding is zero
- **AND** a document with zero live balance fails that predicate even if stored outstanding is positive
- **AND** overdue filtering still requires the existing past-due-date condition

#### Scenario: Outstanding sort crosses pagination boundaries
- **WHEN** a user applies an existing outstanding sort to documents whose stored balances order differently from their live balances
- **THEN** ascending and descending ordering follow live amounts across all pages
- **AND** equal displayed balances use the existing identifier tie-break direction

### Requirement: Balance presentation preserves financial and access boundaries
Reading or filtering balances SHALL NOT reconcile headers or mutate payments, credits, totals, returns or snapshots. Normal business isolation, authorized global access, archive visibility, workflow eligibility and explicit non-balance filters SHALL remain enforced. Balance loading SHALL avoid additional settlement queries per rendered row.

#### Scenario: Viewing stale financial headers
- **WHEN** an authorized user reads a list or detail page with a stale stored balance
- **THEN** the live balance is presented without updating stored financial records

#### Scenario: Business and global eligibility remain enforced
- **WHEN** normal or Global Payment pages are requested
- **THEN** existing access checks and context-specific document restrictions determine which documents can be read
- **AND** changing balance calculation does not expose another business or make an ineligible document payable

#### Scenario: Larger page contains more invoices
- **WHEN** the number of rows on one table page increases
- **THEN** settlement-query count does not increase by an additional query for every row

