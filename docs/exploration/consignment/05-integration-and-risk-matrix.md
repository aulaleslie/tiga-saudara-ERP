# Integration and Risk Matrix

| Area | MVP behavior to explore/specify | Main risk | Safeguard |
|---|---|---|---|
| Location admin | Mark/classify eligible consignment custody | Reclassifying live stock | Block change while consignment stock/open obligations exist |
| Purchase/receipt | Receive custody without a supplier bill | Premature bill/payable or cost update | Distinct document semantics and posting guard |
| Product stock | Reuse aggregate physical location bucket | Mixed supplier ownership is invisible in aggregate stock | Separate receipt ownership ledger and reconciliation |
| Tax buckets | Preserve tax/non-tax provenance | Wrong output/input tax treatment | Snapshot tax classification on receiving and allocation |
| Serial numbers | Resolve supplier from approved receipt lineage | Serial moves lose title history | Immutable serial-to-receipt lineage |
| Transfer | Preserve or explicitly convert ownership | Ordinary transfer accidentally buys/sells goods | Transfer policy matrix and conversion action |
| Standard Sales | Respect the location selected on actual dispatch | Sale line may mix consignment eligibility incorrectly | Persist and bill only actual consignment-location dispatch quantity |
| POS | Preserve current configured location-priority allocation | A line may be sourced partly from standard and consignment locations | Use persisted POS source allocations; do not recalculate later |
| Bundles | Allocate ownership at component level | Parent sale hides consigned components | Defer or require component-level lineage |
| Sales Return | Restore original ownership/location | Returned unit becomes owned stock | Source-linked reversal |
| Purchase Return | Return custody to consignor | Incorrect purchase refund/payable | Dedicated owner-return semantics |
| Adjustments | Record shrinkage/damage responsibility | Unexplained financial loss | Reason, approval, evidence, agreement rule |
| HPP | Recognize appropriate cost on sale | Unsold consignment inflates owned valuation | Exclusion rules and sale-time cost snapshot |
| Billing confirmation | Allocate sold units to supplier receipt pools | Overallocation or arbitrary ownership | Lock/revalidate sold eligibility and supplier balances atomically |
| Supplier bill/payable | Generate Purchase without receiving stock again | Duplicate stock or early bill/liability | Explicit consignment-bill type and no-stock receipt bypass |
| Customer output VAT | Continue recognizing tax through Sales/POS | Supplier allocation changes customer tax incorrectly | Keep customer-sale tax independent from supplier billing |
| Supplier input VAT | Record supplier tax invoice when applicable | Assuming company PKP status alone determines VAT | Validate company, supplier, goods, DPP, and creditability separately |
| Tax period | Preserve sale, recognition, invoice, and Faktur Pajak dates | User delays confirmation into another month | Cross-period controls, privileged override, immutable audit |
| Settlement | Freeze movement set and terms | Double settlement or history mutation | Unique inclusion link and immutable approved statement |
| Customer payment | Define whether eligibility waits for collection | Supplier paid before retailer collects | Explicit settlement trigger |
| Reports | Separate physical vs owned quantities/value | Misleading inventory/financial decisions | Named report dimensions and reconciliation totals |
| Permissions | Separate configure, receive, sell, adjust, settle, pay | Operational user changes financial terms | Least-privilege permission set |
| Imports | Reject or explicitly classify consignment imports | Legacy importer creates owned stock | New import type/preflight |
| Deletion/archive | Retain historical agreement/location references | Broken audit trail | Soft close; restrict destructive deletion |

## Highest-risk invariants

1. Every unit billed as consignment must trace to exactly one supplier, approved receiving source, and qualifying sale quantity.
2. A consignment sale movement can enter at most one approved settlement, except through explicit reversal/credit events.
3. Physical quantity reconciliation and financial obligation reconciliation must be independently possible.
4. Unsold inbound consigned inventory must not be reported as company-owned inventory value.
5. Agreement edits must not change historical sale cost or settlement amounts.
6. Concurrent sales cannot consume the same available consigned quantity.
7. Returns and voids must reverse both inventory and settlement eligibility consistently.
8. Billing confirmation must not arbitrarily redefine the legal sale, recognition, or Faktur Pajak period.
9. Output VAT on the customer sale and input VAT on the supplier bill must remain separate transactions.

## Minimum reconciliation views

- opening consigned quantity + receipts + inbound transfers + customer returns - sales - owner returns - outbound transfers +/- adjustments = closing physical consigned quantity;
- eligible sold amount - statement inclusions - reversals = unsettled obligation;
- approved statements - payments - credits = outstanding supplier liability;
- serial ownership history agrees with serial physical location and current status.
