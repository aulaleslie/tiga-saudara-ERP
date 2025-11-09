# Data Model and Entity Relationships

## Core Entities

### Purchase Flow
```
Purchase (PO)
├── PurchaseDetail (PO lines)
│   └── ReceivedNoteDetail (receiving records)
│       └── ProductSerialNumber (serial tracking)
└── Supplier (vendor info)
```

### Sales Flow
```
Sale (SO)
├── SaleDetails (SO lines)
│   └── DispatchDetail (dispatch records with serial_numbers JSON)
└── Customer (buyer info)
```

## Key Relationships

### Purchase Serial Numbers
- `Purchase` → `PurchaseDetail` (1:many)
- `PurchaseDetail` → `ReceivedNoteDetail` (1:many)
- `ReceivedNoteDetail` → `ProductSerialNumber` (1:many)

### Sales Serial Numbers
- `Sale` → `DispatchDetail` (1:many, direct relationship)
- Serial numbers stored as JSON in `DispatchDetail.serial_numbers`

## Search Data Sources

### Serial Number Search
**Sales:** Direct JSON search on `dispatch_details.serial_numbers`
**Purchases:** Join through `product_serial_numbers` → `received_note_details` → `purchase_details` → `purchases`

### Reference Search
**Purchases:** Direct search on `purchases.reference`
**Sales:** Direct search on `sales.reference`

### Party Search
**Purchases:** `purchases` → `suppliers.supplier_name`
**Sales:** `sales` → `customers.customer_name`

## Database Schema Details

### Purchases
- `purchases.id` (Primary Key)
- `purchases.reference` (Searchable)
- `purchases.supplier_id` → `suppliers.supplier_name` (Searchable)
- `purchases.total_amount`, `purchases.status`, `purchases.created_at`
- `purchases.setting_id` (Tenant)

### Sales
- `sales.id` (Primary Key)
- `sales.reference` (Searchable)
- `sales.customer_id` → `customers.customer_name` (Searchable)
- `sales.total_amount`, `sales.status`, `sales.created_at`
- `sales.setting_id` (Tenant)

### Serial Numbers
- **Sales:** `dispatch_details.serial_numbers` (JSON array)
- **Purchases:** `product_serial_numbers.serial_number` (string)

## Query Patterns

### Serial Number Search (Sales)
```sql
SELECT * FROM sales
WHERE EXISTS (
    SELECT 1 FROM dispatch_details
    WHERE sale_id = sales.id
    AND JSON_SEARCH(serial_numbers, 'one', ?) IS NOT NULL
)
```

### Serial Number Search (Purchases)
```sql
SELECT * FROM purchases
WHERE EXISTS (
    SELECT 1 FROM purchase_details pd
    JOIN received_note_details rnd ON pd.id = rnd.po_detail_id
    JOIN product_serial_numbers psn ON rnd.id = psn.received_note_detail_id
    WHERE pd.purchase_id = purchases.id
    AND psn.serial_number LIKE ?
)
```

### Combined Results
- Union purchase and sales results
- Add `type` field ('purchase'/'sale')
- Sort by `created_at DESC`
- Apply pagination</content>
<parameter name="filePath">/home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/ai-docs/global-purchase-and-sales-search/data-model.md