# consignment-stock-buyout Specification

## Purpose

Allow a business to purchase supplier-owned consignment goods before sale, optionally bill sold quantities together, and safely recognize company ownership and supplier liability with auditable receipt provenance.

## Requirements

### Requirement: Buyouts select one supplier and business
A buyout SHALL belong to one supplier and business and SHALL support multiple approved non-reversed receipts, source locations, products, and positive base quantities. Users SHALL select all eligible balances or explicit partial quantities, separately identifying sold-but-unbilled and unsold quantities.

#### Scenario: Buy all remaining eligible goods
- **WHEN** an authorized user selects a supplier and receipts and requests all eligible quantities
- **THEN** the preview SHALL distinguish sold-but-unbilled quantities from physically available unsold quantities and show exclusions and blockers
- **AND** the system SHALL NOT silently substitute suppliers, receipts, locations, or quantities

#### Scenario: Partial purchase spans receipts
- **WHEN** selected quantities use multiple receipts with different supplier costs
- **THEN** each receipt contribution and original commercial evidence SHALL remain separately traceable on the resulting purchase

#### Scenario: Another business or supplier is submitted
- **WHEN** any selected receipt or destination violates the document supplier or business boundary
- **THEN** the entire request SHALL be rejected without data disclosure or mutation

### Requirement: Pooled sales require explicit supplier attribution
Before unsold non-serialized stock can be approved for purchase, all outstanding sold quantities for the affected product and source location SHALL have authoritative receipt attribution. Attribution SHALL be explicit, complete, and capacity-checked; the system SHALL NOT infer supplier ownership through FIFO, LIFO, or current stock totals.

#### Scenario: Other suppliers share a stock pool
- **WHEN** an affected product/location has unallocated dispatched quantities across supplier receipts
- **THEN** unsold approval SHALL be blocked with actionable sales references until their receipt attribution is completed
- **AND** the preparation flow SHALL permit authorized users to resolve that attribution without generating unrelated supplier bills

#### Scenario: Attribution is complete
- **WHEN** every effective sold quantity is attributed without duplicate receipt consumption
- **THEN** the available unsold balance SHALL exclude those sold quantities, prior acquisitions, and active reservations
- **AND** each supplier receipt's available physical quantity SHALL still be capped by the actual unreserved location stock and matching buckets

### Requirement: Serial selection preserves exact identity
Serialized buyout quantity SHALL equal its selected unique product serial count. Each serial SHALL resolve to one eligible receipt and supplier. Unsold serials SHALL be physically present and sellable at the source, with no conflicting allocation, dispatch, transfer, or return-process claim.

#### Scenario: Exact serials are selected
- **WHEN** a user selects three unsold serialized units
- **THEN** approval SHALL reserve precisely three eligible serial identities and their receipt capacity

#### Scenario: Serial evidence is invalid
- **WHEN** any selected serial is missing, duplicated, associated with another product or receipt, unavailable, or claimed elsewhere
- **THEN** the entire approval SHALL fail without partial claims

### Requirement: Approval reserves without acquiring ownership
Buyouts SHALL support draft, waiting approval, approved, rejected, cancelled, finalized, and reversed states. Draft and waiting documents SHALL not reserve resources. Approval SHALL freeze terms and reserve resources atomically without stock, cost, payable, or payment mutation. Rejected drafts SHALL be revisable; approved terms SHALL not be directly editable.

#### Scenario: Approved buyout awaits finalization
- **WHEN** a valid submitted buyout is approved
- **THEN** receipt, sold-source, physical-stock, and serial reservations SHALL become active together
- **AND** goods SHALL remain supplier-owned at their original locations with no Purchase yet created

#### Scenario: Approval fails on a later line
- **WHEN** any line is ineligible or no longer matches submitted evidence
- **THEN** no approval, reservation, or partial state change SHALL persist

#### Scenario: Approval is retried
- **WHEN** approval of an already approved unchanged document is repeated
- **THEN** it SHALL create no duplicate reservations or audit transition

