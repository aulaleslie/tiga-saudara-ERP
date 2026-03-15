## Why

DataTable AJAX requests fail with mixed content errors when accessed via Cloudflare tunnel over HTTPS. The page loads via HTTPS, but DataTables generate AJAX URLs using HTTP, which browsers block. This affects all pages with DataTables (Roles, Users, Products, etc.) when accessed remotely through Cloudflare while functioning correctly over local HTTP. The root cause is that the proxy trust configuration doesn't recognize Cloudflare's headers, preventing Laravel from detecting the HTTPS protocol on the incoming request.

## What Changes

- Configure `TrustProxies` middleware to explicitly trust Cloudflare's IP range
- Verify the middleware properly reads `X-Forwarded-Proto: https` headers from Cloudflare
- Ensure `url()` and route helpers generate HTTPS URLs when accessed via Cloudflare tunnel
- DataTable AJAX requests will automatically use the correct protocol (HTTPS) once URL generation is fixed

## Capabilities

### New Capabilities

- `cloudflare-proxy-trust`: Support for trusted proxy configuration to properly handle Cloudflare tunnel headers and HTTPS protocol detection

### Modified Capabilities

<!-- No existing specs are changing, only implementation of proxy trust -->

## Impact

- **Affected Code**: `app/Http/Middleware/TrustProxies.php` - configure proxy list
- **Affected Pages**: All pages using DataTables (Roles, Users, Products, Customers, Suppliers, Expenses, etc.)
- **APIs**: URL generation functions (`route()`, `url()`, etc.) will now return correct HTTPS URLs
- **Dependencies**: No new dependencies; using existing Laravel TrustProxies middleware
