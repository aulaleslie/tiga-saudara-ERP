## 1. Database Migrations

- [x] 1.1 Generate a new migration to replace the active session unique constraint (up: drop `pos_sessions_user_active_unique`, add `pos_sessions_global_active_user_unique` on `cashier_user_id, active_marker`; down: reverse).
- [x] 1.2 Run database migrations locally.

## 2. Core Service Implementation

- [x] 2.1 Update `PosSessionLifecycleService::openSession()` to globally query for `activeSessionForUser` by removing the `setting_id` clause.
- [x] 2.2 Update `PosSessionLifecycleService::openSession()` to check if the retrieved global session's `setting_id` matches the current `setting_id`. If not, load the `setting` relationship and throw a DomainException indicating an active session exists in another setting.

## 3. UI and Controller Interception

- [x] 3.1 Update `PosSessionController@create()` to check if the user has an active session in a setting other than the current `setting_id`.
- [x] 3.2 If an active session outside the current setting is found in `create()`, fetch its `setting` name and pass an `activeSessionInOtherSetting` variable payload to the view.
- [x] 3.3 Update `Modules/Pos/Resources/views/session/open.blade.php` to conditionally display a block warning (e.g. "You already have an active session in Cabang Pusat...") and hide/disable the form fields and submit button if `activeSessionInOtherSetting` is present.
