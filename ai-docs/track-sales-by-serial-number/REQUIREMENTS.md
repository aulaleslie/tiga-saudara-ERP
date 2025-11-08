# Global Menu Feature - Requirements Document

**Version:** 1.0  
**Status:** In Development  
**Date:** November 8, 2025  
**Module:** Sale / Multi-Tenant Integration

---

## 1. Executive Summary

The Global Menu feature provides a centralized access point for searching and managing sales orders by serial number across the multi-tenant ERP system. This feature enables quick identification of which tenant/seller originated each sale and provides filtering capabilities within the existing authentication and multi-tenant architecture.

### Key Objectives
- Enable users to quickly locate sales orders using serial numbers
- Identify the originating tenant and seller for each transaction
- Provide intuitive search and filtering capabilities
- Maintain audit trail for compliance
- Ensure secure, role-based access control

---

## 2. Functional Requirements

### 2.1 Sales Order Tracking by Serial Number

**FR-1: Serial Number Search**
- Allow users to search for sales orders using product serial numbers
- Support partial serial number matches with autocomplete suggestions
- Return all matching sales orders with serial numbers across the system
- Display search results with relevant order details (order ID, date, customer, amount, status)

**FR-2: Serial Number Tracking**
- Track which serial numbers are associated with each sales order
- Support multiple serial numbers per sale detail line item
- Store serial number metadata (assignment date, location, tax classification)
- Maintain serial number history and audit trail

**FR-3: Batch Serial Number Import/Display**
- Support display of serialized products in sales orders
- Show serial numbers in both list and detail views
- Enable filtering by serial number status (allocated, dispatched, returned)

---

### 2.2 Multi-Tenant Identification

**FR-4: Tenant/Seller Identification**
- Clearly identify the originating tenant (Setting/Business entity) for each sale
- Display tenant information: name, location, ID
- Link sales orders to the responsible seller/user
- Show seller details: name, email, role, location assigned

**FR-5: Tenant-Scoped Search Results**
- Filter search results by tenant (business/setting)
- Display tenant context in search results
- Support "show all tenants" for authorized administrators
- Respect user's assigned settings (tenants) via session

**FR-6: Cross-Tenant Access Control**
- Restrict users to view only sales from assigned tenants
- Allow administrators to view cross-tenant sales reports
- Maintain tenant isolation through session-based `setting_id`

---

### 2.3 Search and Filtering Capabilities

**FR-7: Advanced Filtering**
- Filter by serial number (exact/partial match)
- Filter by sales order reference
- Filter by customer name/ID
- Filter by date range (creation, dispatch, payment)
- Filter by sales order status (DRAFTED, APPROVED, DISPATCHED, RETURNED, etc.)
- Filter by tenant/seller
- Filter by product/product category

**FR-8: Search Interface**
- Global search bar accessible from main menu
- Quick search with keystroke shortcuts (Ctrl+Shift+S or similar)
- Advanced search form with multiple criteria
- Saved search filters (optional enhancement)
- Search history with recent queries (optional enhancement)

**FR-9: Result Display and Export**
- Display results in paginated DataTable format (using existing yajra/datatables)
- Support column sorting and reordering
- Export results to CSV/Excel format
- Print-friendly view for selected records
- Show expanded serial number details on demand

---

### 2.4 Integration with Existing Systems

**FR-10: Authentication Integration**
- Authenticate using existing Laravel Sanctum system
- Support both web and API authentication
- Validate user session and `setting_id` middleware
- Enforce role-based access control (RBAC) using Spatie Permission

**FR-11: Multi-Tenant Architecture Integration**
- Use existing Setting (tenant) model as business unit
- Respect Location assignments per Setting
- Follow established pattern: User → Setting (via user_setting pivot)
- Use session `setting_id` for tenant context
- Support Location-based sales tracking

**FR-12: Data Relationships**
- Establish relationship chain: Sale → SaleDetails → ProductSerialNumber
- Link sales to Customer and Seller (User)
- Track dispatch information via Dispatch and DispatchDetail entities
- Maintain audit trail via existing audits table
- Support returned items tracking via SalesReturn module

**FR-13: API Integration**
- Provide RESTful API endpoints for search and filter operations
- Support JSON request/response format
- Enable third-party integration possibilities
- Return paginated results with metadata
- Support filtering via query parameters

---

### 2.5 Additional Features

**FR-14: Sales Order Details View**
- Display complete sale information from serial number lookup
- Show all serial numbers associated with the order
- Show dispatch history and status
- Show payment history
- Show return information (if applicable)

**FR-15: Analytics and Reporting**
- Generate reports of serial numbers by tenant
- Track dispatch rates and return rates by serial number
- Identify trends in serial number usage
- Support custom report generation

**FR-16: Notifications and Alerts**
- Notify users of status changes in tracked serial numbers
- Alert on suspicious patterns (duplicate serials, missing serials)
- Email notifications for high-value orders (optional)

---

## 3. Non-Functional Requirements

### 3.1 Performance

**NFR-1: Response Time**
- Serial number search should return results within 2 seconds
- DataTable pagination and sorting within 1 second
- API endpoints should respond within 500ms for 10,000 record dataset

