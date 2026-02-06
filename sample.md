# Cycle 6 Tickets: Membership + PT Sessions + Scheduling + Private Group Sessions + Attendance

## Theme
- Gym membership lifecycle, PT session packages, scheduling calendar, private group sessions (group PT), and attendance tracking.

## Alignment with existing system (required)
- **Audit columns:** all new entities extend `BaseAuditEntity` so audit logs capture create/update/delete (Cycle 1/2 pattern).
- **Guards:** Auth -> ActiveTenant -> TenantMembership -> Permission (Cycle 1 guard order).
- **Error codes:** use centralized error codes from `@gym-monorepo/shared` for new exceptions.
- **i18n:** new UI strings in `apps/web/src/locales/en.json` and `apps/web/src/locales/id.json`.
- **API client:** frontend uses axios `api`/`apiRaw` (no `fetch`).
- **Permissions:** add new Membership/Scheduling permission constants and seed them.
- **Sales integration:** memberships/PT packages created synchronously when invoice is POSTED (Cycle 5 hook).
- **BullMQ:** reuse existing worker infrastructure for scheduled jobs (Cycle ALTER).
- **Catalog:** membership and PT items use `service_kind` MEMBERSHIP/PT_SESSION from Cycle 4.

## Dependencies
- Cycle 3: People module (CUSTOMER type for members, STAFF type for trainers)
- Cycle 4: Catalog items with `service_kind` MEMBERSHIP/PT_SESSION
- Cycle 5: Sales invoices with posting handlers
- Cycle ALTER: BullMQ worker, outbox patterns

## Prerequisites (Cycle 4 Update Required)
- Add `max_participants` column to `items` table (int, default 1)
  - `max_participants = 1` → regular 1:1 PT session
  - `max_participants > 1` → private group session (group PT)

## Standard DoD Requirements (applies to ALL tickets)

Every ticket in this cycle must meet the following criteria before being marked complete:

1. **Linting:** Run `pnpm --filter api lint` and `pnpm --filter web lint` — **zero warnings and zero errors**.
2. **Unit Tests:** Add unit tests for new code (services, utilities, components, hooks).
3. **Existing Tests:** Run `pnpm --filter api test` and `pnpm --filter web test` — **all existing tests must pass**.
4. **Shared Package:** If shared types/constants are modified, run `pnpm --filter @gym-monorepo/shared build` before testing.

> **Note:** Individual ticket DoD sections list feature-specific acceptance criteria. The standard requirements above are implicit for every ticket.

---

## EPIC C6-0: Members Core

Goal: Create member records linked to People with profile tracking and status management.

### C6A-BE-01 - Members entity + migration

Scope:
- Create `members` table with FK to `people`:
  - `id` uuid PK
  - `tenant_id` uuid FK -> tenants
  - `person_id` uuid FK -> people (unique per tenant)
  - `member_code` string auto-generated per tenant
  - `status` enum: `NEW | ACTIVE | EXPIRED | INACTIVE` (default `NEW`)
  - `member_since` timestamptz nullable (set on first ACTIVE)
  - `current_expiry_date` timestamptz nullable (computed from active memberships)
  - `profile_completion_percent` int default 0 (0-100)
  - `agrees_to_terms` boolean default false
  - `terms_agreed_at` timestamptz nullable
  - `notes` text nullable
  - audit columns + timestamps
- Constraints:
  - unique (`tenant_id`, `person_id`)
  - unique (`tenant_id`, `member_code`)
  - `person_id` must reference People with `type = CUSTOMER`
- Code generation: tenant counter key `members` with prefix `MBR-`.

DoD:
- Migration runs successfully.
- Entity uses BaseAuditEntity and barrel imports.
- Member code auto-generated on create.

### C6A-BE-02 - Members CRUD API

Scope:
- Module path: `apps/api/src/modules/members`.
- Guards: AuthGuard -> ActiveTenantGuard -> TenantMembershipGuard -> PermissionGuard.
- Endpoints:
  - `GET /members?status=&search=&page=&limit=`
  - `GET /members/:id`
  - `POST /members` (create from existing Person or create Person + Member)
  - `PUT /members/:id` (update profile fields, status)
  - `DELETE /members/:id` (soft, sets `status=INACTIVE`)
- Search matches: `member_code`, person's `full_name`, `email`, `phone`.
- When creating a member:
  - If `personId` provided, create member linked to existing Person (must be CUSTOMER).
  - If person details provided without `personId`, create Person (CUSTOMER) first, then Member.
- Validation:
  - Cannot create member if Person already has a member record in this tenant.
  - `agrees_to_terms` must be true before status can transition to ACTIVE.

DoD:
- CRUD works with pagination + filters.
- Profile completion recalculated on update.

### C6A-BE-03 - Member profile completion logic

Scope:
- Define required profile fields for member activation:
  - `agrees_to_terms` (required)
- Calculate `profile_completion_percent` based on filled fields:
  - Only `agrees_to_terms` required in Cycle 6: 0% or 100%.
  - Future: add more fields and weighted calculation.
