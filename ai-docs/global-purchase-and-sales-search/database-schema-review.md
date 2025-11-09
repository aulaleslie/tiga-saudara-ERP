# Database Schema Review - Purchase and Sales Modules

## Purchase Module Schema

### purchases table
```sql
CREATE TABLE purchases (
    id BIGINT PRIMARY KEY,
    date DATE,
    reference VARCHAR(255),
    supplier_id BIGINT NULL,
    supplier_name VARCHAR(255), -- Deprecated, use supplier_id
    tax_percentage INT DEFAULT 0,
    tax_amount DECIMAL(15,2),
    discount_percentage INT DEFAULT 0,
    discount_amount DECIMAL(15,2),
    shipping_amount DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2),
    paid_amount DECIMAL(15,2),
    due_amount DECIMAL(15,2),
    status VARCHAR(255),
    payment_status VARCHAR(255),
    payment_method VARCHAR(255),
    note TEXT NULL,
    setting_id BIGINT, -- Tenant isolation
    due_date DATE NULL,
    tax_id BIGINT NULL,
    payment_term_id BIGINT NULL,
    is_tax_included BOOLEAN DEFAULT FALSE,
    supplier_reference_no VARCHAR(255) NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);
```

### purchase_details table
```sql
CREATE TABLE purchase_details (
    id BIGINT PRIMARY KEY,
    purchase_id BIGINT,
    product_id BIGINT,
    product_name VARCHAR(255),
    product_code VARCHAR(255),
    quantity INT,
    unit_price DECIMAL(15,2),
    price DECIMAL(15,2),
    product_discount_type VARCHAR(255),
    product_discount_amount DECIMAL(15,2),
    sub_total DECIMAL(15,2),
    product_tax_amount DECIMAL(15,2),
    tax_id BIGINT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id)
);
```

### received_notes table
```sql
CREATE TABLE received_notes (
    id BIGINT PRIMARY KEY,
    po_id BIGINT, -- Purchase Order ID
    external_delivery_number VARCHAR(255) NULL,
    date DATE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchases(id)
);
```

### received_note_details table
```sql
CREATE TABLE received_note_details (
    id BIGINT PRIMARY KEY,
    received_note_id BIGINT,
    po_detail_id BIGINT, -- Links to purchase_details
    quantity_received INT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (received_note_id) REFERENCES received_notes(id),
    FOREIGN KEY (po_detail_id) REFERENCES purchase_details(id)
);
```

## Sales Module Schema

### sales table
```sql
CREATE TABLE sales (
    id BIGINT PRIMARY KEY,
    date DATE,
    reference VARCHAR(255),
    customer_id BIGINT NULL,
    customer_name VARCHAR(255), -- Deprecated, use customer_id
    tax_percentage INT DEFAULT 0,
    tax_amount DECIMAL(15,2),
    discount_percentage INT DEFAULT 0,
    discount_amount DECIMAL(15,2),
    shipping_amount DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2),
    paid_amount DECIMAL(15,2),
    due_amount DECIMAL(15,2),
    status VARCHAR(255),
    payment_status VARCHAR(255),
    payment_method VARCHAR(255),
    note TEXT NULL,
    setting_id BIGINT, -- Tenant isolation
    due_date DATE NULL,
    is_tax_included BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id)
);
```

### sale_details table
```sql
CREATE TABLE sale_details (
    id BIGINT PRIMARY KEY,
    sale_id BIGINT,
    product_id BIGINT,
    product_name VARCHAR(255),
    product_code VARCHAR(255),
    quantity INT,
    unit_price DECIMAL(15,2),
    price DECIMAL(15,2),
    product_discount_type VARCHAR(255),
    product_discount_amount DECIMAL(15,2),
    sub_total DECIMAL(15,2),
    product_tax_amount DECIMAL(15,2),
    tax_id BIGINT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id)
);
```

