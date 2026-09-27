# Tasks: refine-pos-return-draft-resolutions

## Fix: Component Availability Uses Full Setting Scope

- [x] In `PosReturnCreateForm::getComponentAvailability()`, replace single-location `ProductStock` query with `SalesLocationResolver::resolveLocationIds($settingId)` SUM across all allowed locations for the `source_setting_id`, matching POS stock lookup scope.
- [x] Apply the same fix to `PosReturnEditForm::getComponentAvailability()`.

