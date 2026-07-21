## Context

A previous migration `2026_03_07_215500_add_position_to_setting_sale_locations.php` altered `setting_sale_locations.position` to be `NOT NULL`. However, when toggling location status via `SaleLocationConfigurationController@toggle`, an `updateOrCreate` is performed without providing a `position` parameter. This produces a SQL General Error (1364) since there's no default value. 

## Goals / Non-Goals

**Goals:**
- Provide a robust way to automatically generate a `position` when one is not supplied during the creation of a `SettingSaleLocation` record.
- Enable users to successfully toggle/enable locations again from the configuration page.

**Non-Goals:**
- Modifying the existing UI for reordering sale locations.
- Changing the `NOT NULL` constraint in the database.

## Decisions

**Hooking into the Eloquent `creating` Event**
Instead of adding default value calculation strictly within `SaleLocationConfigurationController@toggle` or any specific business logic service, we will hook into the `creating` event on the `SettingSaleLocation` model's `booted` method.

*Rationale:* 
This ensures that any future logic attempting to create a `SettingSaleLocation` using Eloquent (e.g., `updateOrCreate`, `create`, `save`) without supplying a position will automatically get a sequentially valid default position, avoiding SQL errors globally within the application.

*Implementation:*
```php
static::creating(function (SettingSaleLocation $assignment) {
    if (is_null($assignment->position)) {
        $maxPosition = static::where('setting_id', $assignment->setting_id)->max('position');
        $assignment->position = ($maxPosition ?: 0) + 1;
    }
});
```

## Risks / Trade-offs

- **Concurrency Risk** → If two users simultaneously enable locations for the same `setting_id`, a race condition could theoretically assign the same `position`. Mitigation: While theoretically possible, it's a very low probability edge-case on an administrative setting screen. If it occurs, users can easily drag-and-drop to reorder them via the UI which fixes the ordering.
