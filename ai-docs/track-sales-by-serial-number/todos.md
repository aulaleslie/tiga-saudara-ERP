# Global Menu Feature - Implementation Todos

**Version:** 1.0  
**Sprint:** To Be Assigned  
**Last Updated:** November 8, 2025  
**Estimated Effort:** 21-28 story points

---

## Phase 1: Database & Schema Setup ✅ COMPLETED (4/4 tasks)

### Database Migration Tasks

- [x] **DB-1: Create sales_order_serial_tracking table** ✅
  - Description: Track serial number allocations to sales orders
  - Fields: id, sale_id, product_serial_number_id, quantity_allocated, dispatch_date, return_date, created_at, updated_at
  - Indexes: sale_id, product_serial_number_id, created_at
  - Foreign keys: sale_id → sales.id, product_serial_number_id → product_serial_numbers.id
  - File: `Modules/Sale/Database/Migrations/2025_11_08_120000_create_sales_order_serial_tracking_table.php`
  - Effort: 1 point
  - Status: Migration executed successfully (183ms)

- [x] **DB-2: Add indexes for performance optimization** ✅
  - Description: Add indexes to existing tables for serial number searches
  - Add index on: product_serial_numbers.serial_number
  - Add composite index on: sales.reference, sales.status, sales.created_at
  - Add index on: sale_details.sale_id, sale_details.product_id
  - Add index on: product_serial_numbers.location_id
  - File: `Modules/Sale/Database/Migrations/2025_11_08_120001_add_serial_search_indexes.php`
  - Effort: 1 point
  - Status: Migration executed successfully (241ms)

- [x] **DB-3: Ensure serial_number_ids JSON column exists in sale_details** ✅
  - Description: Verify or create JSON column for storing serial number arrays
  - Check: sale_details table has serial_number_ids column
  - If missing: Create migration to add `serial_number_ids` as JSON column
  - File: `Modules/Sale/Database/Migrations/2025_11_08_120002_add_serial_number_ids_to_sale_details.php`
  - Effort: 1 point
  - Status: Migration executed successfully (26ms)

- [x] **DB-4: Create global_menu_searches audit table** ✅
  - Description: Track all serial number searches for audit/analytics
  - Fields: id, user_id, setting_id, search_query, filters_applied, results_count, response_time_ms, created_at
  - Indexes: user_id, setting_id, created_at
  - File: `Modules/Sale/Database/Migrations/2025_11_08_120003_create_global_menu_searches_table.php`
  - Effort: 1 point
  - Status: Migration executed successfully (206ms)

**Subtotal: 4 points** ✅ **COMPLETED**

---

## Phase 2: Backend - Models & Services

### Model Enhancement Tasks

- [ ] **M-1: Create GlobalMenuSearch model** (optional, for tracking)
  - Description: Model for audit logging of searches
  - File: `app/Models/GlobalMenuSearch.php`
  - Properties: user_id, setting_id, search_query, filters, results_count
  - Relationships: belongsTo User, belongsTo Setting
  - Effort: 1 point

- [ ] **M-2: Add relationships to existing Sale model**
  - Description: Add serialNumbers relationship to Sale model
  - File: `Modules/Sale/Entities/Sale.php`
  - Add method: `serialNumbers()` → hasManyThrough(ProductSerialNumber, SaleDetails)
  - Add method: `seller()` → belongsTo(User) [creator or assigned seller]
  - Add method: `tenantSetting()` → belongsTo(Setting) via location
  - Effort: 2 points

- [ ] **M-3: Add relationships to SaleDetails model**
  - Description: Add serial number collection methods
  - File: `Modules/Sale/Entities/SaleDetails.php`
  - Add method: `serialNumbers()` → hasMany(ProductSerialNumber) via serial_number_ids JSON
  - Add method: Cast serial_number_ids as array
  - Effort: 1 point

### Service Layer Tasks

