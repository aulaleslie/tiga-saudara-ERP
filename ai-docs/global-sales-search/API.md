# Global Sales Search API Documentation

## Base URL
`http://localhost:8000/api/global-sales-search`

## Authentication
All endpoints require authentication via Laravel Sanctum.

## Endpoints

### 1. Get Autocomplete Suggestions
**GET** `/suggest`

Get autocomplete suggestions for search queries.

**Query Parameters:**
- `q` (string) - Search query (minimum 2 characters)
- `type` (string) - Suggestion type: `serial`, `reference`, `customer`, or `all`

**Response:**
```json
{
  "success": true,
  "suggestions": [
    {
      "label": "SN001234",
      "type": "serial"
    },
    {
      "label": "SO-2025-001",
      "type": "reference"
    },
    {
      "label": "John Doe",
      "type": "customer"
    }
  ]
}
```

**Error Response:**
```json
{
  "success": false,
  "message": "Query parameter is required"
}
```

## Search Implementation

The Global Sales Search uses Livewire for real-time search functionality. The main search logic is handled client-side with server-side processing for performance.

### Search Types
1. **all** - Search across serial numbers, sale references, and customer names
2. **serial** - Search only serial numbers
3. **reference** - Search only sale references
4. **customer** - Search only customer names

### Search Logic
- Uses JSON_SEARCH for efficient serial number matching in `dispatch_details.serial_numbers`
- OR logic for multiple search criteria within selected type
- Global search - no tenant filtering applied
- Results sorted by creation date (descending) by default
- Pagination with configurable page size (default: 20)

## Error Responses

### Validation Error
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "q": ["The q field is required."],
    "type": ["The selected type is invalid."]
  }
}
```

### Permission Denied
```json
{
  "message": "This action is unauthorized.",
  "error": "Access denied"
}
```

### Server Error
```json
{
  "success": false,
  "message": "Search failed. Please try again.",
  "error": "Database connection timeout"
}
```

## Rate Limiting

- Applied to all endpoints
- Standard Laravel rate limiting configuration
- Separate limits for authenticated users

## Data Formats

### Suggestion Format
Autocomplete suggestions return objects with:
- `label` (string) - The display text
- `type` (string) - The suggestion type (`serial`, `reference`, `customer`)

## Implementation Notes

### Client-side Search
- Search is performed via Livewire component updates
- Debounced input (300ms) prevents excessive API calls
- Real-time results with loading indicators

### Performance Optimizations
- Database indexes on frequently searched columns
- JSON_SEARCH for efficient serial number lookups
- Pagination limits result set size
- Response time monitoring and logging

### Security Features
- Input validation and sanitization
- CSRF protection on all forms
- Audit logging of all search activities
- Permission-based access control</content>
<parameter name="filePath">/home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/ai-docs/global-menu/API.md