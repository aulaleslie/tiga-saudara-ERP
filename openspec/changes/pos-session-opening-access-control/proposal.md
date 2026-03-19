## Why

Users without `pos.sell` permission should not be able to configure POS session opening parameters (terminal selection and opening float/saldo). Currently, these UI fields are visible and usable to users who have `pos.sessions.open` but lack `pos.sell`, creating a confusing and incorrect permission model. The fix ensures that session opening is restricted to users who can actually conduct sales, while allowing non-selling users to benefit from future "Simpan dan Buka Baru" (Save and Open New) features.

## What Changes

- **Terminal Selection & Total Saldo Awal fields**: Hidden from users without `pos.sell` permission
- **Backend validation**: Only allow form submission if user has `pos.sessions.open` permission (no change to auth gate, but validation logic refined)
- **Field requirements**: Terminal selection is optional for everyone; Total Saldo Awal is mandatory only if a terminal is selected
- **UI messaging**: Dynamic labels show which fields are required based on terminal selection
- **Non-breaking**: Users with both `pos.sessions.open` and `pos.sell` see no change; users without `pos.sell` have restricted fields hidden

## Capabilities

### New Capabilities
- `pos-session-opening-access-control`: Permission-based field visibility and requirement logic for POS session opening form

### Modified Capabilities
<!-- No existing capability requirement changes -->

## Impact

- **Code Changes**:
  - `Modules/Pos/Http/Requests/StorePosSessionOpenRequest.php` - validation rules
  - `Modules/Pos/Resources/views/session/open.blade.php` - conditional rendering and dynamic field requirements
- **Permissions**: Uses existing `pos.sell` permission for access control
- **User Experience**: Clearer form with hidden fields and dynamic required indicators
- **No API or database changes**
