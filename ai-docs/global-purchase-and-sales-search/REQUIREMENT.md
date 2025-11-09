# Global Purchase and Sales Search Requirements

## Overview

The Global Purchase and Sales Search feature provides a unified search interface for tracking and managing both purchase orders and sales orders based on serial numbers and reference numbers. It allows users to search across multiple criteria including serial numbers, purchase/sales references, supplier/customer information, and works across all tenants without restrictions.

**URL:** `http://localhost:8000/global-purchase-and-sales-search`

## Business Objectives

1. **Unified Visibility** - Single search interface to find both purchase and sales transactions
2. **Cross-Tenant Search** - Search across all business units without restrictions
3. **Quick Lookup** - Rapidly locate specific transactions by serial number, reference, or party name
4. **Audit Trail** - Maintain comprehensive audit logging of all searches
5. **Inventory Tracking** - Track serial numbers through purchase receipt to sales dispatch

## Functional Requirements

### F1: Search Capability

#### F1.1 Multi-Type Search
- Users can search by:
  - **Serial Numbers** - Product serial numbers from both purchases and sales
  - **Purchase References** - Purchase order reference numbers (e.g., PO-2025-001)
  - **Sales References** - Sales order reference numbers (e.g., SO-2025-001)
  - **Supplier Name** - Search by supplier/vendor name
  - **Customer Name** - Search by customer name
  - **All** - Combined search across all fields

#### F1.2 Global Search Scope
- Searches must return results from all tenants without tenant-based filtering
- Maintain tenant context in audit logs for compliance
- No data should be hidden based on tenant isolation in results

#### F1.3 Real-Time Search
- Livewire-powered reactive search on input change
- 300ms debounce to prevent excessive API calls
- Instant results display with loading indicators

### F2: Results Display

#### F2.1 Purchase Results
- Transaction Type: Purchase Order
- Reference Number (clickable hyperlink with `target="_blank"` to open purchase detail in new window)
- Supplier Name
- Serial Numbers Count
- Total Amount
- Status (color-coded badge)
- Location
- Date Created
- Tenant Information

#### F2.2 Sales Results
- Transaction Type: Sales Order
- Reference Number (clickable hyperlink with `target="_blank"` to open sales detail in new window)
- Customer Name
- Serial Numbers Count
- Total Amount
- Status (color-coded badge)
- Location
- Seller Name
- Date Created
- Tenant Information

#### F2.3 Mixed Results
- Results displayed together in a single table
- Transaction type clearly identified (Purchase/Sale)
- Sortable by all columns except transaction type
- Color-coded to distinguish between purchase and sales items

### F3: Sorting and Pagination

#### F3.1 Sorting
- Sortable columns: Reference, Amount, Status, Date
- Default: Sort by created_at (descending)
- Users can change sort column and direction
- Sort direction toggles between ascending and descending

#### F3.2 Pagination
- Configurable page size (default: 20 per page)
- Total results count displayed
- Page navigation controls
- Go-to-page functionality

### F4: User Interface

#### F4.1 Search Interface
- Clean, streamlined search experience
- Search type selector prominently displayed
- Clear button to reset search state
- Keyboard shortcut: Ctrl+Shift+A (similar to sales search)

#### F4.2 Responsive Design
- Mobile-friendly layout
- Responsive table for different screen sizes
- Touch-friendly buttons and controls
- Desktop and tablet optimization

#### F4.3 Accessibility
- ARIA labels for form inputs
- Semantic HTML structure
- Keyboard navigation support
- Screen reader compatibility

### F5: Data Handling

#### F5.1 Serial Number Matching
- JSON_SEARCH for efficient serial number lookups in dispatch_details and purchase_details
- Support for partial serial number matching
- Display complete serial number history (from purchase to sale)

#### F5.2 Search Logic
- OR logic for multiple criteria within selected search type
- AND logic when combining filters
- Case-insensitive search

