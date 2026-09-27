# Current-System Fit

## What the repository already provides

The system is already strongly location-oriented:

- `locations` belongs to a `setting` (business).
- `product_stocks` is bucketed by product and location, with normal/broken and tax/non-tax quantities.
- serial numbers and serial history carry location identity.
- inventory `transactions` record product, setting, location, quantity, bucket values, and before/after balances.
- stock transfers already move authoritative bucket and serial provenance atomically between locations.
- Purchase receiving adds inventory at locations; Sales dispatch removes it.
- POS resolves and prioritizes sale locations and supports inventory sourced across business-owned locations.
- Sales Return and Purchase Return restore/remove stock at a selected or source-aligned location.
- inventory, valuation, mutation, purchase, sales, and supplier-payables reports already query these structures.

This makes a dedicated consignment location the lowest-friction physical segregation mechanism.

## Important existing semantics

`locations.setting_id` currently describes the business that owns/configures the location. It does not independently describe legal title to every unit stored there.

`setting_sale_locations` allows a business to enable and prioritize locations, including foreign-owned locations, for sales sourcing. This is useful precedent for consignment availability, but it is not itself a consignment contract or ownership ledger.

The stock record currently lacks supplier, consignment agreement, ownership type, receipt lot, and settlement state. Consequently, a boolean on location can say “this place is used for consignment,” but cannot safely answer:

- whose goods these are;
- whether this is inbound or outbound consignment;
- which terms apply;
- which receipt lot funded a sale;
- what is owed to a supplier;
- whether returned stock remains consigned;
- whether two suppliers' identical SKU units may be mixed.

## Natural integration seams

| Concern | Existing seam | Consignment implication |
|---|---|---|
| Physical custody | `locations` | Reuse; classify or associate it with an agreement |
| Quantity buckets | `product_stocks` | Reuse for MVP if one owner/agreement per location |
| Audit ledger | product `transactions` | Add consignment provenance/reference rather than create an unrelated stock ledger |
| Incoming goods | Purchase receiving / received notes | Introduce consignment receipt semantics without creating supplier payable immediately |
| Outgoing goods | Sales dispatch / POS checkout | Consume consigned provenance and create a settlement obligation |
| Movement | Stock transfer lifecycle | Define which transfers preserve consignment and which represent ownership conversion |
| Returns | Sales/Purchase Returns | Reverse sale/settlement and restore the same ownership provenance |
| Valuation/HPP | average cost and sale HPP snapshots | Exclude unsold supplier-owned stock from owned inventory valuation; define HPP on sale |
| Supplier liability | supplier payables/payments | Do not treat receipt as an ordinary payable; settle sold/accepted units later |
| Access | business/location permissions | Add agreement and settlement permissions; retain tenant boundaries |

## Compatibility constraints

1. Standard inventory workflows must behave exactly as before for non-consignment locations.
2. A consignment classification must not silently make all stock at a historical location consigned.
3. Quantity, tax, broken-stock, serial, bundle, and UOM provenance must survive every movement.
4. Financial ownership cannot be inferred from the current physical location after stock has moved.
5. Reports must explicitly state whether they show physical stock, owned stock, consigned-in stock, or consigned-out stock.
6. Existing cross-business sale-location behavior must not be mistaken for supplier consignment.
