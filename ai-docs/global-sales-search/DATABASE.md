# Global Sales Search Database Schema

## Core Tables

### sales
Main sales orders table.

**Key Fields:**
- `id` - Primary key
- `reference` - Unique sale reference (e.g., "SO-2025-001")
- `customer_id` - Foreign key to customers table
- `user_id` - Foreign key to users table (seller)
- `setting_id` - Foreign key to settings table (tenant)
- `location_id` - Foreign key to locations table
- `status` - Enum: DRAFTED, APPROVED, DISPATCHED, etc.
- `total_amount`, `tax_amount`, `discount_amount`, `shipping_amount`
- `paid_amount`, `due_amount`
- `created_at`, `updated_at`

### sale_details
Line items for sales orders.

**Key Fields:**
- `id` - Primary key
- `sale_id` - Foreign key to sales
- `product_id` - Foreign key to products
- `quantity` - Integer
- `unit_price`, `sub_total`, `product_tax_amount`, `product_discount_amount`
- `serial_number_ids` - JSON array of serial number IDs

### dispatch_details
Tracks product dispatches with serial numbers.

**Key Fields:**
- `id` - Primary key
- `sale_id` - Foreign key to sales
- `product_id` - Foreign key to products
- `dispatch_id` - Foreign key to dispatches
- `serial_numbers` - JSON array of serial numbers
- `quantity` - Integer
- `created_at`

### product_serial_numbers
Master table for all serial numbers.

**Key Fields:**
- `id` - Primary key
- `serial_number` - Unique serial number string
- `product_id` - Foreign key to products
- `location_id` - Foreign key to locations
- `status` - Current status of the serial
- `tax_id` - Foreign key to tax rates
- `created_at`, `updated_at`

## Audit and Logging Tables

### global_sales_searches
Tracks all global sales search operations for audit purposes.

**Fields:**
- `id` - Primary key
- `user_id` - Foreign key to users
- `setting_id` - Foreign key to settings (tenant)
- `search_query` - JSON: original search parameters
- `filters_applied` - JSON: applied filters (now simplified)
- `results_count` - Integer: number of results returned
- `response_time_ms` - Integer: search execution time
- `created_at`

## Related Tables

### customers
Customer information.

### products
Product catalog.

### locations
Warehouse/storage locations.

### settings
Multi-tenant business units.

### users
System users (sellers, etc.).

## Key Relationships

```
sales (1) ──── (N) sale_details
sales (1) ──── (N) dispatch_details
products (1) ──── (N) product_serial_numbers
locations (1) ──── (N) product_serial_numbers
settings (1) ──── (N) sales (tenant isolation maintained for audit)
users (1) ──── (N) global_sales_searches
```

## Indexes and Performance

### Required Indexes
```sql
-- Sales table indexes
CREATE INDEX idx_sales_reference ON sales(reference);
CREATE INDEX idx_sales_setting_id ON sales(setting_id);
CREATE INDEX idx_sales_status ON sales(status);
CREATE INDEX idx_sales_created_at ON sales(created_at);
CREATE INDEX idx_sales_customer_id ON sales(customer_id);

-- Serial numbers search (critical for performance)
CREATE INDEX idx_dispatch_details_serials ON dispatch_details((JSON_EXTRACT(serial_numbers, '$')));

-- Product serial numbers
CREATE INDEX idx_product_serial_numbers_serial ON product_serial_numbers(serial_number);
CREATE INDEX idx_product_serial_numbers_product ON product_serial_numbers(product_id);
CREATE INDEX idx_product_serial_numbers_location ON product_serial_numbers(location_id);

-- Audit logging
CREATE INDEX idx_global_sales_searches_user ON global_sales_searches(user_id);
CREATE INDEX idx_global_sales_searches_setting ON global_sales_searches(setting_id);
CREATE INDEX idx_global_sales_searches_created ON global_sales_searches(created_at);
```

## Query Patterns

### Global Serial Number Search
```sql
SELECT s.* FROM sales s
JOIN dispatch_details dd ON s.id = dd.sale_id
WHERE JSON_SEARCH(dd.serial_numbers, 'one', 'SN001234') IS NOT NULL
-- No tenant filtering for global search
```

### Global Reference Search
```sql
SELECT * FROM sales
WHERE reference LIKE '%query%'
-- No tenant filtering for global search
```

### Global Customer Search
```sql
SELECT s.* FROM sales s
JOIN customers c ON s.customer_id = c.id
WHERE c.customer_name LIKE '%query%'
-- No tenant filtering for global search
```

## Data Integrity

### Foreign Key Constraints
- All foreign keys should have proper constraints
- Cascading deletes should be carefully considered
- Global search maintains audit trail with tenant context

### JSON Data Validation
- `serial_number_ids` in `sale_details` must be valid JSON arrays
- `serial_numbers` in `dispatch_details` must be valid JSON arrays
- Application code validates JSON structure before insertion

## Migration History

### Recent Migrations
- `2025_11_08_181647_create_global_sales_search_permission.php` - Adds globalSalesSearch.access permission
- Various sales-related migrations for core functionality
- Serial number tracking migrations

## Backup and Recovery

### Critical Data
- `sales` and `sale_details` - Core business transactions
- `product_serial_numbers` - Inventory tracking
- `global_sales_searches` - Audit trail (can be archived)

### Point-in-time Recovery
- Full database backups recommended
- Transaction log backups for point-in-time recovery
- Test restore procedures regularly

## Global Search Considerations

### Performance Impact
- Global searches scan all tenants, requiring optimized queries
- JSON_SEARCH operations need proper indexing
- Consider implementing Elasticsearch for large datasets

### Security Considerations
- Audit logging captures tenant context for compliance
- Permission-based access controls global search capability
- No data leakage between tenants despite global search scope

### Scalability
- Pagination limits result sets
- Response time monitoring identifies performance bottlenecks
- Database optimization crucial for multi-tenant global searches</content>
<parameter name="filePath">/home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/ai-docs/global-menu/DATABASE.md