### Requirement: Cancellation and rejection release reservations
Rejection or explicit cancellation before finalization SHALL require a reason and SHALL release every active reservation atomically while preserving the document and audit history. Approved reservations SHALL remain active until finalization or explicit cancellation and SHALL not expire automatically.

#### Scenario: Approved buyout is cancelled
- **WHEN** an authorized user cancels an approved unfinalized buyout
- **THEN** all its quantity and serial reservations SHALL be released without changing stock, cost, supplier liability, or payments

#### Scenario: Reserved document is old
- **WHEN** an approved buyout remains unfinalized for an extended period
- **THEN** its reservations SHALL remain effective and visible for authorized cancellation

### Requirement: Buyout authority is separate from sold-only billing
Selecting, submitting, approving, finalizing, cancelling, and reversing buyouts SHALL enforce action permissions and the accessible active business in both request and domain boundaries. Buying unsold stock SHALL additionally require dedicated buyout authority; sold-only billing authority SHALL not grant it implicitly.

#### Scenario: Sold-only biller attempts an unsold acquisition
- **WHEN** a user has sold-only billing authority but lacks unsold-buyout authority
- **THEN** unsold preparation and mutation actions SHALL be denied without changing resources

#### Scenario: Unauthorized status mutation is attempted
- **WHEN** an unauthorized user directly invokes a buyout or related Purchase status-changing action
- **THEN** the response SHALL be HTTP 403 and document, payment, and inventory state SHALL remain unchanged

### Requirement: Approved invoice terms preserve receipt evidence
Draft pricing SHALL retain receipt commercial snapshots as evidence and accept one product-level set of invoice price, row/global discount, tax and final-row-total controls from tab 3. Original-price disagreements SHALL require explicit invoice price or total entry. All contributing acquisition slices SHALL use the same product financial terms with exact allocated totals. Invoice number, date, due date or payment term, notes, tax reference, and supported attachments SHALL follow existing Purchase validation; the reporting date SHALL start equal to the invoice date and is not a preparation input. Approval SHALL freeze accepted terms separately from original evidence.

#### Scenario: Supplier invoice changes prices
- **WHEN** an authorized preparer adjusts receipt-derived prices and discounts
- **THEN** the preview SHALL calculate final payable and inventory cost server-side and retain original receipt amounts for audit

#### Scenario: Tax policy differs by business
- **WHEN** the business is non-PKP
- **THEN** invoice tax SHALL be zero with tax-included disabled regardless of forged inputs
- **AND** a PKP business SHALL receive validated tax choices and the existing tax-included default

#### Scenario: Approved price is changed at finalization
- **WHEN** submitted terms or row identities differ from the approved revision
- **THEN** finalization SHALL reject the change and require a newly approved document rather than modify accepted terms

### Requirement: Unsold acquisition moves stock within the same business
Every unsold slice SHALL specify an active standard destination belonging to its source business. Finalization SHALL move exact base quantities, condition/tax buckets, and selected serials from consignment to that destination, recording both movement legs and ownership evidence without changing total physical quantity or creating another receiving note.

#### Scenario: Five existing units are bought
- **WHEN** five unsold units move from a consignment location with eight units to a standard location with two units
- **THEN** the locations SHALL contain three and seven units respectively and total physical quantity SHALL remain ten
- **AND** historical receipt and serial provenance SHALL remain available

#### Scenario: Destination changes eligibility
- **WHEN** the destination becomes inactive, consignment-classified, or owned by another business before finalization
- **THEN** finalization SHALL fail without any financial or inventory mutation

#### Scenario: Non-serialized quantity is fractional
- **WHEN** an eligible non-serialized acquisition selects 1.250 base units
- **THEN** both movement legs SHALL preserve 1.250 units without integer truncation

#### Scenario: Invoice tax differs from physical tax state
- **WHEN** accepted invoice tax differs from a source stock bucket or serial tax identity
- **THEN** financial tax SHALL NOT silently relabel physical inventory; movement SHALL preserve authoritative physical buckets and serial tax evidence

