# Global Sales Search Implementation Documentation

## Overview

The Global Sales Search feature provides a streamlined search interface for tracking and managing sales orders based on serial numbers. It allows users to search across multiple criteria including serial numbers, sale references, customer information, and works across all tenants without restrictions.

**URL:** `http://localhost:8000/global-sales-search`

## Architecture

### Core Components

#### 1. Livewire Component: `GlobalSalesSearch`
**Location:** `Modules/Sale/Http/Livewire/GlobalSalesSearch.php`

**Properties:**
```php
public string $query = '';
public string $searchType = 'all'; // all, serial, reference, customer
public array $searchResultsData = [];
public array $paginationInfo = [];
public int $perPage = 20;
public string $sortBy = 'created_at';
public string $sortDirection = 'desc';
```

**Key Methods:**
- `updatedQuery()` - Triggers search on input change
- `performSearch()` - Executes the actual search
- `buildSearchFilters()` - Constructs filter array based on search type
- `sortBy()` - Handles column sorting
- `gotoPage()` - Pagination navigation
- `clearSearch()` - Resets search state

#### 2. Services

**SerialNumberSearchService** (`Modules/Sale/Services/SerialNumberSearchService.php`)
- Core search logic for finding sales by serial numbers
- Supports global search without tenant restrictions
- Methods: `buildQuery()` with null tenant filtering

**SalesOrderFormatter** (`Modules/Sale/Services/SalesOrderFormatter.php`)
- Formats sale data for different display contexts
- Handles serialization of complex sale relationships

## API Endpoints

### Web Routes
```php
Route::get('/global-sales-search', [GlobalSalesSearchController::class, 'index'])->name('global-sales-search.index');
```

### API Routes (Prefix: `/api/global-sales-search`)
```php
Route::get('/suggest', [GlobalSalesSearchController::class, 'suggest']);
```

## Views and Templates

### Main Interface
- `Modules/Sale/Resources/views/livewire/global-sales-search.blade.php` - Complete search interface

### Features
- Real-time search with 300ms debounce
- Search type selector (All/Serial/Reference/Customer)
- Sortable results table with pagination
- Export button (placeholder for future implementation)
- Keyboard shortcuts (Ctrl+Shift+S to focus search)

## Database Models and Resources

### Models
- `App\Models\GlobalSalesSearch` - Audit trail for searches
- `Modules\Sale\Entities\Sale` - Main sales entity
- `Modules\Product\Entities\ProductSerialNumber` - Serial number tracking

## Search Functionality

### Search Types
1. **All** - Search across serial numbers, references, and customers
2. **Serial** - Exact serial number matching
3. **Reference** - Sales order reference numbers
4. **Customer** - Customer name matching

### Search Logic
- Uses JSON_SEARCH for serial number matching in `dispatch_details.serial_numbers`
- OR logic for multiple search criteria within selected type
- Global search - no tenant filtering applied
- Sorting by reference, status, or date
- Pagination with configurable page size

## Key Features

### Real-time Search
- Livewire-powered reactive search
- Debounced input (300ms) to prevent excessive API calls
- Instant results display

### Global Search
- Searches across all tenants without restrictions
- No tenant-based filtering in queries
- Comprehensive results from entire system

### Simplified Interface
- Removed complex filtering options
- Clean, focused search experience
- Always-visible search controls

### Audit Trail
- All searches logged to `global_sales_searches` table
- Tracks user, query, filters, results count, and response time

### Serial Number Tracking
- Detailed serial number information
- Associated sales history
- Status tracking (allocated, dispatched, returned)

## Performance Considerations

### Query Optimization
- Uses database indexes on frequently searched columns
- JSON_SEARCH for efficient serial number lookups
- Eager loading of relationships to prevent N+1 queries

### Response Time Monitoring
- Response times logged for performance analysis
- Target: <500ms for typical searches

## Error Handling

### Validation
- Input validation for search queries
- Comprehensive validation rules for all parameters

### Exception Handling
- Try-catch blocks in all search methods
- Detailed error logging with context
- User-friendly error messages

## Testing and Validation

### Feature Tests
- API endpoint testing for correct responses
- Livewire component testing
- Integration tests for complete workflows

## Future Enhancements

### Planned Features
- Export functionality (Excel, CSV)
- Advanced reporting and analytics
- Bulk operations on search results
- Enhanced autocomplete with suggestions
- Mobile-responsive optimization

### Performance Improvements
- Redis caching for frequently accessed data
- Database query optimization and indexing
- Search result caching

## Dependencies

### PHP Packages
- Laravel Livewire
- Laravel Sanctum (API authentication)

### Database Requirements
- MySQL 8.0+ (JSON functions)
- Proper indexing on search columns
- Foreign key constraints for data integrity

## Configuration

### Environment Variables
- Standard Laravel configuration
- Database connection settings
- Cache and session drivers

### Module Registration
- Registered in `SaleServiceProvider`
- Routes loaded in module-specific route files
- Livewire components registered

## Troubleshooting

### Common Issues
1. **No search results** - Check database connectivity and JSON functions
2. **Slow performance** - Check database indexes and query optimization
3. **Permission denied** - Verify user has required permissions

### Debug Information
- Extensive logging in search methods
- Response time tracking
- Query debugging with `toSql()` and bindings

## Maintenance

### Regular Tasks
- Monitor search performance metrics
- Clean up old audit logs
- Update search indexes as needed
- Review and optimize slow queries

### Code Quality
- Comprehensive PHPDoc documentation
- PSR-12 coding standards
- Unit test coverage for critical paths</content>
<parameter name="filePath">/home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/ai-docs/global-menu/README.md