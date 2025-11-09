# Global Purchase and Sales Search Service Architecture

## Overview
The GlobalPurchaseAndSalesSearchService will provide unified search capabilities across both purchase orders and sales orders, supporting serial number tracking, reference lookups, and party searches.

## Key Components

### 1. Search Methods
- `searchBySerialNumber()` - Search by serial number in both purchases and sales
- `searchByPurchaseReference()` - Search purchase orders by reference
- `searchBySalesReference()` - Search sales orders by reference
- `searchBySupplier()` - Search purchases by supplier name
- `searchByCustomer()` - Search sales by customer name
- `searchCombined()` - Combined search across all fields

### 2. Data Sources

#### Sales Serial Numbers
- Table: `dispatch_details`
- Column: `serial_numbers` (JSON array)
- Query: `JSON_SEARCH(serial_numbers, 'one', ?) IS NOT NULL`

#### Purchase Serial Numbers
- Table: `product_serial_numbers`
- Linked via: `received_note_details` -> `product_serial_numbers`
- Query: Join through received notes

### 3. Result Structure
```php
[
    'type' => 'purchase' | 'sale',
    'id' => int,
    'reference' => string,
    'party_name' => string, // supplier or customer
    'amount' => float,
    'status' => string,
    'location' => string,
    'date' => Carbon,
    'tenant' => string,
    'serial_count' => int
]
```

### 4. Search Logic
- OR logic within search type
- AND logic for filters (status, date range, etc.)
- Case-insensitive searches
- Support for partial matches

### 5. Performance Optimizations
- Database indexes on searchable columns
- Query result limiting
- Pagination support
- Optional tenant filtering

## Integration Points

### Existing Services
- Extend Sale SerialNumberSearchService patterns
- Adapt for purchase data structures

### Database Schema
- Audit table: `global_purchase_and_sales_searches`
- Indexes on: purchase.reference, sale.reference, supplier.name, customer.name
- JSON indexes on serial numbers

### API Endpoints
- Main search: `GlobalPurchaseAndSalesSearchController@index`
- Autocomplete: `GlobalPurchaseAndSalesSearchController@suggest`

### Livewire Component
- `GlobalPurchaseAndSalesSearch` component
- Reactive search with 300ms debounce
- Results table with clickable hyperlinks</content>
<parameter name="filePath">/home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/ai-docs/global-purchase-and-sales-search/architecture-design.md