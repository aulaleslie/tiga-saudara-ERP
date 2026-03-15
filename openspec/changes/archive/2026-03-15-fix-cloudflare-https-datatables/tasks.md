## 1. Configure TrustProxies Middleware

- [x] 1.1 Update `app/Http/Middleware/TrustProxies.php` to define `$proxies` property with Cloudflare IP ranges
- [x] 1.2 Verify headers configuration includes `X_FORWARDED_PROTO` and `X_FORWARDED_HOST`

## 2. Test Local Access (HTTP)

- [x] 2.1 Access application at `http://localhost:8000` and verify page loads normally
- [x] 2.2 Check that regular page navigation works (click links, navigate to different pages)
- [x] 2.3 Verify URL generation returns HTTP URLs in local environment

## 3. Test Cloudflare Tunnel Access (HTTPS)

- [x] 3.1 Access application via Cloudflare tunnel at `https://app.tiga-saudara.my.id`
- [x] 3.2 Verify page loads over HTTPS
- [x] 3.3 Navigate to Roles page and verify no mixed-content errors in browser console

## 4. Test DataTable AJAX Requests

- [x] 4.1 Access Roles page via Cloudflare tunnel
- [x] 4.2 Verify DataTable loads data successfully (table shows roles)
- [x] 4.3 Check browser DevTools Network tab — AJAX request URL should use HTTPS
- [x] 4.4 Verify no "Mixed Content" errors in browser console
- [x] 4.5 Test other DataTable pages (Users, Products, etc.) to ensure fix applies across all tables

## 5. Verify Backward Compatibility

- [x] 5.1 Confirm that API endpoints function normally
- [x] 5.2 Test file uploads/downloads if applicable
- [x] 5.3 Verify session management and authentication still work
- [x] 5.4 Check that non-DataTable pages are unaffected

## 6. Documentation & Cleanup

- [x] 6.1 Add comments to TrustProxies.php explaining Cloudflare configuration
- [x] 6.2 Document which Cloudflare IP ranges are being trusted (or note if using broad trust)