- Member cannot transition from `NEW` to `ACTIVE` until `profile_completion_percent = 100`.
- Expose validation errors listing missing required fields.

DoD:
- Profile completion calculated and persisted.
- Clear error messages for incomplete profiles.

### C6A-BE-04 - Member current expiry computation

Scope:
- `current_expiry_date` is computed from the latest `memberships.end_date` where `status = ACTIVE`.
- When memberships are created/updated/expired, trigger recomputation.
- Add service method `computeMemberExpiry(memberId)`.
- This is denormalized for query performance; source of truth is memberships table.

DoD:
- `current_expiry_date` stays in sync with memberships.

### C6A-BE-05 - Members permissions

Scope:
- Add permission codes:
  - `members.read`, `members.create`, `members.update`, `members.delete`
- Seed with group label `Members`.
- Apply `@RequirePermissions()` per endpoint.

DoD:
- Guards enforce access for all member endpoints.

### C6A-BE-06 - Error codes + shared types

Scope:
- Add error codes in `packages/shared`:
  - `MEMBER_ERRORS.NOT_FOUND`
  - `MEMBER_ERRORS.ALREADY_EXISTS`
  - `MEMBER_ERRORS.PERSON_NOT_CUSTOMER`
  - `MEMBER_ERRORS.PROFILE_INCOMPLETE`
  - `MEMBER_ERRORS.TERMS_NOT_AGREED`
- Add shared enums:
  - `MemberStatus`
- Export from shared index and rebuild package.

DoD:
- Errors used in all member exceptions.

---

## EPIC C6-1: Memberships

Goal: Track membership purchases with duration, expiry, and sales integration.

### C6B-BE-01 - Memberships entity + migration

Scope:
- Create `memberships` table:
  - `id` uuid PK
  - `tenant_id` uuid FK -> tenants
  - `member_id` uuid FK -> members
  - `item_id` uuid FK -> items (service_kind = MEMBERSHIP)
  - `item_name` string (snapshot)
  - `source_document_id` uuid FK -> documents nullable
  - `source_document_item_id` uuid FK -> document_items nullable
  - `status` enum: `ACTIVE | EXPIRED | CANCELLED` (default `ACTIVE`)
  - `start_date` date (required)
  - `end_date` date (required, computed from item duration)
  - `duration_value` int (snapshot from item)
  - `duration_unit` enum: `DAY | WEEK | MONTH | YEAR` (snapshot from item)
  - `price_paid` numeric(12,2)
  - `notes` text nullable
  - `cancelled_at` timestamptz nullable
  - `cancelled_reason` text nullable
  - `requires_review` boolean default false (set true on credit note)
  - audit columns + timestamps
- Constraints:
  - Member can have multiple membership records (extensions).
  - Each purchase creates a new record.

DoD:
- Migration runs successfully.
- Entity uses BaseAuditEntity.

### C6B-BE-02 - Membership expiry calculation

Scope:
- Implement calendar-based expiry with edge case handling:
  - `end_date` = `start_date` + duration using calendar math.
  - Edge case rule: if `start_date` is the last day of its month, `end_date` is the last day of the target month.
  - Example: Jan 31 + 1 month = Feb 28/29; Mar 31 + 1 month = Apr 30.
- Implement `calculateMembershipEndDate(startDate, durationValue, durationUnit)` utility.
- Handle chaining: if member has active membership ending in the future, new membership `start_date` = previous `end_date` + 1 day (or use provided start_date if later).

DoD:
- Expiry calculation handles all edge cases correctly.
- Membership chaining works for extensions.

### C6B-BE-03 - Memberships CRUD API

Scope:
- Endpoints:
  - `GET /memberships?memberId=&status=&page=&limit=`
  - `GET /memberships/:id`
  - `POST /memberships` (manual creation, requires `memberships.create`)
  - `PUT /memberships/:id` (update notes, dates if not from sales)
  - `POST /memberships/:id/cancel` (sets status=CANCELLED)
- On membership create:
  - Compute `end_date` from `start_date` + item duration.
  - If extending, chain from previous membership.
  - Trigger member expiry recomputation.
- On membership cancel:
  - Set `status = CANCELLED`, `cancelled_at`, `cancelled_reason`.
  - Trigger member expiry recomputation.
  - If member has no other active memberships, set member `status = EXPIRED`.

DoD:
- CRUD works with proper status management.
- Member expiry updates automatically.

### C6B-BE-04 - Sales posting hook for memberships

Scope:
- Hook into sales invoice posting handler (Cycle 5).
- When invoice contains items with `service_kind = MEMBERSHIP`:
  - Create `members` record if person doesn't have one (status = NEW).
  - Create `memberships` record with:
    - `source_document_id`, `source_document_item_id` linked.
    - `start_date` from sales header or default to posting date.
    - `end_date` computed from item duration.
    - `price_paid` from document_item.
  - If item has `included_pt_sessions > 0`, also create PT package (see C6C-BE-04).
