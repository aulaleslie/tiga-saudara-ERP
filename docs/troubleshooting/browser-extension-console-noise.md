# Browser Extension Console Noise (Local Dev)

This runbook isolates browser-extension console warnings that look like app errors but are not emitted by this Laravel codebase.

## Scope

- Environment: local development.
- Browser focus: Chromium-based browsers (Chrome/Edge/Brave).
- Goal: separate extension-origin noise from first-party application issues.

## Fingerprint Patterns

If you see one or more of these patterns, treat them as extension-origin candidates first:

- `sw.js:13 Event handler of 'jamToggleDumpStore' event must be added on the initial evaluation of worker script.`
- `Unchecked runtime.lastError: The page keeping the extension port is moved into back/forward cache, so the message channel is closed.`
- `Error: [mobx-state-tree] You are trying to read or write to an object that is no longer part of a state tree.`
- `lockdown-install.js: SES Removing unpermitted intrinsics`

## Source Attribution Checklist

Run all checks before touching app code.

1. Confirm stack/source origin in DevTools.
   - Open console error.
   - Check source URL and call stack.
   - If source starts with `chrome-extension://` (or an extension service worker), classify as extension-origin.
2. Confirm no app service worker in browser storage.
   - Open DevTools `Application` tab.
   - Go to `Service Workers`.
   - Confirm no worker registered for this app origin.
3. Confirm repo has no service-worker registration.
   - Run:
     ```bash
     rg -n "navigator\\.serviceWorker|serviceWorker\\.register|register\\(\\s*['\\\"]/?sw\\.js" -S resources app Modules routes config public
     ```
   - Expected: no matches.
4. Confirm no project `sw.js` file.
   - Run:
     ```bash
     find . -maxdepth 4 -type f \( -name "sw.js" -o -name "*service*worker*.js" \)
     ```
   - Expected: no project service-worker script for app runtime.

## Local Fix Workflow

1. Reproduce once in normal browser profile and capture the warning text.
2. Open the same page in Incognito with extensions disabled, or use a clean browser profile.
3. Compare console output.
   - If warnings disappear, root cause is extension-side.
4. Re-enable extensions selectively to identify offender.
   - Typical classes: session recorder, wallet injector, productivity overlay, security script injector.
5. Disable the offending extension for localhost/dev host.
   - Use extension site access settings (`On specific sites` or disabled for local origin).
6. Hard reload and validate affected pages:
   - Purchase create/edit flow.
   - Sale create flow.

## App Health Validation

After isolation, verify app health did not regress.

1. Check Laravel log for same timestamps.
   - File: `storage/logs/laravel.log`
   - Expected: no correlated exception explaining those extension stack traces.
2. Check network requests on affected actions.
   - Expected: normal status codes, no failed app requests tied to the console spam.
3. Check behavior.
   - Expected: forms still submit/update normally, with no new first-party JS errors.

## Do / Don’t

Do:

- Treat extension-origin stack traces as environment noise first.
- Keep this runbook as the first triage path before code changes.

Don’t:

- Don’t patch app code to suppress extension console logs.
- Don’t add production hacks that hide console errors globally.
- Don’t create service-worker code just to “absorb” extension warnings.

## Test Scenarios

1. Baseline reproduction in normal profile.
   - Expected: warnings may appear.
2. Isolation test in clean/incognito profile.
   - Expected: warnings disappear.
3. Functional regression check in purchase/sale forms.
   - Expected: no behavioral changes.
4. Source confirmation check.
   - Expected: warning source is extension context, not app assets.
5. Log correlation check.
   - Expected: no backend failure tied to those warning signatures.

## Exit Criteria

- Extension source is confirmed by DevTools.
- Clean profile does not reproduce warnings.
- App functional flows still pass.
- No application code change is required for this issue.
