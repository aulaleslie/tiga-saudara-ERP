# Spec Delta

## ADDED Requirements

### Requirement: Supplier liability follows the original receipt business
Consignment confirmation, supplier Purchase, invoice tax policy, payment, and due balance SHALL belong to the original approved receipt business and supplier even when another business sells or holds the goods. A supplier document SHALL not combine receipts belonging to different billing businesses or reassign the selling business's sales or customer payments.

#### Scenario: CANDRA goods sell at PERDANA
- **WHEN** a verified PERDANA dispatch consumes goods from TOP IT's CANDRA receipt
- **THEN** any supplier confirmation and Purchase SHALL belong to TOP IT with its supplier identity and invoice policy
- **AND** PERDANA's sale, revenue, customer payment, dispatch tax, and historical amounts SHALL remain unchanged

#### Scenario: Mixed original businesses contribute to one dispatch
- **WHEN** a remote dispatch contains goods attributed to two original receipt businesses
- **THEN** supplier billing SHALL separate obligations by original business and supplier without splitting or rewriting the actual customer transaction

#### Scenario: Existing bills are inspected after reconstruction
- **WHEN** verified transfer provenance is added around a receipt with historical billing
- **THEN** existing Purchase identity, paid/due balances, supplier allocations, snapshots, and payments SHALL remain unchanged
- **AND** later billing SHALL exclude all previously consumed receipt and serial quantities

### Requirement: Supplier-facing documents exclude remote internal identity
Supplier-facing consignment bill, payment, print, and export surfaces SHALL identify the original receipt business as the supplier's counterparty. They SHALL not expose remote business names, customer-sale references, internal transfer references, or inter-business settlement. Authorized internal lineage and reconciliation SHALL retain these references separately.

#### Scenario: Supplier receives a document for transferred goods
- **WHEN** TOP IT issues supplier-facing billing or payment evidence covering PERDANA-held or PERDANA-sold goods
- **THEN** the document SHALL identify TOP IT and the supplier without exposing PERDANA or its internal transactions
- **AND** internal users with the required permissions SHALL still be able to trace each quantity to its actual custody and sale

#### Scenario: Financial settlement remains external to provenance
- **WHEN** transfer reconstruction, sold allocation, or remote acquisition succeeds
- **THEN** no automatic TOP IT-to-PERDANA invoice, payable, commission, stock-debt settlement, or payment SHALL be generated
