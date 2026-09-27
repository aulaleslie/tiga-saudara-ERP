## Context

The consignment billing conversion already groups commercial Purchase details by product, cost, and tax while retaining one immutable lineage row per contributing allocation or serial. The Purchase show page currently renders every lineage row under its product and labels `consignment_receiving_detail_id` as a receiving number. It checks whether a serialized allocation exists but does not resolve its `product_serial_number_id` to `serial_number`.

The local example, CBC `CBC-202609-0001` / Purchase `TPI-BL-2026-09-00016`, has two correct Purchase product rows and 12 serial lineage rows. All belong to receival `TPI-CR-2026-08-00001` and receiving `TPI-CRN-2026-08-00001`. The receipt allocation has a `receiving_reference` snapshot, while its `receival_reference` can be null; the receival relationship supplies the number. Existing Purchase financial and lineage data must remain intact.

## Goals / Non-Goals

**Goals:**
- Present a consignment receival reference, receiving number, summed billed quantity, and actual serial numbers grouped within each Purchase product row.
- Include non-serialized lineage quantity without inventing serial numbers.
- Resolve source data for all rows with bounded queries and display a neutral unavailable label when a legacy source number cannot be resolved.

**Non-Goals:**
- Regroup or recalculate Purchase details, prices, tax, or payments.
- Change conversion, lineage persistence, serial ownership, or historical records.
- Add a database migration or change other Purchase pages.

## Decisions

1. Group for presentation within each existing Purchase detail by receival and receiving identity. Sum `billed_base_quantity` from that detail's lineage rows; collect unique, nonempty serial number strings from serialized allocations. The detail's financial row remains the same. Grouping by product across Purchase details was considered but would obscure commercially distinct cost or tax rows.
2. Use `ConsignmentPurchaseDetailLineage.receivingDetail -> consignmentReceiving -> receival` to resolve the consignment receival reference and receiving number. Prefer the immutable receipt allocation reference snapshot where available for the displayed receiving number, with the receiving relationship as fallback. A raw numeric ID must never appear as a document number. Group using source IDs rather than displayed strings so missing or duplicate references cannot merge unrelated sources.
3. Resolve each serialized lineage through `serializedAllocation -> productSerialNumber -> serial_number`. Eager load these nested relationships in the Purchase show action to avoid one query per lineage. Reusing lineage relationships was chosen over copying serial strings into Purchase details because the immutable links already identify the billed serials.
4. Show a clear unavailable label for a missing historical reference or serial value while retaining the grouped quantity. Missing serials remain visible as unresolved evidence rather than being silently counted as displayed serials. Existing non-consignment Purchases retain their current presentation.

## Risks / Trade-offs

- [Missing legacy source relation or reference] → Show an unavailable label and keep source groups separate by internal identity; do not mislabel an ID as a document number.
- [Many serials make a product cell tall] → Use a compact, readable serial list within the source group while keeping each serial visible.
- [Grouping hides allocation-level audit detail] → Keep immutable lineage rows unchanged; this view only summarizes them. Tests verify quantity and serial coverage against stored lineage.

## Migration Plan

Deploy the controller/view and focused tests together. No data migration or backfill is required. Rolling back the code restores the previous display without changing stored Purchases.

## Open Questions

None. `TPI-CR-…` is the consignment receival reference, and `TPI-CRN-…` is the receiving number; display both with distinct labels.
