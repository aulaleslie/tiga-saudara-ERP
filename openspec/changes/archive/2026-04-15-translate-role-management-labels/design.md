## Context

The role management UI uses English labels in a few places: static labels in the view, array keys in the permissions configuration (which act as UI group headers), and POS bundle names/descriptions in the POS permission matrix support class. The application as a whole is presented in Bahasa Indonesia, creating an inconsistent user experience.

## Goals / Non-Goals

**Goals:**
- Translate the remaining English text string in the role management views (`create.blade.php`).
- Translate the permission group keys in `app/Config/Permissions.php` to Bahasa Indonesia.
- Translate the POS bundle labels and descriptions in `Modules/Pos/Support/PosPermissionMatrix.php`.

**Non-Goals:**
- Implementing a dynamic, multi-language localization system (e.g. `lang/id` or `__('string')`). The application relies on hardcoded Bahasa Indonesia for simplicity and consistency.
- Altering the actual permission strings (e.g. `roles.access`). Doing so would break the actual authorization gates and guards throughout the system.

## Decisions

**Direct String Replacement**
- We will manually update the group keys in `app/Config/Permissions.php`.
- The `PosPermissionMatrix.php` arrays will simply be translated. This is a low-risk approach as these labels are explicitly meant for presentation.

## Risks / Trade-offs

- **Risk:** Existing database seeders, views, or config parsers may rely on the exact English group names from `app/Config/Permissions.php`.
  **Mitigation:** The group name is only used for UI grouping. We will double-check for any magic string comparisons that might assume the group name is "POS" or "Products", but typically `config('permissions')` is only iterated over for form rendering.
