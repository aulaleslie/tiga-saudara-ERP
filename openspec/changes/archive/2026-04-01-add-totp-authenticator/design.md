## Context

The application is a multi-tenant ERP with a POS module. Users authenticate via Laravel session (web) and Sanctum tokens (API). The User module handles profile management and user CRUD. Currently, no two-factor authentication exists. Sensitive POS operations (cash pickup, session finalization) use supervisor email + password for authorization. The profile page has two independent forms (profile info + password change) using standard form submissions.

The User model uses Spatie Permission for roles, Spatie MediaLibrary for avatars, and a `user_setting` pivot for multi-tenant role assignment. The user edit page (admin) manages settings, roles, and account status.

## Goals / Non-Goals

**Goals:**
- Allow users to self-register a TOTP authenticator app from their profile
- Provide recovery codes as a backup mechanism
- Allow users to test their TOTP setup from the profile page
- Allow admins to reset a user's 2FA
- Expose a `TwoFactorService` that can be consumed by future features (POS cash pickup, session finalization)

**Non-Goals:**
- Enforcing TOTP for login (this is action-level OTP, not login 2FA)
- Wiring TOTP into POS cash pickup or finalization flows (Phase 2)
- SMS/email OTP alternatives (TOTP only)
- Per-setting or per-role 2FA enforcement policies
- Rate limiting on OTP verification (add later when wiring into POS)

## Decisions

### D1: Use `pragmarx/google2fa-laravel` + `bacon/bacon-qr-code`

**Choice**: Two dedicated packages for TOTP math and QR rendering.

**Alternatives considered**:
- `laravel/fortify`: Full auth scaffold — too heavy, we only need TOTP primitives
- Rolling own HMAC-SHA1 implementation: Unnecessary risk, RFC 6238 is well-specified and the package handles edge cases (clock drift, timing attacks)

**Rationale**: `google2fa` is the most widely-used PHP TOTP library. `bacon-qr-code` is its recommended QR renderer. Both are maintained and have minimal dependencies.

### D2: Store secret on `users` table (not a separate table)

**Choice**: Add `two_factor_secret`, `two_factor_confirmed_at`, and `two_factor_recovery_codes` columns directly to `users`.

**Alternatives considered**:
- Separate `two_factor_credentials` table: More normalized but adds a join for every verification. TOTP is 1:1 with user, no cardinality benefit.
- Polymorphic "credentials" table: Over-engineered for a single credential type.

**Rationale**: 1:1 relationship, always loaded with user. Encrypted casts handle security. Three nullable columns are simpler than a separate table with foreign key management.

### D3: Encrypt secrets at rest using Laravel's `encrypted` cast

**Choice**: Use `encrypted` cast on `two_factor_secret` and `two_factor_recovery_codes`.

**Rationale**: TOTP secrets are equivalent to passwords — if the database is compromised, an attacker with the secret can generate valid codes. Laravel's `encrypted` cast uses AES-256-CBC with the app key, providing encryption at rest with zero application code changes.

### D4: Recovery codes stored as encrypted JSON array of hashed values

**Choice**: Generate 8 recovery codes, hash each with `bcrypt`, store as encrypted JSON array. When used, verify with `Hash::check()` and remove from array.

**Alternatives considered**:
- Store plaintext (encrypted column only): If app key leaks, all recovery codes are exposed
- Store unhashed in encrypted column: Single layer of protection

**Rationale**: Defense in depth. Even if encryption is broken, hashed codes require brute force. Format: `XXXXX-XXXXX` (10 alphanumeric chars + separator) for readability.

### D5: AJAX-driven 2FA section on profile page

**Choice**: The 2FA card uses fetch/XHR for all interactions. No form submissions, no page reloads.

**Rationale**: Keeps 2FA completely isolated from existing profile and password forms. The QR code display, code confirmation, test verification, and disable flow all happen inline within the card. This matches the interactive nature of the feature (scan QR → immediately enter code → see result).

### D6: Two-step enable with session-stored temporary secret

**Choice**: When user clicks "Aktifkan", server generates secret and stores it in the HTTP session. Secret is only persisted to database after user successfully verifies a code from their authenticator app.

**Rationale**: Prevents storing unverified secrets. If user abandons setup, nothing is saved. The session-stored secret expires naturally with the session.

### D7: Service class in `app/Services/`

**Choice**: Place `TwoFactorService` in `app/Services/` (not in the User module).

**Rationale**: The service will be consumed by the POS module in Phase 2. Placing it in `app/Services/` keeps it module-agnostic, consistent with existing services like `IdempotencyService` and `ProductQuantityProjectionService`.

### D8: All 2FA routes return JSON responses

**Choice**: Controller endpoints return JSON (not views or redirects). The Blade partial handles rendering via JavaScript.

**Rationale**: Consistent with AJAX-driven approach (D5). The profile page loads the initial state via Blade, then all subsequent interactions are JSON API calls that update the DOM.

## Risks / Trade-offs

- **[Risk] User loses phone and recovery codes** → Admin reset via user edit page. Document recovery process for ops team.
- **[Risk] Clock drift between server and authenticator app** → google2fa supports configurable window. Default ±1 period (90 seconds total) handles typical drift.
- **[Risk] Session expiry during setup** → If session expires between QR display and code entry, user simply restarts setup. No orphaned data.
- **[Trade-off] No rate limiting on verification endpoint** → Acceptable for Phase 1 (profile testing). MUST add rate limiting in Phase 2 when TOTP gates financial operations.
- **[Trade-off] Recovery codes shown only once at setup** → User can view them again from profile (requires password confirmation). Balance between security and usability.
