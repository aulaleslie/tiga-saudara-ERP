# Migration Strategy for Audit Table

## Current State
- Existing table: `global_sales_searches`
- Tracks sales-specific searches only
- Fields: user_id, setting_id, search_query, filters_applied, results_count, response_time_ms, search_type, timestamps

## Requirements for Global Search
- Track both purchase and sales searches
- Include transaction type information
- Maintain tenant context for compliance
- Support all search types: serial, purchase_ref, sales_ref, supplier, customer, all

## Migration Options

### Option 1: Extend Existing Table
**Pros:**
- Single audit table for all searches
- Easier reporting and analytics
- Less database maintenance

**Cons:**
- Schema changes required
- Potential data migration needed
- Backward compatibility concerns

**Changes Needed:**
```sql
ALTER TABLE global_sales_searches
ADD COLUMN transaction_type ENUM('purchase', 'sale') DEFAULT 'sale',
ADD COLUMN tenant_context VARCHAR(255) NULL,
MODIFY COLUMN search_type ENUM('serial', 'purchase_ref', 'sales_ref', 'supplier', 'customer', 'all') DEFAULT 'serial';
```

### Option 2: Create New Table
**Pros:**
- Clean separation of concerns
- No impact on existing sales search auditing
- Easier rollback if needed
- Future extensibility

**Cons:**
- Duplicate audit logic
- More complex reporting queries
- Additional database maintenance

**New Table Schema:**
```sql
CREATE TABLE global_purchase_and_sales_searches (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    setting_id BIGINT,
    search_query VARCHAR(255) NULL,
    search_type VARCHAR(255), -- serial, purchase_ref, sales_ref, supplier, customer, all
    transaction_types JSON NULL, -- ['purchase', 'sale'] for combined searches
    filters_applied JSON NULL,
    results_count INT DEFAULT 0,
    response_time_ms INT DEFAULT 0,
    tenant_context VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (setting_id) REFERENCES settings(id)
);
```

## Recommended Approach: Option 2 (New Table)

### Rationale
1. **Separation of Concerns**: Sales searches and global searches have different scopes
2. **Risk Mitigation**: No impact on existing sales audit functionality
3. **Future-Proofing**: Can evolve independently
4. **Compliance**: Dedicated audit trail for global searches

### Migration Plan

#### Phase 1: Create New Table
```php
// Migration: create_global_purchase_and_sales_searches_table.php
Schema::create('global_purchase_and_sales_searches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('setting_id')->constrained('settings')->cascadeOnDelete();
    $table->string('search_query')->nullable();
    $table->string('search_type'); // serial, purchase_ref, sales_ref, supplier, customer, all
    $table->json('transaction_types')->nullable(); // For 'all' searches
    $table->json('filters_applied')->nullable();
    $table->integer('results_count')->default(0);
    $table->integer('response_time_ms')->default(0);
    $table->string('tenant_context')->nullable();
    $table->string('ip_address', 45)->nullable();
    $table->text('user_agent')->nullable();
    $table->timestamps();

    // Indexes
    $table->index('user_id');
    $table->index('setting_id');
    $table->index('search_type');
    $table->index('created_at');
    $table->index(['user_id', 'created_at']);
    $table->index(['setting_id', 'created_at']);
    $table->index(['search_type', 'created_at']);
});
```

#### Phase 2: Create Indexes Migration
```php
// Migration: add_search_indexes_for_global_search.php
Schema::table('purchases', function (Blueprint $table) {
    $table->index('reference');
    $table->index('supplier_id');
    $table->index('created_at');
    $table->index('setting_id');
});

Schema::table('sales', function (Blueprint $table) {
    $table->index('reference');
    $table->index('customer_id');
    $table->index('created_at');
    $table->index('setting_id');
});

// JSON index for sales serial numbers (MySQL 8.0+)
DB::statement('ALTER TABLE dispatch_details ADD INDEX idx_serial_numbers ((CAST(serial_numbers AS CHAR(255))))');

// Index for purchase serial numbers
Schema::table('product_serial_numbers', function (Blueprint $table) {
    $table->index('serial_number');
    $table->index('received_note_detail_id');
});
```

#### Phase 3: Update Permissions
```php
// Migration: add_global_purchase_and_sales_search_permission.php
// Add permission: globalPurchaseAndSalesSearch.access
```

#### Phase 4: Data Seeding
```php
// Seeder: add_global_search_permission_seeder.php
// Seed the new permission into roles/permissions tables
```

## Rollback Strategy

### Safe Rollback
1. **Drop new table**: `global_purchase_and_sales_searches`
2. **Remove indexes**: Drop added indexes (check if they existed before)
3. **Remove permissions**: Delete permission records
4. **Clear cache**: Clear Laravel permission cache

### Data Preservation
- No existing data modification
- Audit data remains intact in original table
- New audit data is isolated

## Testing Strategy

### Pre-Migration Tests
1. Verify existing sales search auditing works
2. Backup database
3. Test migration on staging environment

### Post-Migration Tests
1. Verify new table created with correct schema
2. Test permission system
3. Verify indexes improve query performance
4. Test audit logging functionality

## Performance Considerations

### Index Impact
- **Positive**: Faster searches on reference, supplier/customer, dates
- **Negative**: Slight insert/update overhead
- **Storage**: Minimal additional storage for indexes

### Query Optimization
- Use EXPLAIN to verify index usage
- Monitor slow query log
- Consider composite indexes if needed

## Compliance and Security

### Audit Fields
- `tenant_context`: For multi-tenant compliance
- `ip_address`: Track user location/network
- `user_agent`: Browser/client information
- `created_at`: Timestamp with timezone

### Data Retention
- Implement retention policy (e.g., 7 years)
- Archive old audit data
- Regular cleanup jobs

## Implementation Timeline

1. **Week 1**: Create and test migrations
2. **Week 2**: Implement audit service and logging
3. **Week 3**: Add permissions and security
4. **Week 4**: Performance testing and optimization</content>
<parameter name="filePath">/home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/ai-docs/global-purchase-and-sales-search/audit-migration-plan.md