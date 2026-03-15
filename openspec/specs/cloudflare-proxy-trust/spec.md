# cloudflare-proxy-trust Specification

## Purpose
TBD - created by archiving change fix-cloudflare-https-datatables. Update Purpose after archive.
## Requirements
### Requirement: Trust Cloudflare proxy headers

The application SHALL trust `X-Forwarded-*` headers from Cloudflare's proxy in order to correctly detect the original request's protocol and host. This ensures that when users access the application via Cloudflare tunnel over HTTPS, Laravel's URL generation functions return HTTPS URLs.

#### Scenario: Local HTTP access continues to work

- **WHEN** user accesses application at `http://localhost:8000` directly
- **THEN** the request is not treated as proxied and URL helpers return HTTP URLs

#### Scenario: Cloudflare tunnel HTTPS access

- **WHEN** user accesses application via Cloudflare tunnel at `https://app.tiga-saudara.my.id`
- **THEN** the TrustProxies middleware detects Cloudflare's headers and URL helpers return HTTPS URLs

#### Scenario: DataTable AJAX requests use correct protocol

- **WHEN** a DataTable on a page accessed via Cloudflare tunnel makes an AJAX request
- **THEN** the AJAX URL uses HTTPS protocol (e.g., `https://app.tiga-saudara.my.id/roles?...`)
- **AND** the browser accepts the request (no mixed-content error)

### Requirement: Configuration does not hardcode specific IPs

The proxy trust configuration SHALL use a method that does not require constant maintenance as Cloudflare updates their IP ranges. The configuration SHOULD be flexible enough to adapt to infrastructure changes without code modifications.

#### Scenario: Configuration remains stable across Cloudflare IP updates

- **WHEN** Cloudflare's IP ranges are updated
- **THEN** the application continues to trust Cloudflare's headers without requiring code changes
- **AND** no deployment is necessary if using environment-based or broad trust approach

#### Scenario: Configuration works in all environments

- **WHEN** application runs in development, staging, or production
- **THEN** the proxy trust configuration works consistently across all environments
- **AND** local development at `http://localhost:8000` continues to function normally

### Requirement: No breaking changes to existing functionality

The proxy trust configuration SHALL not affect any existing application behavior, routes, or APIs. All existing pages and endpoints MUST continue to function as before.

#### Scenario: Regular page navigation unaffected

- **WHEN** user navigates to any application page (e.g., `/roles`, `/users`, `/products`)
- **THEN** page loads and renders correctly
- **AND** no changes to page layout, functionality, or navigation

#### Scenario: Non-DataTable routes unaffected

- **WHEN** user accesses routes that do not use DataTables
- **THEN** the routes function identically to before the change
- **AND** URL generation in templates uses correct protocol based on request

#### Scenario: API endpoints unaffected

- **WHEN** any API endpoint is called
- **THEN** the endpoint returns the same response
- **AND** the configuration does not affect API behavior or output