- Transition member to ACTIVE if profile is complete and `agrees_to_terms`.

DoD:
- Memberships auto-created on invoice posting.
- Member status updated appropriately.

### C6B-BE-05 - Credit note handling for memberships

Scope:
- When credit note is posted against an invoice with memberships:
  - Set `memberships.requires_review = true` for linked membership.
  - Do NOT auto-cancel (manual decision required).
- Add endpoint: `POST /memberships/:id/clear-review` to acknowledge and decide action.

DoD:
- Credit notes flag memberships for review.
- Admin can resolve flagged memberships.

### C6B-BE-06 - Memberships permissions

Scope:
- Add permission codes:
  - `memberships.read`, `memberships.create`, `memberships.update`, `memberships.cancel`
- Seed with group label `Members`.

DoD:
- Guards enforce access.

### C6B-BE-07 - Membership history tracking

Scope:
- Create `membership_history` table:
  - `id` uuid PK
  - `membership_id` uuid FK -> memberships
  - `action` enum: `CREATED | EXTENDED | CANCELLED | EXPIRED | FLAGGED | CLEARED`
  - `from_status` enum nullable
  - `to_status` enum nullable
  - `notes` text nullable
  - `performed_by_user_id` uuid FK -> users
  - `performed_at` timestamptz
  - audit columns
- Insert history record on every membership state change.

DoD:
- Full audit trail for membership lifecycle.

---

## EPIC C6-2: PT Session Packages

Goal: Track PT session purchases with quota, expiry, and consumption.

### C6C-BE-01 - PT session packages entity + migration

Scope:
- Create `pt_session_packages` table:
  - `id` uuid PK
  - `tenant_id` uuid FK -> tenants
  - `member_id` uuid FK -> members
  - `item_id` uuid FK -> items (service_kind = PT_SESSION)
  - `item_name` string (snapshot)
  - `source_document_id` uuid FK -> documents nullable
  - `source_document_item_id` uuid FK -> document_items nullable
  - `source_membership_id` uuid FK -> memberships nullable (for included PT sessions)
  - `preferred_trainer_id` uuid FK -> people (STAFF) nullable
  - `status` enum: `ACTIVE | EXPIRED | EXHAUSTED | CANCELLED` (default `ACTIVE`)
  - `total_sessions` int (from item.session_count or membership.included_pt_sessions)
  - `used_sessions` int default 0
  - `remaining_sessions` int (computed: total - used)
  - `start_date` date
  - `expiry_date` date nullable (computed from item duration if defined)
  - `price_paid` numeric(12,2)
  - `notes` text nullable
  - `requires_review` boolean default false
  - audit columns + timestamps

DoD:
- Migration runs successfully.
- Entity tracks session quota.

### C6C-BE-02 - PT session package expiry calculation

Scope:
- If item has `duration_value` and `duration_unit`, package expires after that period from `start_date`.
- Use same calendar-based calculation as memberships.
- If no duration defined on item, package never expires (only exhausts).

DoD:
- Expiry calculated based on item configuration.

### C6C-BE-03 - PT session packages CRUD API

Scope:
- Endpoints:
  - `GET /pt-packages?memberId=&trainerId=&status=&page=&limit=`
  - `GET /pt-packages/:id`
  - `POST /pt-packages` (manual creation)
  - `PUT /pt-packages/:id` (update trainer, notes)
  - `POST /pt-packages/:id/cancel`
- Preferred trainer can be set/changed at any time.

DoD:
- CRUD works with quota tracking.

### C6C-BE-04 - Sales posting hook for PT packages

Scope:
- Hook into sales invoice posting handler.
- When invoice contains items with `service_kind = PT_SESSION`:
  - Create `members` record if person doesn't have one.
  - Create `pt_session_packages` record.
  - Allow specifying `preferred_trainer_id` on the sales line (via metadata or separate field).
- When membership item has `included_pt_sessions > 0`:
  - Create additional `pt_session_packages` with:
    - `source_membership_id` linked.
    - `total_sessions` = `included_pt_sessions`.
    - Expiry matches membership expiry.
    - `price_paid = 0` (included in membership).

DoD:
- PT packages auto-created on invoice posting.
- Included PT sessions handled correctly.

### C6C-BE-05 - PT session consumption

Scope:
- When a booking is marked COMPLETED:
  - Find oldest active PT package with `remaining_sessions > 0` (FIFO).
  - Increment `used_sessions`, decrement `remaining_sessions`.
  - If `remaining_sessions = 0`, set `status = EXHAUSTED`.
- When a booking is CANCELLED (not NO_SHOW):
  - Do NOT deduct session.
- When a booking is NO_SHOW:
  - Deduct session (gym policy - use it or lose it).
- Add endpoint: `POST /pt-packages/:id/deduct` for manual deduction by admin.

DoD:
- Session consumption tracked correctly.
- FIFO order respected.