### Requirement: Shared average cost changes without a second receipt
For unsold acquisitions, the business's existing shared average-cost model SHALL replace the acquired units' pre-finalization carrying value with final invoiced inventory cost, without adding quantity to the denominator. The calculation SHALL use locked current quantity and average, include allocated invoice discounts and applicable inventory-cost tax treatment, and retain before/after evidence.

#### Scenario: Existing quantity is repriced
- **WHEN** a business holds ten units at average 100 and buys four existing unsold units at final inventory cost 120 each
- **THEN** total physical quantity SHALL remain ten and its average SHALL become 108
- **AND** other businesses' costs and historical Sales/POS cost snapshots SHALL remain unchanged

#### Scenario: Invoice discount applies to mixed lines
- **WHEN** an invoice contains sold and unsold lines and a global discount
- **THEN** the discount SHALL be allocated deterministically across all contributing lines, preserving the exact header total
- **AND** only unsold lines' allocated inventory costs SHALL affect the current average

#### Scenario: Sold quantities are billed
- **WHEN** a document contains only sold-but-unbilled quantities
- **THEN** finalization SHALL create no stock, current average-cost, or last-purchase-price change and SHALL not rewrite historical sales costs

### Requirement: Finalization is atomic and idempotent
Finalization SHALL revalidate approved terms, ownership, availability, return evidence, reservations, source receipts, serials, and destinations in one transaction and create exactly one protected Purchase with complete lineage. Concurrent or repeated requests SHALL create at most one payable and movement set. Finalization SHALL not create payments.

#### Scenario: Mixed buyout finalizes
- **WHEN** two sold units and three unsold units are finalized together
- **THEN** one Purchase SHALL bill five units with receipt lineage, only three units SHALL move, and all five units SHALL be unavailable for another supplier charge

#### Scenario: Availability becomes insufficient
- **WHEN** selected resources cannot fulfill the exact approved quantities
- **THEN** finalization SHALL fail completely with actionable blockers instead of shrinking or substituting the purchase

#### Scenario: Posting or audit fails
- **WHEN** any later Purchase line, movement, cost, serial, ownership, or audit write fails
- **THEN** all changes in that finalization SHALL roll back and the approved reservations SHALL remain intact

#### Scenario: Finalization is repeated
- **WHEN** finalization is retried after a successful commit
- **THEN** it SHALL return the same linked Purchase or reject an incompatible retry without creating or changing postings

### Requirement: Acquired goods cannot generate another supplier bill
Finalized sold slices SHALL consume sold and receipt capacity once. Finalized unsold slices SHALL consume receipt capacity and establish company ownership before resale. Customer returns of newly acquired goods SHALL remain company-owned and SHALL not replenish supplier-owned capacity or reopen consignment billing eligibility. Existing held-custody, inspection, and POS execution policies SHALL remain enforced.

#### Scenario: Bought stock is later sold
- **WHEN** bought-out goods are dispatched from their standard location through Sales or POS
- **THEN** discovery SHALL not create another consignment liability for those units

#### Scenario: Billed sold goods are returned
- **WHEN** a customer physically returns goods covered by a finalized buyout sold slice
- **THEN** the acquired return quantity SHALL retain its recorded same-business standard destination, entering held custody where required and sellable stock only through the existing authorized release policy
- **AND** historical dispatch/source snapshots and the supplier payable SHALL remain unchanged

#### Scenario: Return covers mixed ownership quantities
- **WHEN** a return includes quantities both covered and not covered by buyout acquisition
- **THEN** receiving SHALL explicitly separate their ownership and destination effects without double counting physical returns
- **AND** ambiguous quantity or serial attribution SHALL block receipt rather than guess

### Requirement: Full reversal requires untouched acquisition evidence
A dedicated permissioned full reversal SHALL require a reason, no historical payment against the generated Purchase, no later dependent inventory/serial/cost use, and exact recoverable posting evidence. It SHALL atomically cancel liability, reverse ownership/movements/cost effects, and restore eligible allocation capacity while retaining original and compensating evidence. Ordinary deletion and partial reversal SHALL be prohibited.