- [ ] **S-1: Create SerialNumberSearchService**
  - Description: Core search logic for serial numbers
  - File: `Modules/Sale/Services/SerialNumberSearchService.php`
  - Methods:
    - `searchBySerialNumber(string $serial, $settingId, $limit = 50)` → returns paginated Collection
    - `searchBySaleReference(string $reference, $settingId)` → returns Sale|null
    - `searchByCustomer(string $name|$id, $settingId)` → returns Collection
    - `buildQuery($filters)` → returns Query builder
    - `applyTenantFilter(&$query, $settingId)` → applies tenant isolation
  - Effort: 3 points

- [ ] **S-2: Create SalesOrderFormatter service**
  - Description: Format sales data for display with tenant/seller info
  - File: `Modules/Sale/Services/SalesOrderFormatter.php`
  - Methods:
    - `formatForList(Sale $sale)` → returns formatted array
    - `formatForDetail(Sale $sale)` → returns complete details
    - `formatSerialNumbers(SaleDetails $details)` → returns serial details
    - `getTenantInfo(Sale $sale)` → returns Setting/tenant name and ID
    - `getSellerInfo(Sale $sale)` → returns User name, email, location
  - Effort: 2 points

- [ ] **S-3: Create SearchFilterProcessor service** (optional)
  - Description: Validate and process filter criteria
  - File: `Modules/Sale/Services/SearchFilterProcessor.php`
  - Methods:
    - `validateFilters(array $filters)` → returns validated array|throws ValidationException
    - `buildFilterQuery(Query $query, array $filters)` → returns modified Query
  - Effort: 1 point

**Subtotal: 10 points**

---

## Phase 3: Backend - API Endpoints

### API Route & Controller Tasks

- [ ] **API-1: Create GlobalMenuController for API endpoints**
  - Description: RESTful endpoints for serial number search
  - File: `Modules/Sale/Http/Controllers/GlobalMenuController.php`
  - Middleware: auth:sanctum, role.setting (or custom multi-tenant middleware)
  - Methods:
    - `search(Request $request)` → POST /api/global-menu/search
    - `searchByReference(string $reference)` → GET /api/global-menu/sales/{reference}
    - `getSerialDetails(int $serialId)` → GET /api/global-menu/serials/{id}
  - Effort: 2 points

- [ ] **API-2: Create GlobalMenuRequest form requests**
  - Description: Validation rules for API requests
  - File: `Modules/Sale/Http/Requests/GlobalMenuSearchRequest.php`
  - Fields:
    - serial_number (string, nullable)
    - sale_reference (string, nullable)
    - customer_id (int, nullable)
    - customer_name (string, nullable)
    - status (string, nullable, enum)
    - date_from (date, nullable)
    - date_to (date, nullable)
    - location_id (int, nullable)
    - page (int, default 1)
    - per_page (int, max 100, default 20)
  - Effort: 1 point

- [ ] **API-3: Register API routes**
  - Description: Add routes for global menu endpoints
  - File: `Modules/Sale/Routes/api.php`
  - Routes:
    - POST `/api/global-menu/search`
    - GET `/api/global-menu/sales/{reference}`
    - GET `/api/global-menu/serials/{id}`
    - GET `/api/global-menu/suggest` (autocomplete)
  - Effort: 1 point

- [ ] **API-4: Create API Resources for formatting**
  - Description: Format API responses consistently
  - Files:
    - `Modules/Sale/Http/Resources/SaleSearchResource.php`
    - `Modules/Sale/Http/Resources/SerialNumberResource.php`
  - Include: id, reference, customer, tenant, seller, status, date, serial_numbers
  - Effort: 2 points

**Subtotal: 6 points**

---

## Phase 4: Frontend - UI Components

### Web UI Tasks (Livewire/Blade)

- [ ] **UI-1: Create Global Menu Search Component**
  - Description: Livewire component for search interface
  - File: `Modules/Sale/Http/Livewire/GlobalMenuSearch.php`
  - Features:
    - Real-time search input with autocomplete
    - Search type selector (serial, reference, customer, etc.)
    - Quick filter buttons
    - Results pagination
  - Blade template: `Modules/Sale/Resources/views/livewire/global-menu-search.blade.php`
  - Effort: 3 points

