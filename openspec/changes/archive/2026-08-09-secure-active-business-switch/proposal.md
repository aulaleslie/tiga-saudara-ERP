## Why

Changing the active business currently returns the user to the page they were viewing under the previous business. That page can be invalid in the new context, producing confusing 404 or access failures. The switching endpoint also accepts a submitted business identifier without first proving that a non-Super-Admin user is assigned to it, allowing a crafted request to alter the active-business session context.

## What Changes

- Redirect users to the Home page after a successful active-business switch instead of returning them to the referring page.
- Require server-side authorization of the requested business before changing session state, caches, or dynamically assigned roles.
- Allow Super Admin users to switch to any existing business, consistent with existing login and selector behavior.
- Reject nonexistent or inaccessible business identifiers without exposing business membership and without partially changing the current context.
- Add regression coverage for valid, unauthorized, and Super Admin switching paths.

## Capabilities

### New Capabilities

- `active-business-switch-security`: Securely select an authorized active business and start the new context from Home.

### Modified Capabilities

- None.

## Impact

- `Modules/Setting/Http/Controllers/BusinessController.php` active-business switch action.
- Header business-selector form behavior through its existing route.
- Session `setting_id`, per-business settings cache, and dynamic role synchronization.
- New focused feature tests in the Setting module or existing test suite.
