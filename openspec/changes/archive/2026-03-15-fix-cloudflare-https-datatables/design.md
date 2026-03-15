## Context

The application is deployed in two modes:
1. **Local**: Runs on `http://localhost:8000` with `.env` configured to `APP_URL=http://localhost:8000`
2. **Remote via Cloudflare Tunnel**: HTTPS traffic from `https://app.tiga-saudara.my.id` is tunneled to local HTTP at `localhost:8000`

When accessed via Cloudflare tunnel, the request flow is:
- User browser makes HTTPS request to `https://app.tiga-saudara.my.id`
- Cloudflare tunnel encrypts and forwards as HTTP to `http://localhost:8000`
- Cloudflare adds headers: `X-Forwarded-Proto: https`, `X-Forwarded-Host: app.tiga-saudara.my.id`
- Laravel receives HTTP request but headers indicate original was HTTPS

Current problem:
- `TrustProxies.php` has `$proxies = null` (trusts no specific IPs)
- Even though headers are configured, without explicit proxy trust, Laravel ignores forwarded headers
- `url()` helper reads scheme from request (which is HTTP) instead of headers
- DataTables use `url()->full()` which returns `http://app.tiga-saudara.my.id`
- Browser blocks AJAX: HTTPS page cannot request HTTP resource

## Goals / Non-Goals

**Goals:**
- Make DataTables work over HTTPS when accessed via Cloudflare tunnel
- Maintain backward compatibility with local HTTP access
- Ensure URL generation respects Cloudflare's forwarded headers
- Apply the fix systematically so all DataTables benefit

**Non-Goals:**
- Change application logic or DataTable behavior
- Modify database or data models
- Implement dynamic APP_URL detection (use middleware configuration instead)
- Add new features to DataTables
- Performance optimization (this is a bug fix)

## Decisions

**Decision 1: Trust Cloudflare by configuring specific proxy IPs**

Rather than using environment-based detection or dynamic runtime logic, configure `TrustProxies` middleware to explicitly trust Cloudflare's IP range.

**Rationale:**
- Cloudflare publishes official IP ranges
- Laravel's TrustProxies is designed for this exact use case
- Configuration-based approach is secure (only trust specific IPs, not all proxies)
- No performance overhead, works at middleware level
- Already configured to read the right headers (`X-Forwarded-Proto`, etc.)

**Alternatives considered:**
- Dynamic detection based on request headers: Less secure, harder to maintain
- Create custom middleware: Reinvents what TrustProxies already does
- Modify APP_URL environment: Breaks local development
- Proxy-specific code in DataTable generation: Couples business logic to infrastructure

**Decision 2: Use Cloudflare IP range in TrustProxies**

Configure `$proxies` to match Cloudflare's current IP ranges (or use `*` if willing to trust all proxies in controlled environment).

**Rationale:**
- Cloudflare publishes IP ranges at `https://www.cloudflare.com/ips/`
- Most secure approach: only trust known Cloudflare IPs
- For small team in controlled environment (local + Cloudflare tunnel only), can use broader trust

**Alternatives considered:**
- Trust all proxies (`*`): Less secure, but simple for small team
- Hard-code specific IPs: Works but requires maintenance as Cloudflare updates IPs

**Decision 3: Implement once in TrustProxies, benefit all URLs**

Since the issue is at the middleware/URL-generation level, one fix in TrustProxies fixes:
- DataTable AJAX URLs
- All `route()` and `url()` helpers
- Any code relying on request scheme detection

## Risks / Trade-offs

**Risk 1: IP range maintenance** → Cloudflare occasionally updates IP ranges. Mitigation: Use broader trust (`*` if team is small), document that IPs may need updates, or use Cloudflare's official IP list dynamically.

**Risk 2: Trusting too broad a range** → If using `*` or `CLOUDFLARE_IPS_ALL`, any proxy could spoof headers. Mitigation: Ensure application is only accessible via Cloudflare tunnel in production, no direct internet access to app server.

**Risk 3: Backward compatibility with local dev** → Ensuring local `http://localhost` still works after changes. Mitigation: TrustProxies is designed to gracefully ignore headers when IP is not trusted, so local HTTP requests (not from proxy) continue to work normally.

## Migration Plan

**Deployment:**
1. Update `app/Http/Middleware/TrustProxies.php` to configure `$proxies`
2. No database migrations or data changes needed
3. No deployment coordination required
4. Can be deployed independently

**Rollback:**
- Revert TrustProxies.php to previous state
- No schema or data rollback needed
- Immediate effect on next request

**Testing:**
- Test local access via `http://localhost:8000` — should work unchanged
- Test Cloudflare access via `https://app.tiga-saudara.my.id` — DataTables should load data
- Verify browser console has no mixed-content errors

## Open Questions

1. **Should we use specific IP ranges or trust all proxies?**
   - If only Cloudflare tunnel is used: Can trust broader
   - If other proxies might be added: Need specific IPs
   - **Recommendation**: Start with Cloudflare-specific IPs; if complexity arises, document and adjust

2. **Should this be environment-specific (production only)?**
   - For simplicity: One configuration works for all environments
   - Cloudflare headers only present when accessed via tunnel, so configuration is safe in all envs
   - **Recommendation**: Same config in all environments