#### F5.3 Data Accuracy
- Ensure serial numbers are properly tracked through purchase receipt and sales dispatch
- Prevent duplicate results when serial number appears in both purchase and sale
- Account for inventory location changes

#### F5.4 Clickable Reference Links
- Reference numbers must be rendered as hyperlinks using `route()` helper
- Purchase references link to: `route('purchases.show', $id)` 
- Sales references link to: `route('sales.show', $id)`
- All links open in new window using `target="_blank"` attribute
- Implementation follows pattern from `SalesDataTable` and `PurchaseDataTable`
- Links styled with `class="text-primary"` for visual consistency
- Rendered as raw HTML with `rawColumns(['reference_hyperlink'])` in Livewire

### F6: Performance Requirements

#### F6.1 Search Response Time
- Target: <500ms for typical searches
- Monitor and log all search response times
- Optimize queries for searches returning 100+ results

#### F6.2 Concurrent Users
- Support 50+ simultaneous users
- Maintain performance under load
- Handle large result sets (10,000+ records) efficiently

#### F6.3 Optimization
- Database indexes on frequently searched columns
- Pagination to limit result set size
- Query optimization and eager loading
- Consider caching for frequently accessed data

### F7: Security and Compliance

#### F7.1 Authentication and Authorization
- All searches require authenticated user
- Permission: `globalPurchaseAndSalesSearch.access`
- User must have viewing permissions for both purchase and sales modules

#### F7.2 Audit Logging
- Log all search operations to `global_purchase_and_sales_searches` table
- Track user, search query, filters applied, result count, response time
- Maintain audit trail for compliance requirements

#### F7.3 Data Validation
- Comprehensive input validation for search queries
- Sanitize search terms to prevent injection
- Validate search type parameter
- Validate pagination parameters

#### F7.4 Error Handling
- Graceful error handling for database failures
- User-friendly error messages
- Detailed error logging with context
- Try-catch blocks for all critical operations

### F8: Integration

#### F8.1 Backend Integration
- Integrate with existing Purchase module
- Extend existing Sale module search capabilities
- Utilize SerialNumberSearchService for search logic
- Create new service or extend existing to handle both purchase and sales searches

#### F8.2 API Endpoints
- Main web route: `/global-purchase-and-sales-search`
- API suggest endpoint: `/api/global-purchase-and-sales-search/suggest`
- RESTful design following Laravel conventions

#### F8.3 Livewire Integration
- Create `GlobalPurchaseAndSalesSearch` Livewire component
- Register component in service provider
- Implement reactive properties and methods
- Use Livewire events for navigation and actions

## Non-Functional Requirements

### NF1: Performance
- Database queries optimized with proper indexing
- Response time monitoring and logging
- Support for large datasets (10,000+ records)
- Pagination for efficient memory usage

### NF2: Scalability
- Support for future data growth
- Horizontal scaling capability
- Database optimization for multi-tenant environments
- Caching strategy for frequently accessed data

### NF3: Maintainability
- Clean, well-documented code (PSR-12)
- Comprehensive PHPDoc comments
- Modular service-based architecture
- Unit test coverage for critical paths

### NF4: Reliability
- Comprehensive error handling
- Graceful degradation on failures
- Data integrity constraints
- Backup and recovery procedures

### NF5: Security
- CSRF protection on all forms
- Input validation and sanitization
- Permission-based access control
- Audit logging for compliance

## Database Requirements

### DRF1: Tables Required
- Extend `global_sales_searches` OR create new `global_purchase_and_sales_searches` table
- Purchase and Sales tables already exist
- Product serial numbers tracking

### DRF2: Indexes Required
- Index on Purchase reference
- Index on Purchase created_at
- Index on Sales reference
- Index on Sales created_at
- JSON index on serial numbers in both purchase and sales dispatch details
- Index on supplier_id (for Purchase)
- Index on customer_id (for Sales)

