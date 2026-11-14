# consignment-transfer-provenance Specification

## Purpose
Preserve consignment supplier responsibility and receipt-backed quantity provenance across custody transfers, remote sales, and ownership acquisition without changing cashier transactions.

## Requirements

### Requirement: Original receipt determines supplier responsibility
Every consignment unit or attributed quantity SHALL retain its original approved receipt, supplier, and receipt business across transfers and sales. Current custody, selling business, and post-buyout inventory owner SHALL be represented separately. Transfer alone SHALL not create a supplier bill or change the party owing the supplier.

#### Scenario: TOP IT goods are sold by PERDANA
- **WHEN** PERDANA sells goods received from CANDRA by TOP IT and transferred into PERDANA custody
- **THEN** PERDANA SHALL retain the sale, revenue, customer payment, and dispatch
- **AND** supplier billing eligibility SHALL belong to TOP IT and CANDRA's original receipt

#### Scenario: Several receipt businesses fund one remote dispatch
- **WHEN** one dispatch consumes quantities traceable to receipts of different businesses or suppliers
- **THEN** reconciliation SHALL separate their supplier obligations while retaining one original dispatch and its exact total quantity

### Requirement: Consignment transfers retain receipt-backed custody
A consignment transfer SHALL retain exact receipt quantities or serial identities through reserved, in-transit, received, cancelled, and returned states. Dispatch SHALL remove source custody and establish transit; completed receipt SHALL establish destination custody. No quantity SHALL be available in two custody states at once.

#### Scenario: Goods are dispatched but not received
- **WHEN** five receipt-backed units leave one consignment location for another
- **THEN** they SHALL be shown in transit, unavailable for sale-backed attribution or unsold buyout at either warehouse

#### Scenario: Transfer is cancelled or returned
- **WHEN** an eligible dispatch is cancelled or a transfer return is completed
- **THEN** the exact affected receipt quantities or serials SHALL return to the correct custody state once, with original evidence preserved

### Requirement: Non-serialized transfers require explicit lot quantities
Non-serialized consignment transfer execution SHALL require positive receipt-lot allocations totaling its dispatched quantity within each eligible source pool. A sole eligible lot SHALL be proposed for operator confirmation; multiple lots SHALL require explicit selection. Stock from another supplier or unrelated standard inventory SHALL not substitute for missing capacity.

#### Scenario: One lot is eligible
- **WHEN** a transfer preparer has one eligible lot covering the requested quantity
- **THEN** that lot SHALL be proposed and require confirmation before dispatch

#### Scenario: Multiple lots can fund a transfer
- **WHEN** several receipts can cover a non-serialized transfer
- **THEN** the transfer operator SHALL explicitly allocate quantities and execution SHALL reject missing, duplicate-overconsumed, or insufficient allocations

#### Scenario: Prior sales leave custody ambiguous
- **WHEN** unassigned historical or newly discovered sales prevent establishing a receipt's transfer capacity
- **THEN** transfer execution against that ambiguous quantity SHALL require back-office resolution rather than assume FIFO or LIFO

### Requirement: POS remains unaware of supplier attribution
POS SHALL retain its existing cashier steps, configured source-location order, source-business tax rules, exact selected serial behavior, transaction posting, and customer payments. Supplier and receipt allocation SHALL occur in consignment back-office reconciliation after manual discovery and SHALL not become a checkout prerequisite.

#### Scenario: POS consumes mixed locations
- **WHEN** checkout consumes ordinary and consignment stock across configured sources
- **THEN** its existing allocation order, quantities, business split, taxes, and customer-facing receipt SHALL remain unchanged
- **AND** consignment reconciliation SHALL read only its actual persisted dispatch evidence

#### Scenario: Multiple consignment receipts are eligible for a sale
- **WHEN** POS sells non-serialized goods from a location with multiple eligible receipt lots
- **THEN** checkout SHALL finish without asking the cashier to select a supplier or receipt
- **AND** unresolved receipt attribution SHALL block only subsequent consignment billing or buyout for affected quantities

### Requirement: Historical reconstruction is previewed and audited
Historical custody SHALL be reconstructed only from authoritative receipt, movement, serial, dispatch, and return evidence. Preview SHALL be read-only; confirmed mappings SHALL be idempotent, auditable, and preserve original stock and financial documents. Ambiguous quantities SHALL remain blocked without fabricated receipt provenance.

#### Scenario: Existing AXIOO transfers are reconstructed
- **WHEN** approved transfer and serial histories prove the TOP IT receipt's movements to and from PERDANA
- **THEN** preview SHALL explain local, remote, and sold quantities and confirmation SHALL add provenance without rewriting stock, transfers, sales, payments, or existing bills

#### Scenario: Historical non-serialized movement has no receipt evidence
- **WHEN** several receipt lots could have funded a movement
- **THEN** an authorized operator SHALL explicitly attribute it with a reason and supporting references before dependent consignment actions

#### Scenario: Reconstruction is repeated
- **WHEN** the same confirmed source events are processed again
- **THEN** no custody quantity, sale obligation, or financial consumption SHALL be duplicated

### Requirement: Custody and financial capacities remain distinct
Consignment availability SHALL reconcile receipt-backed quantities by current custody and condition while supplier financial consumption remains global to the original receipt. Transfers, sales, returns, and acquisitions SHALL consume or restore only their own distinct capacity; no financial reservation SHALL be mistaken for physical stock.

#### Scenario: TOP IT buys only its local remainder
- **WHEN** a receipt has four eligible units at TOP IT and two at PERDANA
- **THEN** TOP IT SHALL be able to buy its four units without treating the remote two as missing local stock
- **AND** the remote quantities SHALL remain separately traceable and unavailable for duplicate consumption