#### Scenario: Unused acquisition is reversed
- **WHEN** a finalized buyout has no payment or downstream dependency and its exact cost and stock effects remain reversible
- **THEN** the entire Purchase liability and buyout posting set SHALL reverse together and original records SHALL remain auditable

#### Scenario: Goods were consumed and replenished
- **WHEN** later activity restores today's balance to the finalization balance
- **THEN** that later movement SHALL still block reversal

#### Scenario: Payment was invalidated
- **WHEN** a historical payment exists even if currently invalidated
- **THEN** the initial full-reversal workflow SHALL reject the request and require a separately defined settlement workflow

#### Scenario: Payment races with reversal
- **WHEN** a payment and reversal target the same finalized Purchase concurrently
- **THEN** at most one incompatible action SHALL commit and the losing transaction SHALL leave no partial effects

### Requirement: Reconciliation distinguishes acquisition from custody and payment
Buyout views and reconciliation SHALL distinguish original receipts, sold attribution, receipt reservations, unsold physical reservations, acquired sold/unsold quantities, ownership movements, reversals, linked Purchase, paid amount, and due amount. Original receipt evidence SHALL remain immutable, and released or reversed reservations SHALL not be counted as active.

#### Scenario: Operator reviews mixed acquisition
- **WHEN** an operator reviews a finalized mixed buyout
- **THEN** the screen SHALL show which quantities moved, which were already sold, their source receipts and serials, owned destinations, invoice amounts, and current payment balance

#### Scenario: Historical data is revalidated
- **WHEN** legacy sold-only records are opened or revalidated after buyout support is installed
- **THEN** their historical snapshot versions and amounts SHALL remain unchanged and no compatibility-only mismatch SHALL be introduced

### Requirement: Newly prepared buyouts create one Purchase detail per product
Buyouts prepared with product-level terms SHALL create exactly one Purchase detail for each distinct product, combining its sold and unsold quantities across all selected receipts and destinations. Detail financial amounts SHALL match the approved product row. Each source contribution SHALL retain original evidence, accepted amounts and its precise link to that detail; source allocation rows SHALL remain distinct.

#### Scenario: Several receipt lots share a product
- **WHEN** an approved product row contains five units spanning different original receipt prices, source tax evidence and sold/unsold allocations
- **THEN** finalization SHALL create one Purchase detail with quantity five and the single accepted invoice price, discount and tax
- **AND** lineage SHALL preserve each contribution and reconcile exactly to the detail totals

#### Scenario: Mixed product row affects unsold cost only
- **WHEN** two sold units and three unsold units share a product invoice row and a global discount
- **THEN** financial allocation SHALL reconcile across all five units and only the accepted unsold inventory amounts SHALL enter the existing acquisition-cost calculation
- **AND** physical stock buckets, serial identities and historical sales costs SHALL follow existing buyout rules

#### Scenario: Fractional quantities and rounded totals
- **WHEN** source slices have fractional quantities or a product line-total override cannot divide evenly to currency precision
- **THEN** deterministic allocation SHALL preserve the exact approved product and document totals without creating a second product detail or a different invoice price on another source slice

### Requirement: Product grouping preserves existing buyout documents
Historical finalized Purchases SHALL retain their existing detail identities, amounts and lineage. Existing approved buyouts SHALL retain their approved financial snapshot and hash contract and SHALL finalize through their existing compatible terms. Reopening an eligible draft SHALL use the product editor without inventing a shared invoice price where saved source prices disagree.

#### Scenario: Historical Purchase is opened
- **WHEN** a generated Purchase predates product-level preparation
- **THEN** it SHALL display its existing rows without automatic regrouping or repricing

#### Scenario: Existing approved document is finalized
- **WHEN** an approved legacy buyout is finalized without financial edits
- **THEN** its original approval verification, accepted totals and source mapping SHALL remain compatible and SHALL not require rewriting approval hashes

#### Scenario: Existing draft has conflicting saved prices
- **WHEN** a draft with different prices for the same product is reopened in the wizard
- **THEN** the editor SHALL show its source evidence and require one explicit product price or total before the revised draft is saved