- [ ] **UI-2: Create Advanced Filters Component**
  - Description: Livewire component for filter form
  - File: `Modules/Sale/Http/Livewire/GlobalMenuFilters.php`
  - Features:
    - Date range picker
    - Status dropdown
    - Tenant/location selector
    - Product category filter
    - Clear filters button
  - Blade template: `Modules/Sale/Resources/views/livewire/global-menu-filters.blade.php`
  - Effort: 3 points

- [ ] **UI-3: Create Search Results DataTable**
  - Description: DataTable display of search results
  - File: `Modules/Sale/DataTables/GlobalMenuSearchDataTable.php`
  - Columns: Reference, Customer, Serial Numbers, Tenant, Seller, Status, Date, Actions
  - Features: sorting, filtering, pagination, inline serial details
  - Blade: `Modules/Sale/Resources/views/global-menu/results.blade.php`
  - Effort: 3 points

- [ ] **UI-4: Create Sales Order Detail View**
  - Description: Display complete order with serial numbers
  - File: `Modules/Sale/Resources/views/global-menu/detail.blade.php`
  - Includes:
    - Order header (reference, date, status, customer)
    - Tenant/seller information section
    - Serial numbers table with status
    - Dispatch history
    - Payment history
    - Return information (if applicable)
  - Effort: 2 points

- [ ] **UI-5: Create Global Menu Layout**
  - Description: Main page layout for search feature
  - File: `Modules/Sale/Resources/views/global-menu/index.blade.php`
  - Includes: search bar, filters, results area, sidebar
  - Responsive design (desktop/mobile)
  - Breadcrumb navigation
  - Effort: 2 points

### JavaScript/Alpine Tasks

- [ ] **JS-1: Create autocomplete functionality**
  - Description: Autocomplete suggestions for serial numbers
  - File: `Modules/Sale/Resources/js/global-menu-autocomplete.js`
  - Integration: Use existing framework (Alpine.js or vanilla JS)
  - Debouncing, caching suggestions
  - Effort: 2 points

- [ ] **JS-2: Create dynamic filter handling**
  - Description: Filter state management and updates
  - File: `Modules/Sale/Resources/js/global-menu-filters.js`
  - Features: URL state sync, filter presets, clear button
  - Effort: 1 point

**Subtotal: 16 points**

---

## Phase 5: Integration & Testing

### Integration Tasks

- [ ] **INT-1: Integrate with existing navigation menu**
  - Description: Add Global Menu link to main navigation
  - File: Update main layout template
  - Add: "Global Menu" or "Search Sales" link
  - Icon: magnifying glass or search icon
  - Keyboard shortcut: Ctrl+Shift+S (optional)
  - Effort: 1 point

- [ ] **INT-2: Integrate tenant context middleware**
  - Description: Ensure all queries respect tenant isolation
  - File: Update SerialNumberSearchService, Controllers
  - Validate: setting_id from session, enforce in all queries
  - Error handling: Clear error message if tenant not set
  - Effort: 1 point

- [ ] **INT-3: Hook into existing permission system**
  - Description: Define permissions and gates
  - File: Create permission migration or seeder
  - Permissions:
    - sales.search.global (basic search)
    - sales.search.export (export results)
    - sales.search.cross-tenant (admin feature)
  - File: `database/seeders/GlobalMenuPermissionsSeeder.php`
  - Effort: 1 point

- [ ] **INT-4: Add audit logging**
  - Description: Log searches to existing audits table or custom table
  - File: Update GlobalMenuController, SearchService
  - Log: user_id, setting_id, search_query, filters, result_count
  - Effort: 1 point

**Subtotal: 4 points**

---

## Phase 6: Testing

### Unit Test Tasks

- [ ] **TEST-1: Test SerialNumberSearchService**
  - Description: Unit tests for search logic
  - File: `Modules/Sale/Tests/Unit/SerialNumberSearchServiceTest.php`
  - Test cases:
    - Search by complete serial number (exact match)
    - Search by partial serial number (like match)
    - Search with tenant filter
    - Search returns empty results appropriately
    - Performance on large dataset (1000+ results)
  - Effort: 2 points