#### Scenario: Legacy and buyout billing compete across businesses
- **WHEN** two supplier billing paths target the same original receipt quantity or sold occurrence
- **THEN** at most its unconsumed capacity SHALL be allocated globally, including legacy pending/approved allocations and active/finalized buyouts

### Requirement: Remote buyout bills the origin and acquires stock locally
A buyout SHALL retain one original receipt business and supplier while allowing eligible custody locations in other authorized businesses. Unsold acquisition SHALL move goods only into a selected standard location of the holding business and recognize that business's owned stock. The supplier Purchase SHALL belong exclusively to the original receipt business.

#### Scenario: TOP IT buys stock held at PERDANA
- **WHEN** TOP IT finalizes a buyout of two eligible unsold CANDRA units held at PERDANA
- **THEN** TOP IT SHALL receive the supplier payable and PERDANA SHALL receive owned inventory at its selected standard destination
- **AND** no artificial physical transfer back to TOP IT, duplicate receiving, or inter-business payable SHALL be created

#### Scenario: Goods have not reached their destination
- **WHEN** selected unsold goods are still in transit, held for inspection, or committed elsewhere
- **THEN** acquisition SHALL reject those quantities without modifying the approved selection or posting partial effects

### Requirement: Remote acquisition cost follows inventory ownership
Unsold acquisition SHALL adjust current carrying value once per holding business and product using that business's locked quantity and average. Supplier invoice pricing and tax SHALL follow the original billing business. Physical buckets SHALL retain authoritative custody tax/condition evidence; sold quantities and historical Sales/POS costs SHALL not be rewritten.

#### Scenario: One invoice acquires stock in two businesses
- **WHEN** one original-business buyout acquires local and remote unsold slices of the same product
- **THEN** each holding business SHALL receive only its own carrying-value adjustment and owned-location movements
- **AND** the one supplier Purchase and all slice amounts SHALL reconcile exactly without double capitalization or changed historical sales

#### Scenario: Invoice and inventory tax policies differ
- **WHEN** the original billing business and remote inventory business have different tax policies
- **THEN** invoice tax SHALL not silently reclassify the remote stock buckets or customer-sale taxation

### Requirement: Remote returns preserve occurrence and ownership
Customer returns SHALL follow original sold and receipt allocations, reduce unbilled eligibility only for unacquired quantities, and retain acquired ownership in the selling or holding business. Existing standard held-custody and inspection rules and POS return execution policy SHALL remain enforced without modifying historical dispatch evidence.

#### Scenario: Remote sale is returned before supplier billing
- **WHEN** a PERDANA customer returns transferred consignment goods through an effective return
- **THEN** the returned quantity SHALL reduce its TOP IT supplier-billing eligibility and remain traceable at its actual return custody location

#### Scenario: Remote bought-out goods are returned
- **WHEN** acquired goods sold by PERDANA are returned
- **THEN** they SHALL remain PERDANA-owned and not reopen CANDRA or TOP IT supplier capacity
- **AND** both acquired and unacquired cumulative return bounds SHALL be enforced

### Requirement: Cross-business mutations are atomic and permissioned
Transfer provenance, attribution, acquisition, reversal, and reservations SHALL enforce original-document and affected custody-business authorization. Cross-business stock, cost, serial, and receipt claims SHALL commit atomically under coordinated locks. A reference to foreign stock alone SHALL not authorize reading or changing unrelated business data.

#### Scenario: Remote finalization fails on its last slice
- **WHEN** remote cost, ownership, stock, lineage, audit, or Purchase posting fails
- **THEN** all businesses' postings SHALL roll back and approved reservations SHALL remain intact

#### Scenario: Supplier billing access does not grant arbitrary remote stock access
- **WHEN** an origin-business biller lacks the required remote acquisition authority
- **THEN** remote acquisition SHALL be denied without exposing unrelated remote inventory or modifying the supplier bill

### Requirement: Reversal respects all affected businesses
Dedicated full buyout reversal SHALL preserve existing no-payment and no-downstream-use rules across every affected custody and inventory business. It SHALL reverse original financial and remote ownership/cost effects together, without deleting evidence or treating a restored balance as proof of no intervening activity.

#### Scenario: Remote goods were sold after acquisition
- **WHEN** PERDANA sells, moves, returns, or otherwise consumes acquired goods or has an incompatible later cost event
- **THEN** reversal of the linked TOP IT buyout SHALL be blocked with the dependency reference

#### Scenario: Unused multi-business acquisition is reversed
- **WHEN** all original and remote effects remain eligible for exact full reversal
- **THEN** supplier liability, owned stock, cost events, claims, and receipt capacity SHALL reverse together exactly once

### Requirement: Internal provenance creates no inter-business settlement
This capability SHALL record internal origin, custody, selling, and acquired-owner references without creating inter-business monetary payables, invoices, commissions, or stock-debt settlement entries. Supplier-facing documents SHALL identify only the original receipt business as the buying/paying party.

#### Scenario: Remote sale is billed to CANDRA
- **WHEN** TOP IT bills or pays CANDRA for units sold by PERDANA
- **THEN** supplier-facing bill and payment documents SHALL identify TOP IT and SHALL not expose PERDANA's identity or internal sale/transfer references
- **AND** authorized internal reconciliation SHALL retain those references for audit

#### Scenario: Another stock-debt feature exists
- **WHEN** the separate cross-business stock-debt workflow independently records a transfer
- **THEN** consignment reconstruction or acquisition SHALL not create a second debt event or automatic financial settlement