### C6C-BE-06 - PT packages permissions

Scope:
- Add permission codes:
  - `pt_sessions.read`, `pt_sessions.create`, `pt_sessions.update`
- Seed with group label `PT Sessions`.

DoD:
- Guards enforce access.

---

## EPIC C6-3: Trainer Availability

Goal: Define trainer working hours with weekly templates and date overrides.

### C6D-BE-01 - Trainer availability entity + migration

Scope:
- Create `trainer_availability` table (weekly template):
  - `id` uuid PK
  - `tenant_id` uuid FK -> tenants
  - `trainer_id` uuid FK -> people (must be STAFF)
  - `day_of_week` int (0=Sunday, 1=Monday, ..., 6=Saturday)
  - `start_time` time
  - `end_time` time
  - `is_active` boolean default true
  - audit columns + timestamps
- Constraints:
  - unique (`tenant_id`, `trainer_id`, `day_of_week`, `start_time`)
  - `start_time < end_time`
- A trainer can have multiple time slots per day (e.g., 9-12, 14-18).

DoD:
- Migration runs successfully.
- Weekly template structure in place.

### C6D-BE-02 - Trainer availability overrides entity + migration

Scope:
- Create `trainer_availability_overrides` table:
  - `id` uuid PK
  - `tenant_id` uuid FK -> tenants
  - `trainer_id` uuid FK -> people
  - `date` date
  - `override_type` enum: `BLOCKED | MODIFIED`
  - `start_time` time nullable (for MODIFIED)
  - `end_time` time nullable (for MODIFIED)
  - `reason` text nullable
  - audit columns + timestamps
- BLOCKED: trainer unavailable entire day or specific hours.
- MODIFIED: different hours than template.
- Constraints:
  - unique (`tenant_id`, `trainer_id`, `date`, `override_type`, `start_time`)

DoD:
- Override structure supports exceptions.

### C6D-BE-03 - Trainer availability API

Scope:
- Endpoints:
  - `GET /trainer-availability?trainerId=` (returns weekly template)
  - `PUT /trainer-availability/:trainerId` (bulk update weekly template)
  - `GET /trainer-availability/:trainerId/overrides?dateFrom=&dateTo=`
  - `POST /trainer-availability/:trainerId/overrides`
  - `DELETE /trainer-availability/:trainerId/overrides/:id`
  - `GET /trainer-availability/:trainerId/slots?date=` (computed available slots for a date)
- Computed slots endpoint:
  1. Get weekly template for the day of week.
  2. Apply overrides for the specific date.
  3. Subtract existing bookings.
  4. Return available time windows.

DoD:
- Availability management works end-to-end.
- Computed slots account for bookings.

### C6D-BE-04 - Trainer availability permissions

Scope:
- Add permission codes:
  - `trainer_availability.read`, `trainer_availability.update`
- Seed with group label `Scheduling`.

DoD:
- Guards enforce access.

---

## EPIC C6-4: Schedule Bookings

Goal: Create and manage PT session bookings with calendar support.

### C6E-BE-01 - Schedule bookings entity + migration

Scope:
- Create `schedule_bookings` table:
  - `id` uuid PK
  - `tenant_id` uuid FK -> tenants
  - `booking_type` enum: `PT_SESSION | GROUP_SESSION` (extensible)
  - `member_id` uuid FK -> members
  - `trainer_id` uuid FK -> people (STAFF)
  - `pt_package_id` uuid FK -> pt_session_packages nullable
  - `group_session_id` uuid FK -> group_sessions nullable
  - `booking_date` date
  - `start_time` time
  - `end_time` time
  - `duration_minutes` int
  - `status` enum: `SCHEDULED | COMPLETED | CANCELLED | NO_SHOW` (default `SCHEDULED`)
  - `notes` text nullable
  - `completed_at` timestamptz nullable
  - `cancelled_at` timestamptz nullable
  - `cancelled_reason` text nullable
  - audit columns + timestamps
- Constraints:
  - No double-booking for same trainer at same time.
  - Booking must fall within trainer availability.
  - `duration_minutes` must be multiple of tenant slot duration setting.

DoD:
- Migration runs successfully.
- Booking structure supports PT and future class types.

### C6E-BE-02 - Tenant scheduling settings

Scope:
- Add to tenant_settings or create `scheduling_settings` table:
  - `slot_duration_minutes` int default 60
  - `booking_lead_time_hours` int default 0 (how far in advance to book)
  - `cancellation_window_hours` int default 24 (cancel without penalty)
- Expose via tenant settings API.

DoD:
- Scheduling settings configurable per tenant.

### C6E-BE-03 - Schedule bookings CRUD API

Scope:
- Endpoints:
  - `GET /bookings?trainerId=&memberId=&date=&dateFrom=&dateTo=&status=&type=&page=&limit=`
  - `GET /bookings/:id`
  - `POST /bookings`
  - `PUT /bookings/:id` (reschedule - change date/time)
  - `POST /bookings/:id/complete`
  - `POST /bookings/:id/cancel`
  - `POST /bookings/:id/no-show`
