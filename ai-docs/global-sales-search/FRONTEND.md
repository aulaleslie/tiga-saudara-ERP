# Global Sales Search Frontend Implementation

## Overview

The Global Sales Search frontend is built using Laravel Livewire for reactive components and vanilla JavaScript for enhanced interactivity. The interface provides real-time search capabilities with a simplified, streamlined design focused on core search functionality.

## Component Architecture

### Livewire Component: `GlobalSalesSearch`
**File:** `Modules/Sale/Http/Livewire/GlobalSalesSearch.php`
**View:** `Modules/Sale/Resources/views/livewire/global-sales-search.blade.php`

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

## UI Components

### Search Interface
**Layout:** Bootstrap 4/5 grid system
**Responsive:** Mobile-friendly design

**Elements:**
- Main search input with icon and clear button
- Search type selector (All/Serial/Reference/Customer)
- Loading indicators during search
- Results table with pagination
- Export button (placeholder for future implementation)

### Results Table
**Columns:**
- Reference (clickable link)
- Customer Name
- Serial Numbers Count (with badge)
- Tenant Name
- Seller Name
- Status (with color-coded badges)
- Date (sortable)

**Features:**
- Sortable columns (Reference, Status, Date)
- Pagination with customizable page size
- Responsive design for mobile devices

## Event System

### Livewire Events
- `viewSale` - Triggers sale detail view
- `exportResults` - Placeholder for export functionality

### JavaScript Events
- `livewire:loaded` - Initializes search input focus
- `keydown` - Global keyboard shortcuts

## Keyboard Shortcuts

### Global Shortcuts
- `Ctrl+Shift+S` - Focus search input

### Search Input
- `Enter` - Execute search (handled by Livewire)
- `Escape` - Clear search (handled by clear button)

## Performance Optimizations

### Frontend
- Debounced search input (300ms)
- Pagination for large result sets
- Minimal DOM updates with Livewire

### Search Logic
- Real-time search with immediate feedback
- Efficient query building based on search type
- Global search without tenant restrictions

## Accessibility

### ARIA Labels
- Form inputs have proper labels
- Buttons have descriptive text
- Tables have proper headers

### Keyboard Navigation
- Tab order follows logical flow
- Enter activates search
- Keyboard shortcuts for power users

### Screen Reader Support
- Semantic HTML structure
- Status announcements for actions

## Browser Compatibility

### Supported Browsers
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

### Requirements
- JavaScript enabled
- Modern browser features (ES6+)

## Mobile Responsiveness

### Breakpoints
- Desktop: > 768px
- Tablet: 576px - 768px
- Mobile: < 576px

### Responsive Features
- Stacked form elements on mobile
- Horizontal scroll for results table
- Touch-friendly buttons
- Optimized spacing for small screens

## Error Handling

### User Feedback
- Loading spinners during searches
- Empty state messages
- Error alerts for failed requests
- Validation error display

### JavaScript Error Handling
- Try-catch blocks around event handlers
- Graceful degradation
- Console logging for debugging

## Simplified Design Decisions

### Removed Features
- Advanced filtering panel
- Filter toggle functionality
- Complex dropdown selections
- Date range pickers
- Multi-select options

### Benefits
- Faster initial load
- Reduced cognitive load
- Cleaner user interface
- Easier maintenance
- Better performance

## Testing

### Manual Testing Checklist
- [ ] Search functionality across all types
- [ ] Sorting and pagination
- [ ] Keyboard navigation
- [ ] Mobile responsiveness
- [ ] Error scenarios
- [ ] Loading states

### Automated Testing
- Livewire component testing
- Feature tests for search functionality
- Integration tests for complete workflows</content>
<parameter name="filePath">/home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/ai-docs/global-menu/FRONTEND.md