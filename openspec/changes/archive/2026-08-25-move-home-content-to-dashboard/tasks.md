## 1. Dashboard Content Move

- [x] 1.1 Add the authenticated, `role.setting`-protected named Dashboard route and a controller action that supplies the same report values previously supplied to Home.
- [x] 1.2 Create the Dashboard Blade view by moving all existing Home report cards, chart markup, authorization checks, Chart.js asset section, and chart configuration script push without altering their behavior.
- [x] 1.3 Reduce the Home controller/view path to the new landing-page presentation and confirm the existing chart data routes and calculation methods remain unchanged.

## 2. Personalized Home Experience

- [x] 2.1 Implement application-local greeting selection for the specified morning, midday, afternoon, and night hour boundaries and derive the first name from the authenticated user's normalized `name`.
- [x] 2.2 Build the Home greeting and Quick Access card using the project's existing Bootstrap/CoreUI Blade patterns, including an empty state when no action is available.
- [x] 2.3 Add links for purchase creation and sale creation, each guarded by its corresponding create permission.
- [x] 2.4 Add the POS session-opening link guarded by current-setting POS enablement plus `pos.access` and `pos.sessions.open`.
- [x] 2.5 Add the global purchase and sales payment workspace links, each guarded by both its global-access and create-payment permissions.

## 3. Sidebar Navigation

- [x] 3.1 Replace the placeholder Dashboard sidebar item with the named Dashboard route and add route-aware active styling while retaining the existing Home link and active styling.

## 4. Focused Verification

- [x] 4.1 Add focused feature/view tests proving Home remains the authenticated landing experience, Dashboard contains the moved reporting presentation, report permission visibility is preserved, and sidebar links point to the correct routes.
- [x] 4.2 Add focused greeting tests with frozen application-local times covering the four greeting periods and their boundary transitions, including first-name extraction.
- [x] 4.3 Add focused Quick Access tests covering each required permission combination, missing-permission hiding, POS enabled/disabled behavior, correct named-route destinations, and the no-actions empty state.
- [x] 4.4 Run only the new Home/Dashboard tests and any directly affected existing focused tests; fix regressions within the touched scope without requiring the full application test suite.
