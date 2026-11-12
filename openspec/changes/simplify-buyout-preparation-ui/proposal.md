# Proposal

## Why

The consignment buyout preparation screen (`/consignments/buyouts/create`) repeats the same goods across an audit table, an "all eligible" proposal table and three draft tables, asks operators to type comma-separated serial database IDs and tax-bucket quantities, and exposes internal IDs (sold source #, receipt detail #, sale #). A reviewed request on representative data also executed roughly 7,985 queries because the same read-only capacity evidence was recomputed for every receipt, pool and sold source.

## What Changes

- Replace the preparation screen with three steps on one page:
  1. **Supplier and destinations** – select supplier, show the original billing business, choose one owned-stock destination per holding business.
  2. **Select goods** – grouped by receiving document → product → unsold / sold-but-unbilled. Each eligible serial is one checkbox labelled with its serial number, shown once. Non-serialized goods keep quantity inputs. Global, receipt-level and product-level select/deselect controls show partial states. Sold units show their POS/Sales document number with a permission-aware link and the selling business. Unsold units show current location and acquiring business. Blocked goods are listed separately with plain explanations and actions; fully billed sales are only summarised.
  3. **Review and save draft** – quantities, receipt prices, document discount, estimated supplier payable with separate sold/unsold subtotals, billing business vs. stock owner, invoice information and **Simpan Draft**.
- Selections are preserved when moving between steps and after a failed save (old input).
- Tax buckets are no longer typed: they are derived from the selected stock using the existing rule (non-taxed first, then taxed, per pool in receipt order), shown as an estimate in review and derived authoritatively on the server when absent.
- Receipt, sold source, source location and inventory-owner identities stay as hidden line fields behind grouped rows; nothing is substituted.
- Preparation reuses/batches read-only evidence within the request through an explicit read-only memo scope on the capacity service. Mutation paths (store, revise, submit, approve, finalize, reverse) never enable the memo.

Unchanged: permissions, the `consignment.buyouts.ui_enabled` gate, reservations, capacity checks, serial eligibility, pricing, ownership, approval, finalization, reversal, manual discovery, POS transactions and allocation order. Saving creates a draft only; server validation remains authoritative.

## Capabilities

### New Capabilities

- `consignment-buyout-preparation`: the three-step preparation workflow, grouped goods selection, serial selection, blocked-goods presentation, review totals, and preparation-request evidence reuse.

### Modified Capabilities

None. Buyout lifecycle rules defined by `buy-out-unsold-consignment-stock` and remote ownership defined by `track-transferred-consignment-provenance` are consumed as-is.

## Impact

- `Modules/Consignment/Http/Controllers/ConsignmentBuyoutController.php` (`create`, store input normalisation)
- New `Modules/Consignment/Services/ConsignmentBuyoutPreparationService.php`
- `ConsignmentBuyoutProposalService` (deferred destinations, full serial options, hard blocker separation)
- `ConsignmentBuyoutCapacityService` (opt-in read-only memo)
- `Modules/Consignment/Resources/views/buyouts/create.blade.php` (rewritten)
- Consignment feature tests for the preparation screen and a query-budget test.
