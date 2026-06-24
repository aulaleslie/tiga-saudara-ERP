## Why

Operational users need to find purchase, sales, purchase return, and sale return documents from the numbers and product identifiers they actually have in hand. The current list searches are uneven: purchase and sales lists already cover some external references and product names, while return lists mostly search header columns and miss product lines and source document references.

## What Changes

- Expand the operational purchase list search to include product codes and the remaining purchase external reference fields while preserving existing filters, sorting, pagination, archive visibility, and summary-card filters.
- Expand the operational sales list search to include product codes while preserving existing imported reference, tax reference, customer, product name, tag, filter, sorting, pagination, and archive behavior.
- Add explicit global search behavior to the purchase return list so users can find returns by return number, supplier, returned product name/code, and source purchase reference numbers.
- Add explicit global search behavior to the sale return list so users can find returns by return number, customer, returned product name/code, source sale reference, imported sales reference, and tax reference.
- Use historical document detail snapshots (`product_name`, `product_code`) as the primary product search surface for existing documents and returns.
- Keep this change limited to list search behavior; it does not alter document creation, approval, settlement, stock, serial, payment, or reporting rules.

## Capabilities

### New Capabilities
- `document-list-search`: Defines the searchable fields and expected behavior for operational purchase, sales, purchase return, and sale return lists.

### Modified Capabilities
- None.

## Impact

- Affected Livewire components:
  - `app/Livewire/Purchase/PurchaseTable.php`
  - `app/Livewire/Sale/SaleTable.php`
- Affected Yajra DataTables:
  - `Modules/PurchasesReturn/DataTables/PurchaseReturnsDataTable.php`
  - `Modules/SalesReturn/DataTables/SaleReturnsDataTable.php`
- Affected model relationships and query paths:
  - `Purchase::purchaseDetails`, `PurchaseDetail::product`
  - `Sale::saleDetails`
  - `PurchaseReturn::purchaseReturnDetails`, `PurchaseReturnDetail::purchase`
  - `SaleReturn::saleReturnDetails`, `SaleReturn::sale`
- Tests should cover focused search behavior for all four lists and guard that filters/sorting/pagination still compose with search.