- [ ] **TEST-2: Test SalesOrderFormatter**
  - Description: Unit tests for formatting logic
  - File: `Modules/Sale/Tests/Unit/SalesOrderFormatterTest.php`
  - Test cases:
    - Format sale with all fields populated
    - Format sale with missing optional fields
    - Tenant info extraction
    - Seller info extraction
    - Serial numbers formatting
  - Effort: 1 point

- [ ] **TEST-3: Test API endpoints**
  - Description: Integration tests for API
  - File: `Modules/Sale/Tests/Feature/GlobalMenuApiTest.php`
  - Test cases:
    - Search endpoint with valid query
    - Search endpoint with invalid filters
    - Unauthorized access rejection
    - Cross-tenant data isolation
    - Response format validation
    - Pagination works correctly
  - Effort: 3 points

### Feature Test Tasks

- [ ] **TEST-4: Test search UI functionality**
  - Description: Livewire/feature tests for UI components
  - File: `Modules/Sale/Tests/Feature/GlobalMenuSearchTest.php`
  - Test cases:
    - Search input filters and displays results
    - Filters update results correctly
    - Pagination works
    - Results clickable to detail view
    - Mobile responsive behavior
  - Effort: 2 points

- [ ] **TEST-5: Test multi-tenant isolation**
  - Description: Security tests ensuring no data leakage
  - File: `Modules/Sale/Tests/Feature/MultiTenantSecurityTest.php`
  - Test cases:
    - User can only see their tenant's sales
    - Admin sees correct cross-tenant data (if allowed)
    - Session change updates results
    - Direct API access without valid tenant rejected
  - Effort: 2 points

- [ ] **TEST-6: Test permission gates**
  - Description: Authorization tests
  - File: `Modules/Sale/Tests/Feature/GlobalMenuAuthorizationTest.php`
  - Test cases:
    - User without permission cannot access
    - Export button hidden for unauthorized users
    - Cross-tenant search available to admins only
  - Effort: 1 point

**Subtotal: 11 points**

---

## Phase 7: Documentation

### Documentation Tasks

- [ ] **DOC-1: API documentation**
  - Description: OpenAPI/Swagger documentation for endpoints
  - File: `ai-docs/GLOBAL_MENU_API.md`
  - Content:
    - Endpoint list with examples
    - Request/response schemas
    - Error codes and messages
    - Authentication requirements
    - Rate limits (if applicable)
  - Effort: 2 points

- [ ] **DOC-2: User guide**
  - Description: End-user documentation
  - File: `ai-docs/GLOBAL_MENU_USER_GUIDE.md`
  - Content:
    - How to search for sales
    - Using filters
    - Exporting results
    - Keyboard shortcuts
    - Screenshots/GIFs
  - Effort: 1 point

- [ ] **DOC-3: Developer guide**
  - Description: Technical documentation for future maintenance
  - File: `ai-docs/GLOBAL_MENU_DEVELOPER_GUIDE.md`
  - Content:
    - Architecture overview
    - Database schema
    - API architecture
    - Key classes and methods
    - Extension points
    - Troubleshooting
  - Effort: 2 points

- [ ] **DOC-4: Update README**
  - Description: Update main README with feature overview
  - File: `README.md`
  - Add section: Global Menu feature description
  - Link to documentation files
  - Effort: 0.5 points

**Subtotal: 5.5 points**

---

## Phase 8: Performance & Optimization

### Performance Tasks

- [ ] **PERF-1: Add database query optimization**
  - Description: Eager load relationships, avoid N+1 queries
  - Files: Update controllers and services
  - Optimize:
    - Load customer with sale
    - Load serial numbers with eager loading
    - Load setting/location relationships
  - Effort: 2 points

- [ ] **PERF-2: Add query caching**
  - Description: Cache frequently accessed data
  - File: Update services
  - Cache:
    - Customer lookups (1 hour TTL)
    - Product information (2 hours TTL)
    - Tenant settings (session lifetime)
  - Effort: 1 point

