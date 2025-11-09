# Architecture Diagram - Global Purchase and Sales Search

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            User Interface                                   │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │                    Livewire Component                                   │ │
│  │                GlobalPurchaseAndSalesSearch                             │ │
│  │                                                                         │ │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐     │ │
│  │  │  Search     │  │   Results   │  │ Pagination  │  │   Sorting   │     │ │
│  │  │   Input     │  │   Table     │  │  Controls  │  │  Controls   │     │ │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘     │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                   │
                                   │ HTTP Request
                                   ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            Controller Layer                                 │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │              GlobalPurchaseAndSalesSearchController                     │ │
│  │                                                                         │ │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐     │ │
│  │  │   index()   │  │  search()   │  │ suggest()   │  │ validation  │     │ │
│  │  │   method    │  │   method    │  │  method    │  │   & auth    │     │ │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘     │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                   │
                                   │ Service Call
                                   ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Service Layer                                     │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │              GlobalPurchaseAndSalesSearchService                        │ │
│  │                                                                         │ │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐     │ │
│  │  │ Serial #    │  │ References  │  │  Parties   │  │  Combined   │     │ │
│  │  │   Search    │  │   Search    │  │   Search    │  │   Search    │     │ │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘     │ │
│  │                                                                         │ │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐     │ │
│  │  │  Sorting    │  │ Pagination  │  │ Filtering  │  │  Auditing   │     │ │
│  │  │   Logic     │  │   Logic     │  │   Logic     │  │   Logic     │     │ │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘     │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                   │
                                   │ Database Queries
                                   ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Database Layer                                    │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │                          MySQL Database                                 │ │
│  │                                                                         │ │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐     │ │
│  │  │  purchases  │  │    sales    │  │dispatch_dtl│  │product_srls│     │ │
│  │  │    table    │  │    table    │  │   table    │  │   table    │     │ │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘     │ │
│  │                                                                         │ │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐                      │ │
│  │  │ suppliers   │  │ customers   │  │ audit_log  │                      │ │
│  │  │    table    │  │    table    │  │   table    │                      │ │
│  │  └─────────────┘  └─────────────┘  └─────────────┘                      │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Component Interactions

### 1. User → Livewire Component
- User types search query
- Livewire component debounces input (300ms)
- Triggers reactive search

### 2. Livewire → Controller
- Component calls controller methods
- Controller validates requests
- Controller applies authorization

### 3. Controller → Service
- Controller instantiates service
- Passes validated parameters
- Service executes business logic

### 4. Service → Database
- Service builds optimized queries
- Handles different data sources (purchases vs sales)
- Applies tenant filtering (optional for global search)

### 5. Database → Service
- Returns unified result set
- Service formats results with type indicators
- Applies sorting and pagination

### 6. Service → Controller → Livewire → User
- Results flow back through layers
- Livewire renders results table
- User sees clickable hyperlinks and formatted data

## Key Design Patterns

### Service Layer Pattern
- Business logic separated from controllers
- Reusable search methods
- Centralized query optimization

### Repository Pattern (Implicit)
- Entity relationships abstracted
- Query building encapsulated
- Data source agnostic interface

### Decorator Pattern (Results)
- Unified result format for purchases and sales
- Type indicators and normalized fields
- Clickable hyperlink generation

## Security Layers

### Authentication
- User must be logged in
- Permission: `globalPurchaseAndSalesSearch.access`

### Authorization
- Controller checks permissions
- Service validates data access
- Tenant isolation (optional)

### Input Validation
- Sanitize search queries
- Validate search types
- Prevent injection attacks

### Audit Logging
- All searches logged to database
- Track user, query, results, performance
- Compliance and monitoring</content>
<parameter name="filePath">/home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/ai-docs/global-purchase-and-sales-search/architecture-diagram.md