- On create:
  - Validate trainer availability.
  - Validate no conflicts.
  - Validate member has available PT sessions (if PT_SESSION type).
  - Validate duration is multiple of slot setting.
- On complete:
  - Deduct from PT package (FIFO).
  - Set `completed_at`.
- On no-show:
  - Deduct from PT package.
  - Record as NO_SHOW.
- On cancel:
  - Do NOT deduct from PT package.
  - Set `cancelled_at`, `cancelled_reason`.

DoD:
- Full booking lifecycle management.
- PT session consumption integrated.

### C6E-BE-04 - Calendar data endpoint

Scope:
- `GET /bookings/calendar?trainerId=&dateFrom=&dateTo=`
- Returns:
  - All bookings in date range.
  - Trainer availability slots.
  - Format optimized for calendar rendering.
- Support filtering by multiple trainers (comma-separated IDs).

DoD:
- Calendar UI can fetch all needed data in one call.

### C6E-BE-05 - Conflict detection

Scope:
- Before creating/updating a booking, check:
  - Trainer doesn't have another booking at the same time.
  - Time slot falls within trainer availability.
  - No override blocking the time.
- Return clear error with conflict details.
- Use database-level locking to prevent race conditions.

DoD:
- Double-booking prevented.
- Clear error messages.

### C6E-BE-06 - Schedule bookings permissions

Scope:
- Add permission codes:
  - `schedules.read`, `schedules.create`, `schedules.update`, `schedules.delete`
- Seed with group label `Scheduling`.

DoD:
- Guards enforce access.

---

## EPIC C6-5: Private Group Sessions

Goal: Support private group sessions where one member purchases a PT package for multiple participants (e.g., group PT training). Uses existing `service_kind = PT_SESSION` with `max_participants > 1`.

### C6F-BE-01 - Group sessions entity + migration

Scope:
- Create `group_sessions` table:
  - `id` uuid PK
  - `tenant_id` uuid FK -> tenants
  - `purchaser_member_id` uuid FK -> members (who bought the package)
  - `item_id` uuid FK -> items (service_kind = PT_SESSION with max_participants > 1)
  - `item_name` string (snapshot)
  - `source_document_id` uuid FK -> documents nullable
  - `source_document_item_id` uuid FK -> document_items nullable
  - `instructor_id` uuid FK -> people (STAFF)
  - `status` enum: `ACTIVE | EXPIRED | EXHAUSTED | CANCELLED` (default `ACTIVE`)
  - `total_sessions` int
  - `used_sessions` int default 0
  - `remaining_sessions` int
  - `max_participants` int (from item config)
  - `start_date` date
  - `expiry_date` date nullable
  - `notes` text nullable
  - audit columns + timestamps

DoD:
- Migration runs successfully.
- Group session package structure in place.

### C6F-BE-02 - Group session participants entity + migration

Scope:
- Create `group_session_participants` table:
  - `id` uuid PK
  - `group_session_id` uuid FK -> group_sessions
  - `member_id` uuid FK -> members (required - participants must be existing members)
  - `is_active` boolean default true
  - audit columns + timestamps
- Constraints:
  - `member_id` is required (no guest participants).
  - Total active participants <= `group_sessions.max_participants`.

DoD:
- Participant tracking for existing members only.

### C6F-BE-03 - Group sessions CRUD API

Scope:
- Endpoints:
  - `GET /group-sessions?memberId=&instructorId=&status=&page=&limit=`
  - `GET /group-sessions/:id`
  - `POST /group-sessions` (manual creation)
  - `PUT /group-sessions/:id` (update instructor, notes)
  - `POST /group-sessions/:id/cancel`
  - `GET /group-sessions/:id/participants`
  - `POST /group-sessions/:id/participants` (add existing member as participant)
  - `DELETE /group-sessions/:id/participants/:participantId`
- Validate max participants not exceeded.
- Participant must be an existing member.

DoD:
- Group session management works end-to-end.

### C6F-BE-04 - Group session booking integration

Scope:
- When creating a booking with `booking_type = GROUP_SESSION`:
  - Link to `group_session_id`.
  - On complete, deduct from group session `remaining_sessions`.
  - All participants in the group session are considered attended.
- Schedule bookings for group sessions work the same as PT sessions.

DoD:
- Group session slots tracked through booking system.

### C6F-BE-05 - Sales posting hook for group sessions

Scope:
- When invoice contains items with `service_kind = PT_SESSION` AND `max_participants > 1`:
  - Create `group_sessions` record instead of `pt_session_packages`.
  - Admin can later add participants (other members).
- **Prerequisite (Cycle 4 update):** Add `max_participants` field to items table (default 1 for regular PT, > 1 for group sessions).

DoD:
- Group sessions auto-created on invoice posting for group PT items.

### C6F-BE-06 - Group sessions permissions

