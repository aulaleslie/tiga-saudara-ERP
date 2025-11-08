# Global Sales Search Troubleshooting Guide

## Overview

This guide provides solutions to common issues encountered with the Global Sales Search feature. The Global Sales Search provides a streamlined interface for searching sales orders across all tenants without restrictions.

## Common Issues and Solutions

### 1. No Search Results Found

**Symptoms:**
- Search returns empty results
- Loading indicator shows but no data appears

**Possible Causes:**
- Database connection issues
- Missing database indexes
- JSON data corruption in serial_numbers field

**Solutions:**
```bash
# Check database connectivity
php artisan tinker
DB::connection()->getPdo();

# Verify indexes exist
php artisan tinker
Schema::hasTable('dispatch_details');
# Check for JSON search index on serial_numbers column

# Clear application cache
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

**Debug Query:**
```php
// Test basic search query
$sales = DB::table('sales')
    ->join('dispatch_details', 'sales.id', '=', 'dispatch_details.sale_id')
    ->whereRaw("JSON_SEARCH(dispatch_details.serial_numbers, 'one', ?) IS NOT NULL", ['SN001234'])
    ->get();
```

### 2. Slow Search Performance

**Symptoms:**
- Search takes longer than 500ms
- High server CPU usage during searches

**Possible Causes:**
- Missing database indexes
- Large dataset without pagination
- Inefficient JSON_SEARCH queries

**Solutions:**
```sql
-- Ensure required indexes exist
CREATE INDEX idx_dispatch_details_serials ON dispatch_details((JSON_EXTRACT(serial_numbers, '$')));
CREATE INDEX idx_sales_reference ON sales(reference);
CREATE INDEX idx_sales_created_at ON sales(created_at);
CREATE INDEX idx_sales_customer_id ON sales(customer_id);
```

**Performance Monitoring:**
```php
// Add to GlobalSalesSearch.php performSearch method
$startTime = microtime(true);
// ... search logic ...
$endTime = microtime(true);
$responseTime = ($endTime - $startTime) * 1000;
Log::info('Search performance', ['response_time_ms' => $responseTime]);
```

### 3. Permission Denied Errors

**Symptoms:**
- 403 Forbidden error when accessing the feature
- User cannot see the global sales search page

**Possible Causes:**
- Missing `globalSalesSearch.access` permission
- User not assigned to correct role
- Permission cache not cleared

**Solutions:**
```bash
# Check user permissions
php artisan tinker
$user = User::find(1);
$user->hasPermissionTo('globalSalesSearch.access');

# Clear permission cache
php artisan cache:clear
php artisan permission:cache-reset

# Assign permission if missing
php artisan tinker
$user = User::find(1);
$user->givePermissionTo('globalSalesSearch.access');
```

### 4. JavaScript/Autocomplete Not Working

**Symptoms:**
- Search input not responding
- No autocomplete suggestions
- Keyboard shortcuts not working

**Possible Causes:**
- JavaScript files not loaded
- Livewire scripts not initialized
- Missing API endpoints

**Solutions:**
```javascript
// Check browser console for errors
// Verify API endpoint exists
GET /api/global-sales-search/suggest?q=test&type=all

// Check Livewire initialization
document.addEventListener('livewire:loaded', () => {
    console.log('Livewire loaded successfully');
});
```

**Debug Code:**
```php
// Add to GlobalSalesSearch component
public function getSuggestions(): Collection
{
    Log::info('Suggestions requested', [
        'query' => $this->query,
        'type' => $this->searchType
    ]);

    // ... existing code ...
}
```

### 5. Pagination Not Working

**Symptoms:**
- Results don't paginate correctly
- Page numbers don't change results
- "Next/Previous" buttons not working

**Possible Causes:**
- Livewire pagination state corruption
- Incorrect pagination setup
- Missing pagination links

**Solutions:**
```php
// Reset pagination on new search
public function updatedQuery(): void
{
    $this->resetPage(); // This is crucial
    $this->performSearch();
}

public function gotoPage($page): void
{
    $this->setPage($page);
    $this->performSearch();
}
```

### 6. Global Search Not Working (Tenant Issues)

**Symptoms:**
- Search only returns results from current tenant
- Cross-tenant results not appearing

**Possible Causes:**
- Tenant filtering still active in service layer
- Incorrect service method parameters

**Solutions:**
```php
// Verify service call in GlobalSalesSearch.php
$query = $this->searchService->buildQuery($searchFilters, null);
// Second parameter should be null for global search

// Check SerialNumberSearchService.php
public function buildQuery(array $filters, ?int $settingId = null): Builder
{
    $query = Sale::query();
    // ... logic ...
    if ($settingId !== null) {
        $query->where('setting_id', $settingId); // Only apply if not null
    }
}
```

### 7. Export Functionality Not Working

**Symptoms:**
- Export button shows but doesn't work
- "Export functionality will be implemented in Phase 5" message

**Status:**
- This is expected behavior
- Export functionality is planned for future implementation
- Button serves as placeholder for upcoming feature

### 8. Mobile Responsiveness Issues

**Symptoms:**
- Interface not displaying correctly on mobile
- Tables not scrolling horizontally
- Buttons too small for touch

**Solutions:**
```css
/* Add to custom CSS if needed */
@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }

    .btn {
        min-height: 44px; /* iOS touch target size */
    }
}
```

## Debug Tools

### Enable Debug Logging
```php
// In GlobalSalesSearch.php
Log::info('Search debug', [
    'query' => $this->query,
    'searchType' => $this->searchType,
    'results_count' => count($this->searchResultsData),
    'response_time' => $responseTime
]);
```

### Database Query Debugging
```php
// Add to performSearch method
$query = $this->searchService->buildQuery($searchFilters, null);
Log::info('Generated SQL', [
    'sql' => $query->toSql(),
    'bindings' => $query->getBindings()
]);
```

### Performance Profiling
```php
// Use Laravel Debugbar or add custom profiling
$startTime = microtime(true);
// ... code to profile ...
$endTime = microtime(true);
Log::info('Performance', [
    'operation' => 'search',
    'duration_ms' => ($endTime - $startTime) * 1000
]);
```

## Maintenance Tasks

### Regular Monitoring
- Check search response times daily
- Monitor database index usage
- Review error logs weekly
- Clean up old audit logs monthly

### Performance Optimization
- Rebuild database indexes quarterly
- Analyze slow query logs
- Update statistics regularly
- Consider Elasticsearch for large datasets

### Backup and Recovery
- Include audit tables in backups
- Test restore procedures
- Archive old logs to separate storage
- Maintain disaster recovery plans

## Emergency Contacts

### Development Team
- Contact development team for code-related issues
- Provide error logs and debug information
- Include steps to reproduce the issue

### System Administration
- Contact sysadmin for server-related issues
- Database connectivity problems
- Performance degradation

## Version-specific Issues

### Version 2.0.0
- Simplified interface may cause confusion for users accustomed to filters
- Global search may return unexpected results from other tenants
- Export functionality not yet implemented

### Migration from 1.0.0
- Ensure all users have new permissions assigned
- Clear all caches after deployment
- Test search functionality thoroughly
- Monitor performance for the first week

## Prevention Best Practices

### Code Quality
- Add comprehensive error handling
- Implement proper logging
- Write unit tests for critical functions
- Use database transactions for data integrity

### Monitoring
- Set up alerts for slow queries
- Monitor error rates
- Track user adoption metrics
- Regular performance benchmarking

### Documentation
- Keep troubleshooting guides updated
- Document known issues and workarounds
- Maintain change logs
- Update user manuals