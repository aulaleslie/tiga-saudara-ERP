## 1. Resolve purchase source evidence

- [x] 1.1 Eager load each Purchase detail's consignment lineage, receipt allocation snapshots, receiving and receival documents, and serialized allocation's product serial number in the Purchase show action.
- [x] 1.2 Build display groups per Purchase detail using source document identities; sum lineage billed quantities and collect unique serial strings, with explicit unavailable values for missing references.

## 2. Present grouped provenance

- [x] 2.1 Replace the repeated lineage text in the Purchase detail view with one consignment receival and receiving group per source, displaying its summed quantity and actual serial numbers.
- [x] 2.2 Preserve the current financial product rows and ordinary Purchase display; show non-serialized quantities without a serial marker and never render an internal ID as a document number.

## 3. Focused verification

- [x] 3.1 Add or update focused Purchase show feature tests for repeated serialized allocations, multiple source groups, non-serialized quantity, missing legacy references, and ordinary Purchases.
- [x] 3.2 Run the focused Purchase show tests and inspect the resulting view against the existing CBC `CBC-202609-0001` / Purchase `TPI-BL-2026-09-00016` data when the local database is available.