### dispatch_details table
```sql
CREATE TABLE dispatch_details (
    id BIGINT PRIMARY KEY,
    dispatch_id BIGINT,
    sale_id BIGINT,
    product_id BIGINT,
    dispatched_quantity INT,
    location_id BIGINT NULL,
    serial_numbers JSON NULL, -- Array of serial numbers
    tax_id BIGINT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (dispatch_id) REFERENCES dispatches(id),
    FOREIGN KEY (sale_id) REFERENCES sales(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (location_id) REFERENCES locations(id)
);
```

## Serial Number Storage

### Sales Serial Numbers
- **Location**: `dispatch_details.serial_numbers` (JSON array)
- **Example**: `["SN001", "SN002", "SN003"]`
- **Search Query**: `JSON_SEARCH(serial_numbers, 'one', ?) IS NOT NULL`

### Purchase Serial Numbers
- **Location**: `product_serial_numbers.serial_number` (string per record)
- **Linked via**: `received_note_details` → `product_serial_numbers`
- **Search Query**: Join through received note details

## Audit Table Schema

### global_sales_searches table (existing)
```sql
CREATE TABLE global_sales_searches (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    setting_id BIGINT,
    search_query VARCHAR(255) NULL,
    filters_applied JSON NULL,
    results_count INT DEFAULT 0,
    response_time_ms INT DEFAULT 0,
    search_type VARCHAR(255) DEFAULT 'serial',
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (setting_id) REFERENCES settings(id)
);
```

### Required: global_purchase_and_sales_searches table (new)
```sql
CREATE TABLE global_purchase_and_sales_searches (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    setting_id BIGINT,
    search_query VARCHAR(255) NULL,
    search_type VARCHAR(255), -- serial, purchase_ref, sales_ref, supplier, customer, all
    filters_applied JSON NULL,
    results_count INT DEFAULT 0,
    response_time_ms INT DEFAULT 0,
    tenant_context VARCHAR(255) NULL, -- For compliance
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (setting_id) REFERENCES settings(id)
);
```

## Required Indexes

### Purchase Indexes
```sql
-- For reference searches
CREATE INDEX idx_purchases_reference ON purchases(reference);
CREATE INDEX idx_purchases_created_at ON purchases(created_at);
CREATE INDEX idx_purchases_setting_id ON purchases(setting_id);

-- For supplier searches
CREATE INDEX idx_purchases_supplier_id ON purchases(supplier_id);
```

### Sales Indexes
```sql
-- For reference searches
CREATE INDEX idx_sales_reference ON sales(reference);
CREATE INDEX idx_sales_created_at ON sales(created_at);
CREATE INDEX idx_sales_setting_id ON sales(setting_id);

-- For customer searches
CREATE INDEX idx_sales_customer_id ON sales(customer_id);
```

### Serial Number Indexes
```sql
-- For sales serial number searches (JSON)
CREATE INDEX idx_dispatch_details_serial_numbers ON dispatch_details((CAST(serial_numbers AS CHAR(255))));

-- For purchase serial number searches
CREATE INDEX idx_product_serial_numbers_serial ON product_serial_numbers(serial_number);
CREATE INDEX idx_product_serial_numbers_received_note_detail_id ON product_serial_numbers(received_note_detail_id);
```

## Schema Analysis

### Key Findings
1. **Tenant Isolation**: Both purchases and sales use `setting_id` for multi-tenancy
2. **Serial Storage**: Different approaches (JSON vs normalized table)
3. **Reference Patterns**: Both use auto-generated references with prefixes
4. **Status Tracking**: Similar status fields for both modules
5. **Audit Ready**: Existing audit table can be extended or new one created

### Compatibility Issues
1. **Serial Number Queries**: Need different query strategies for purchases vs sales
2. **Party Names**: Both have deprecated name fields, should use ID relationships
3. **Amount Fields**: Recently converted to DECIMAL for precision

### Migration Strategy
1. Create new audit table: `global_purchase_and_sales_searches`
2. Add required indexes for search performance
3. Consider extending existing audit table vs creating new one
4. Ensure backward compatibility with existing data</content>
<parameter name="filePath">/home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/ai-docs/global-purchase-and-sales-search/database-schema-review.md