- [ ] **PERF-3: Benchmark and load test**
  - Description: Performance testing with large datasets
  - File: Create load test script or use tools like Apache JMeter
  - Test scenarios:
    - 10,000 concurrent searches
    - 1M+ serial numbers in database
    - Response time measurements
    - Database connection pool sizing
  - Effort: 2 points

**Subtotal: 5 points**

---

## Phase 9: Deployment & QA

### QA Tasks

- [ ] **QA-1: Manual testing checklist**
  - Description: Manual QA of all features
  - Create: Testing checklist document
  - Test areas:
    - Search functionality (all types)
    - Filtering (all filter combinations)
    - Multi-tenant isolation
    - Permission enforcement
    - UI responsiveness
    - Error handling
    - Performance at scale
  - Effort: 2 points

- [ ] **QA-2: Browser compatibility testing**
  - Description: Test across browsers
  - Browsers: Chrome, Firefox, Safari, Edge (latest versions)
  - Test: Layout, functionality, performance
  - Effort: 1 point

### Deployment Tasks

- [ ] **DEPLOY-1: Create migration script**
  - Description: Script to handle production deployment
  - File: `database/scripts/deploy-global-menu.sh`
  - Steps:
    - Run migrations
    - Seed permissions
    - Clear caches
    - Index database tables
  - Effort: 1 point

- [ ] **DEPLOY-2: Create rollback procedure**
  - Description: Procedure to roll back feature if needed
  - File: Document rollback steps
  - Include: Migration rollback, permission cleanup
  - Effort: 1 point

**Subtotal: 5 points**

---

## Grand Summary

| Phase | Category | Points | Status |
|-------|----------|--------|--------|
| 1 | Database & Schema | 4 | ✅ **COMPLETED** |
| 2 | Backend - Models & Services | 10 | Not Started |
| 3 | Backend - API | 6 | Not Started |
| 4 | Frontend - UI | 16 | Not Started |
| 5 | Integration | 4 | Not Started |
| 6 | Testing | 11 | Not Started |
| 7 | Documentation | 5.5 | Not Started |
| 8 | Performance | 5 | Not Started |
| 9 | QA & Deployment | 5 | Not Started |
| | **TOTAL** | **66.5** | **4/66.5 (6%)** |

---

## Implementation Order (Recommended)

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 → Phase 6 → Phase 7 → Phase 8 → Phase 9
```

**Rationale:**
1. Build data layer first (DB, migrations)
2. Build business logic (services, models)
3. Build API layer (endpoints, requests)
4. Build UI layer (Livewire components)
5. Integrate with existing systems
6. Comprehensive testing
7. Document for maintenance
8. Optimize for production
9. Deploy with confidence

---

## Dependencies Between Tasks

- All tasks depend on Phase 1 (database setup)
- API tasks (Phase 3) depend on Phase 2 (services)
- UI tasks (Phase 4) depend on Phase 3 (API endpoints)
- Testing (Phase 6) depends on all implementation phases
- Documentation (Phase 7) should parallel implementation
- Performance optimization (Phase 8) after basic testing
- Deployment (Phase 9) requires completion of all phases

---

## Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| Performance with large datasets | Early load testing (PERF-3), proper indexing |
| Multi-tenant data leakage | Comprehensive security tests (TEST-5) |
| Breaking existing functionality | Thorough integration tests (TEST-3, TEST-4) |
| User adoption | Clear documentation (DOC-2), UI/UX testing |
| Production deployment issues | Staging environment testing, rollback procedure |

---

## Success Metrics

- Feature development completed within 66.5 story points
- 80%+ unit test coverage achieved
- Performance benchmarks met (< 2s for search)
- Zero security vulnerabilities in code review
- User satisfaction score > 4/5 stars
- No regressions in existing functionality

---

**Document Owner:** Development Lead  
**Last Updated:** November 8, 2025  
**Phase 1 Completed:** November 8, 2025 ✅  
**Next Review:** Upon Phase 2 initiation  
**Progress:** 4/66.5 story points completed (6%)