**NFR-2: Scalability**
- Support 100,000+ serial numbers in the database
- Handle concurrent searches from multiple users
- Optimize database queries with appropriate indexing
- Support lazy loading and pagination for large datasets

**NFR-3: Database Optimization**
- Index serial_number column in product_serial_numbers table
- Index sale_id foreign keys in sale_details
- Index reference field in sales table
- Create composite indexes for multi-field searches

---

### 3.2 Security

**NFR-4: Data Protection**
- Implement row-level security (RLS) for multi-tenant data isolation
- Encrypt serial numbers in transit and at rest (if required by policy)
- Prevent SQL injection through parameterized queries (ORM usage)
- Sanitize all user input before processing

**NFR-5: Access Control**
- Enforce authentication on all endpoints
- Validate `setting_id` against user's assigned settings
- Log all serial number searches for audit purposes
- Restrict export functionality to authorized users only

**NFR-6: Audit and Compliance**
- Log all search queries and filters applied
- Track who accessed which serial numbers and when
- Maintain audit trail using existing audits table
- Support compliance reports (SOX, GDPR, etc.)

---

### 3.3 Usability

**NFR-7: User Interface**
- Responsive design supporting desktop and tablet views
- Keyboard shortcuts for power users
- Accessibility compliance (WCAG 2.1 Level AA)
- Clear error messages and validation feedback
- Breadcrumb navigation for context

**NFR-8: Mobile Support**
- Mobile-responsive search interface
- Touch-friendly controls
- Simplified result view for mobile devices

---

### 3.4 Maintainability

**NFR-9: Code Quality**
- Follow Laravel best practices and conventions
- Use existing architectural patterns (BaseModel, repositories, services)
- Unit test coverage minimum 80%
- Comprehensive API documentation

**NFR-10: Documentation**
- API endpoint documentation with examples
- Database schema documentation
- User guide for end-users
- Developer guide for maintenance

---

## 4. Current System Context

### 4.1 Technology Stack
- **Backend:** PHP 8.1+, Laravel 10.0, nwidart/laravel-modules
- **Frontend:** JavaScript, Livewire 3.0, Tailwind CSS, CoreUI
- **Database:** MySQL 9.2+
- **Authentication:** Laravel Sanctum
- **Authorization:** Spatie Permission
- **DataTables:** yajra/laravel-datatables v10

### 4.2 Existing Architecture
- **Multi-Tenant Model:** Setting (business unit) as tenant, accessed via session `setting_id`
- **User-Tenant Relationship:** User → Setting (via pivot table user_setting with role)
- **Module Structure:** Feature modules under `Modules/` directory with nwidart pattern
- **Location System:** Locations tied to Settings; sales orders associated with locations
- **Serial Numbers:** ProductSerialNumber model tracks serialized inventory items

### 4.3 Existing Data Structures

**Sale Entity**
- `reference`: Generated order number (e.g., "2025-11-SL-00001")
- `customer_id`: Links to Customer
- `setting_id` (implied through Location): Tenant identifier
- `status`: Order status (DRAFTED, APPROVED, DISPATCHED, etc.)
- `created_at`, `updated_at`: Timestamps

**SaleDetails Entity**
- `sale_id`: Foreign key to Sale
- `product_id`: Links to Product
- `quantity`: Order quantity
- `serial_number_ids`: JSON array of serial number IDs (if tracked)
- Supports bundle items via SaleBundleItem

**ProductSerialNumber Model**
- `product_id`: Links to Product
- `serial_number`: The actual serial number string
- `location_id`: Links to Location (and indirectly to Setting)
- `tax_id`: Tax classification

**DispatchDetail Entity**
- Links Sale to Dispatch
- Tracks which serial numbers were dispatched

---

## 5. Success Criteria

- ✓ Users can search for sales orders by serial number in <2 seconds
- ✓ Search results clearly identify the originating tenant and seller
- ✓ Users can apply multiple filters simultaneously
- ✓ Multi-tenant data isolation is maintained (no data leakage)
- ✓ Role-based access control enforced correctly
- ✓ All searches are audited and logged
- ✓ Feature works seamlessly across desktop and mobile
- ✓ API endpoints available for programmatic access
- ✓ 80%+ unit test coverage achieved
- ✓ Documentation complete and accurate

---

## 6. Out of Scope

- Real-time notification systems (Phase 2)
- Mobile app version (Phase 2)
- Advanced ML-based recommendations
- Integration with external warehouse systems
- Custom report builder UI (basic reporting only)

---

## 7. Dependencies

- Existing Sale, SaleDetails, ProductSerialNumber entities
- Existing Spatie Permission system for RBAC
- Existing yajra/laravel-datatables for listing
- Session middleware for tenant context
- Existing authentication system

---

## Appendix A: Related Modules

- **Sale Module:** Core sales order functionality
- **Product Module:** Product and serial number management
- **SalesReturn Module:** Returns tracking and serial number management
- **Setting Module:** Tenant/business configuration and locations
- **People Module:** Customer and supplier management

---

**Document Owner:** Development Team  
**Last Updated:** November 8, 2025