### DRF3: JSON Data Structure
- Purchase dispatch details: `{serial_numbers: [...], quantity: N}`
- Sales dispatch details: `{serial_numbers: [...], quantity: N}`

## API Requirements

### API1: Search Suggest Endpoint
- GET `/api/global-purchase-and-sales-search/suggest`
- Query parameters: `q` (search query), `type` (search type)
- Returns suggestions for autocomplete

### API2: Response Format
- JSON format with success status
- Include transaction type (purchase/sale)
- Include tenant information
- Include relevant transaction details

### API3: Error Handling
- Standard HTTP status codes
- Descriptive error messages
- Validation error details

## Frontend Requirements

### FR1: Layout and Design
- Bootstrap 4/5 grid system
- Responsive mobile design
- Consistent with existing Livewire components
- Clean, intuitive interface

### FR2: Interactive Features
- Real-time search with debounce
- Dynamic sorting
- Pagination navigation
- Clear/reset functionality
- Loading indicators
- **Clickable Reference Hyperlinks:**
  - Purchase and sales reference numbers must be clickable hyperlinks
  - Links open in new browser window/tab using `target="_blank"`
  - Purchase links: Route to `purchases.show` with purchase ID
  - Sales links: Route to `sales.show` with sale ID
  - Links styled with `.text-primary` CSS class
  - Follow implementation pattern from existing DataTables:
    - Use `route()` helper for URL generation
    - Create `reference_hyperlink` column in Livewire component
    - Render as HTML using Blade syntax
    - Declare column as raw HTML: `rawColumns(['reference_hyperlink'])`

### FR3: Keyboard Navigation
- Tab order follows logical flow
- Keyboard shortcuts (Ctrl+Shift+A)
- Enter to execute search
- Escape to clear search

## Testing Requirements

### TR1: Unit Tests
- Service layer tests
- Controller action tests
- Model relationship tests

### TR2: Feature Tests
- Complete search workflows
- Permission-based access
- Error scenarios
- Pagination and sorting

### TR3: Integration Tests
- API endpoint testing
- Database integration
- Livewire component behavior
- Real-time search functionality

### TR4: Manual Testing Checklist
- All search types functioning correctly
- Sorting and pagination working
- Mobile responsiveness
- Keyboard shortcuts
- Error scenarios
- Performance under load

## Deployment Requirements

### DR1: Database Migrations
- Create migration for audit table if needed
- Create necessary indexes
- Update permissions table

### DR2: Configuration
- Update module configuration
- Register routes
- Register Livewire component
- Register permissions

### DR3: Seeders
- Seed new permissions
- Update existing permission seeders

### DR4: Documentation
- Create comprehensive README
- API documentation
- Database schema documentation
- Frontend implementation details
- Troubleshooting guide

## Success Criteria

1. **Functionality** - All search types work correctly across both purchase and sales
2. **Performance** - Search response time <500ms for typical queries
3. **User Experience** - Interface is intuitive and responsive
4. **Security** - All access properly controlled and audited
5. **Scalability** - Supports growing data volume efficiently
6. **Maintainability** - Code is well-documented and tested
7. **Reliability** - Handles errors gracefully
8. **Compliance** - Audit trail maintained for all searches

## Related Documentation

- Global Sales Search: `/ai-docs/global-sales-search/`
- Purchase Module: `/Modules/Purchase/`
- Sale Module: `/Modules/Sale/`
- Database Schema: `/database/schema/`

## Future Enhancements

1. **Export Functionality** - Export results to Excel/CSV
2. **Advanced Reporting** - Generate reports from search results
3. **Bulk Operations** - Perform actions on multiple results
4. **Elasticsearch Integration** - High-performance search for large datasets
5. **Serial Number History** - Visual timeline of serial number movement from purchase to sale
6. **Mobile App** - Dedicated mobile search interface
7. **Autocomplete Enhancement** - Intelligent suggestions with machine learning
8. **Performance Analytics** - Dashboard showing search patterns and performance metrics
