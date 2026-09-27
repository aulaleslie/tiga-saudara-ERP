## Why

The current POS sell screen and its associated product search services load bundles globally, ignoring the `setting_id` scope. This causes the "bundles selection dialog" to display bundles that may not belong to the active POS setting, leading to configuration leakage and potential checkout errors.

## What Changes

- Scope the "bundles selection dialog" on the POS sell screen by the active `session('setting_id')`.
- Update `PosSellController@productBundles` to filter results by setting.
- Update `PosCartService@addLine` to enforce setting-scoped bundle validation.
- Update `PosScanResolverService` to correctly determine bundle status based on the setting-specific configuration.
- Update `PosProductSearchService` to flag bundle parents only if they have bundles in the current setting.

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `bundle-setting-scope`: Extend requirements to include POS sell screen interactions, cart mutations, and product search visibility rules.

## Impact

- `Modules/Pos/Http/Controllers/PosSellController.php`
- `Modules/Pos/Services/PosCartService.php`
- `Modules/Pos/Services/PosProductSearchService.php`
- `Modules/Pos/Services/PosScanResolverService.php`
- AJAX endpoint `/pos/sell/products/{product}/bundles`