Scope:
- Add permission codes:
  - `group_sessions.read`, `group_sessions.create`, `group_sessions.update`, `group_sessions.delete`
- Seed with group label `Group Sessions`.

DoD:
- Guards enforce access.

---

## EPIC C6-6: Attendance & Check-in

Goal: Track member gym entry and activity attendance.

### C6G-BE-01 - Attendance entity + migration

Scope:
- Create `attendance_records` table:
  - `id` uuid PK
  - `tenant_id` uuid FK -> tenants
  - `member_id` uuid FK -> members
  - `attendance_type` enum: `GYM_ENTRY | PT_SESSION | GROUP_CLASS`
  - `booking_id` uuid FK -> schedule_bookings nullable
  - `check_in_at` timestamptz
  - `check_out_at` timestamptz nullable
  - `check_in_method` enum: `MANUAL | QR_CODE | MEMBER_CODE`
  - `checked_in_by_user_id` uuid FK -> users nullable
  - `notes` text nullable
  - audit columns + timestamps

DoD:
- Migration runs successfully.
- Attendance tracking structure in place.

### C6G-BE-02 - Check-in API

Scope:
- Endpoints:
  - `POST /attendance/check-in`
    - Payload: `{ memberId, memberCode, memberPhone, attendanceType, bookingId? }`
    - Lookup member by ID, code, or phone.
    - Validate member has active membership (current_expiry_date >= today).
    - Block if expired (return error with expiry date).
    - Create attendance record.
  - `POST /attendance/check-out`
    - Find open attendance record, set `check_out_at`.
  - `GET /attendance?memberId=&dateFrom=&dateTo=&type=&page=&limit=`
  - `GET /attendance/today` (quick view of today's check-ins)
- Check-in for PT_SESSION or GROUP_CLASS:
  - Requires valid booking for today.
  - Marks booking as COMPLETED.
  - Deducts from package.

DoD:
- Check-in flow validates membership.
- Activity attendance linked to bookings.

### C6G-BE-03 - Member lookup for check-in

Scope:
- `GET /members/lookup?q=` (quick search for check-in)
- Search by: member_code, person full_name, phone.
- Return: member_id, member_code, person name, current_expiry_date, status.
- Highlight if expired or expiring soon.

DoD:
- Fast member lookup for check-in UI.

### C6G-BE-04 - Attendance permissions

Scope:
- Add permission codes:
  - `attendance.read`, `attendance.create`
- Seed with group label `Attendance`.

DoD:
- Guards enforce access.

---

## EPIC C6-7: Notifications & Expiry Jobs

Goal: Automated membership expiry transitions and admin notifications.

### C6H-BE-01 - Membership expiry job

Scope:
- BullMQ repeat job (daily at configurable time, default 00:00):
  - Find all memberships where `end_date < today` and `status = ACTIVE`.
  - Transition to `status = EXPIRED`.
  - Log to membership_history.
  - Recompute member `current_expiry_date`.
  - If member has no active memberships, set member `status = EXPIRED`.

DoD:
- Expired memberships transitioned automatically.

### C6H-BE-02 - PT package expiry job

Scope:
- BullMQ repeat job (daily):
  - Find all PT packages where `expiry_date < today` and `status = ACTIVE`.
  - Transition to `status = EXPIRED`.
  - Forfeit remaining sessions (no refund).

DoD:
- Expired PT packages transitioned automatically.

### C6H-BE-03 - Expiry notification job

Scope:
- BullMQ repeat job (daily at configurable time, default 08:00):
  - Find memberships expiring in [7, 5, 3, 1] days.
  - Create in-app notification for users with `members.read` permission.
  - Notification content: "Member {name} ({code}) membership expires in {N} days."
  - Track which notifications have been sent (avoid duplicates).
- Create `notification_log` table to track sent notifications:
  - `id`, `tenant_id`, `notification_type`, `reference_id`, `days_before`, `sent_at`

DoD:
- Expiry notifications sent to admins.
- No duplicate notifications.

### C6H-BE-04 - In-app notifications entity + migration

Scope:
- Create `notifications` table:
  - `id` uuid PK
  - `tenant_id` uuid FK -> tenants
  - `user_id` uuid FK -> users
  - `type` enum: `MEMBERSHIP_EXPIRING | PT_EXPIRING | BOOKING_REMINDER`
  - `title` string
  - `message` text
  - `reference_type` string nullable (e.g., 'member', 'membership')
  - `reference_id` uuid nullable
  - `is_read` boolean default false
  - `read_at` timestamptz nullable
  - `created_at` timestamptz

DoD:
- In-app notification structure in place.

### C6H-BE-05 - Notifications API

Scope:
- Endpoints:
  - `GET /notifications?unreadOnly=&page=&limit=` (current user's notifications)
  - `GET /notifications/count` (unread count for badge)
  - `POST /notifications/:id/read`
  - `POST /notifications/read-all`

DoD:
- Users can view and manage their notifications.

---

## EPIC C6-8: Frontend - Members UI

Goal: Admin UI for member management.

### C6A-FE-01 - Members sidebar + routes

Scope:
- Add Members section in sidebar.
- Routes:
  - `/members` (list)
  - `/members/new`
  - `/members/[id]` (detail)
  - `/members/[id]/edit`
- Access guarded by `members.*` permissions.

DoD:
- Menu visibility respects permissions.

### C6A-FE-02 - Members list page

Scope:
- Filters: Status, Search (code, name, phone, email).
- Columns: Code, Name, Phone, Email, Status badge, Expiry Date, Profile %, Actions.
- Actions: View, Edit, Deactivate.
- "New Member" button gated by `members.create`.
- Highlight members expiring soon (within 7 days).

DoD:
- List supports search + pagination.
- Expiry highlighting works.

### C6A-FE-03 - Member detail page

Scope:
- Display member info with person details.
- Profile completion progress indicator.
- Terms agreement checkbox (if not agreed).
- Tabs:
  - Overview (status, expiry, profile)
  - Memberships (list of membership records)
  - PT Packages (list of PT session packages)
  - Attendance (recent check-ins)
  - History (membership history log)

DoD:
- Comprehensive member view.

### C6A-FE-04 - Member form (new/edit)

Scope:
- If creating from existing Person: person selector.
- If creating new: person fields (name, email, phone).
- Profile fields: terms agreement.
- Status dropdown (on edit only).
- Notes field.

DoD:
- Create and edit flows work.

---

## EPIC C6-9: Frontend - Memberships UI

### C6B-FE-01 - Memberships list (within member detail)

Scope:
- Table showing all memberships for a member.
- Columns: Item, Status, Start Date, End Date, Price, Source (manual/invoice), Actions.
- Actions: View invoice (if linked), Cancel.
- "Add Membership" button (manual creation).

DoD:
- Membership history visible per member.

### C6B-FE-02 - Manual membership creation dialog

Scope:
- Item selector (filtered to MEMBERSHIP service_kind).
- Start date picker (default today).
- End date computed and displayed.
- Price field (can differ from item price for comps).
- Notes field.

DoD:
- Manual membership creation works.

---

## EPIC C6-10: Frontend - PT Packages UI

### C6C-FE-01 - PT packages list (within member detail)

Scope:
- Table showing all PT packages for a member.
- Columns: Item, Trainer, Status, Sessions (used/total), Expiry, Source, Actions.
- Actions: Change trainer, Cancel.

DoD:
- PT package tracking visible per member.

### C6C-FE-02 - Manual PT package creation

Scope:
- Item selector (filtered to PT_SESSION service_kind).
- Trainer selector (STAFF people).
- Sessions count (from item, can override).
- Start date, expiry date.

DoD:
- Manual PT package creation works.

---

## EPIC C6-11: Frontend - Scheduling Calendar UI

### C6D-FE-01 - Scheduling routes

Scope:
- Routes:
  - `/scheduling` (calendar view)
  - `/scheduling/availability` (trainer availability management)
- Access guarded by `schedules.*` permissions.

DoD:
- Routes and guards in place.

### C6D-FE-02 - Week calendar view

Scope:
- Custom calendar component built with shadcn/ui + Tailwind.
- Week view showing 7 days with hourly slots.
- Display:
  - Trainer availability as colored backgrounds.
  - Bookings as event cards (color-coded by type/trainer).
- Multi-trainer filter: select multiple trainers, each with distinct color.
- Navigation: prev/next week, jump to date.
- Responsive design for various screen sizes.

DoD:
- Week calendar displays availability and bookings.
- Multiple trainers shown with color coding.

### C6D-FE-03 - Booking creation modal

Scope:
- Opens when clicking available slot.
- Pre-filled: trainer, date, start time.
- Fields:
  - Member selector (search by name/code/phone).
  - Booking type (PT_SESSION, GROUP_SESSION).
  - PT package selector (member's active packages).
  - End time / duration.
  - Notes.
- Validation:
  - Member has active package with remaining sessions.
  - Time within trainer availability.
  - No conflicts.

DoD:
- Booking creation works from calendar.

### C6D-FE-04 - Booking detail/edit modal

Scope:
- Opens when clicking existing booking.
- Show booking details.
- Actions:
  - Reschedule (change date/time).
  - Complete (mark done, deduct session).
  - Cancel.
  - No-show.
- Status badge and history.

DoD:
- Booking management works from calendar.

### C6D-FE-05 - Trainer availability management

Scope:
- Route: `/scheduling/availability`
- Trainer selector.
- Weekly template grid:
  - 7-day × 24-hour grid.
  - Click/drag to set available hours.
  - Save template.
- Date overrides:
  - Date picker.
  - Add blocked date or modified hours.
  - List of upcoming overrides.

DoD:
- Trainer availability fully manageable.

---

## EPIC C6-12: Frontend - Private Group Sessions UI

### C6F-FE-01 - Group sessions list page

Scope:
- Route: `/group-sessions`
- Filters: Status, Instructor, Purchaser.
- Columns: Purchaser, Instructor, Sessions (used/total), Participants, Status, Actions.

DoD:
- Group session packages listed.

### C6F-FE-02 - Group session detail page

Scope:
- Show session info, purchaser, instructor.
- Participants list:
  - Add member (search existing members).
  - Remove participant.
- Sessions history.

DoD:
- Participant management works (members only).

---

## EPIC C6-13: Frontend - Attendance UI

### C6G-FE-01 - Check-in page

Scope:
- Route: `/attendance/check-in`
- Quick member lookup (search box).
- Display member card:
  - Photo placeholder, name, code.
  - Membership status and expiry.
  - Today's bookings (if any).
- Action buttons:
  - Check In (gym entry).
  - Check In to Booking (select from today's bookings).
- Success/error feedback.
- Block check-in for expired members with clear message.

DoD:
- Check-in flow works for staff.

### C6G-FE-02 - Attendance history page

Scope:
- Route: `/attendance`
- Filters: Date range, Member, Type.
- Columns: Member, Type, Check-in Time, Check-out Time, Method, Staff.
- Export to CSV.

DoD:
- Attendance history viewable.

### C6G-FE-03 - Today's attendance widget

Scope:
- Dashboard widget showing:
  - Today's check-in count.
  - Recent check-ins list.
  - Quick check-in button.

DoD:
- Quick attendance overview on dashboard.

---

## EPIC C6-14: Frontend - Notifications UI

### C6H-FE-01 - Notification bell + dropdown

Scope:
- Bell icon in header with unread count badge.
- Dropdown showing recent notifications.
- Click notification to navigate to relevant page.
- "Mark all read" action.
- "View all" link to notifications page.

DoD:
- In-app notifications accessible from header.

### C6H-FE-02 - Notifications page

Scope:
- Route: `/notifications`
- List all notifications with read/unread state.
- Filter: unread only.
- Pagination.

DoD:
- Full notification history viewable.

### C6H-FE-03 - Expiring memberships widget

Scope:
- Dashboard widget for admins:
  - "Expiring Soon" section.
  - List members expiring in next 7 days.
  - Click to view member.

DoD:
- Proactive visibility into expiring memberships.

---

## Shared Updates

Scope:
- Add to `packages/shared/src/constants/error-codes.ts`:
  - `MEMBER_ERRORS`
  - `MEMBERSHIP_ERRORS`
  - `PT_PACKAGE_ERRORS`
  - `BOOKING_ERRORS`
  - `ATTENDANCE_ERRORS`
  - `GROUP_SESSION_ERRORS`
- Add to `packages/shared/src/constants/permissions.ts`:
  - All new permission constants
- Add shared types/enums:
  - `MemberStatus`, `MembershipStatus`, `PTPackageStatus`
  - `BookingType`, `BookingStatus`
  - `AttendanceType`, `CheckInMethod`
  - `GroupSessionStatus`
- Rebuild: `pnpm --filter @gym-monorepo/shared build`

---

## Seed Data

Scope:
- Seed permissions with group labels.
- No seed members/memberships (created via sales flow or manual).

---

## Suggested Execution Order

### Phase 1: Members + Memberships Core
1. C6A-BE-01 → C6A-BE-06 (Members backend)
2. C6B-BE-01 → C6B-BE-07 (Memberships backend)
3. C6A-FE-01 → C6A-FE-04 (Members UI)
4. C6B-FE-01 → C6B-FE-02 (Memberships UI)

### Phase 2: PT Packages
5. C6C-BE-01 → C6C-BE-06 (PT Packages backend)
6. C6C-FE-01 → C6C-FE-02 (PT Packages UI)

### Phase 3: Trainer Availability + Scheduling
7. C6D-BE-01 → C6D-BE-04 (Trainer Availability backend)
8. C6E-BE-01 → C6E-BE-06 (Bookings backend)
9. C6D-FE-01 → C6D-FE-05 (Calendar UI)

### Phase 4: Private Group Sessions
10. C6F-BE-01 → C6F-BE-06 (Group Sessions backend)
11. C6F-FE-01 → C6F-FE-02 (Group Sessions UI)

### Phase 5: Attendance + Check-in
12. C6G-BE-01 → C6G-BE-04 (Attendance backend)
13. C6G-FE-01 → C6G-FE-03 (Attendance UI)

### Phase 6: Notifications + Jobs
14. C6H-BE-01 → C6H-BE-05 (Jobs + Notifications backend)
15. C6H-FE-01 → C6H-FE-03 (Notifications UI)

---

## Out of Scope (Cycle 6)
- Member self-service portal / member-facing app
- QR code scanning (future - manual check-in only)
- Email notifications (in-app only)
- Mobile push notifications
- Membership freeze/hold
- Membership transfers
- Public group fitness classes (only private group sessions)
- Facility/equipment booking
- Payment integration (handled by Sales)
- Waitlist for fully-booked slots (deferred to future cycle)
