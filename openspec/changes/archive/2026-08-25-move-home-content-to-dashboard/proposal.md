## Why

The authenticated Home page currently serves as a reporting dashboard while the sidebar's Dashboard item is only a non-functional placeholder. Separating the operational landing experience from reporting content will give users a personalized, permission-aware starting point while preserving the existing dashboard information and behavior.

## What Changes

- Move all existing Home reporting cards and charts to a dedicated Dashboard page without changing their calculations or data endpoints.
- Make the sidebar Dashboard item navigate to the new Dashboard page and retain Home as the post-login landing page.
- Replace the moved Home content with a time-dependent Indonesian greeting addressed to the authenticated user's first name.
- Add a Quick Access card on Home for creating purchases, creating sales, opening a POS session, accessing global purchase payments, and accessing global sales payments.
- Hide each Quick Access action unless the authenticated user has every permission and feature/configuration prerequisite required to use that action.
- Add focused automated coverage for the moved content, greeting periods, navigation, and permission/configuration-based Quick Access visibility.

## Capabilities

### New Capabilities

- `authenticated-home-dashboard`: Defines the distinct Home and Dashboard experiences, personalized greeting, sidebar navigation, and permission-aware Quick Access actions.

### Modified Capabilities

None.

## Impact

- Affected areas include the authenticated web routes, `HomeController`, Home and Dashboard Blade views, sidebar menu, and focused feature/view tests.
- Existing chart endpoints, reporting queries, authorization rules, POS session behavior, purchase/sale creation behavior, and global payment workflows remain unchanged.
- No database migration, external API, or new package is required.
