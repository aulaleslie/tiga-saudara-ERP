## 1. Secure active-business switching

- [x] 1.1 Update the active-business switch action to resolve and authorize the submitted business from authoritative data before changing session, cache, or role state.
- [x] 1.2 Preserve all-business access for Super Admin and enforce `user_setting` membership for every other user.
- [x] 1.3 Return a uniform authorization failure for unknown and inaccessible business IDs without mutating the existing active context.
- [x] 1.4 Redirect every successful active-business switch to the named Home route.

## 2. Regression coverage

- [x] 2.1 Add a focused feature test proving an assigned standard user switches context, refreshes the applicable role, and is redirected Home even with a scoped referrer.
- [x] 2.2 Add focused feature tests proving inaccessible and nonexistent submissions are denied and preserve the prior session context.
- [x] 2.3 Add a focused feature test proving an unassigned Super Admin can switch to an existing business and is redirected Home.
- [x] 2.4 Run the focused Setting-module test coverage and the relevant broader test command required by project conventions.
