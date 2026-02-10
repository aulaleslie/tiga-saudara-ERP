# Phase 0 — Codebase Discovery

Date: 2026-02-08  
Repository: `tiga-saudara-ERP`

## Project Overview
- Stack: PHP 8.1, Laravel 10, Livewire 3, Blade, Alpine.js, CoreUI/Bootstrap, MySQL (local), SQLite in-memory for tests.
- Architecture style: modular monolith using `nwidart/laravel-modules` (`Modules/*`) plus shared app layer (`app/*`).
- Runtime characteristics:
- Multi-business context is session-based (`setting_id`) and enforced via middleware/role syncing.
- Server-rendered UI with Livewire/AJAX enhancements rather than SPA API-first architecture.
- Realtime is present (Pusher + Echo server config), but used for targeted features (e.g., receipt print jobs, WS monitor).

## Key Directories And Responsibilities
- `app/`
- Shared framework-level concerns: middleware, auth, global controllers, helpers, services.
- Livewire components that are cross-module or app-scoped.
- `Modules/`
- Domain modules (`Purchase`, `PurchasesReturn`, `Sale`, `SalesReturn`, `Product`, `Setting`, etc.).
- Each module generally contains `Entities`, `Http/Controllers`, `Routes`, `Database/Migrations`, `Resources`, and often `Tests`.
- `routes/`
- Global web/api/channel routes.
- `database/`
- Core migrations + seeders + cross-module migration patches.
- `resources/`
- Shared Blade views, JS, Sass, Livewire templates.
- `tests/`
- Primary PHPUnit suites configured in `phpunit.xml`.
- `docs/` and `ai-docs/`
- Existing design/requirements notes for past initiatives.

## API Surface (REST / WebSocket / Events)
- HTTP routes:
- Total registered routes: **412** (`php artisan route:list`).
- API routes under `/api`: **35** (mix of controller and closure routes).
- Purchase receive flow:
- `GET /purchases/{purchase}/receive`
- `POST /purchases/{purchase}/receive`
- `POST /receivings/{receivedNote}/approve`
- Serial validation endpoints:
- `POST /serial-numbers/validate`
- `POST /serial-numbers/validate-dispatch`
- `POST /serial-numbers/validate-purchase-return`
- Purchase return settlement/dispatch endpoints are extensive under `purchase-returns/*`.
- Real-time / WebSocket:
- Broadcast config uses Pusher-compatible driver.
- WS monitoring routes:
- `GET /ws-monitor`
- `GET /ws-monitor/data`
- `GET /ws-monitor/presence/{name}`
- Broadcast channels defined in `routes/channels.php` (`App.Models.User.{id}`, `print.{userId}`).
- Event:
- `App\Events\PrintReceiptJob` broadcasts immediately (`ShouldBroadcastNow`) on `print-receipt-job.{userId}`.

## DB Schema Overview (Migrations, Entities)
- Migrations:
- Root migrations: **40**
- Module migrations: **170**
- Total migration files discovered: **210**
- Tables in `schema.sql`: **88**
- Entity layer:
- Module entities are primary domain models (`Modules/*/Entities/*.php`).
- Shared base model (`App\Models\BaseModel`) enforces uppercase normalization for most string fields.
- Key purchasing/serial tables relevant to current issue:
- `purchases`, `purchase_details`, `purchase_payments`
- `received_notes`, `received_note_details` (with `pending_serial_numbers` JSON and approval fields)
- `product_serial_numbers` (status + return-process flags + links to receive/dispatch/return)
- `serial_number_histories` (event log for serial lifecycle)
- `purchase_returns`, `purchase_return_details`, `purchase_return_item_settlements`, `purchase_return_settlements`
- Important schema evolution notes:
- Serial uniqueness moved from global `serial_number` unique to composite `product_id + serial_number`.
- Serial lifecycle/status columns added later (`status`, `is_in_return_process`, `purchase_return_id`).
- Significant cross-entity unique constraints added by migration (`2025_12_01_130006_*`).

## Existing Test Strategy
- Framework: PHPUnit 10 via `php artisan test`.
- `phpunit.xml` suites include only:
- `./tests/Unit`
- `./tests/Feature`
- Test DB defaults to in-memory SQLite (`DB_DATABASE=:memory:` in test config).
- Current inventory:
- `tests/`: **70** test files.
- `Modules/*/Tests`: **38** test files (not automatically included by default suite unless explicitly targeted).
- Patterns:
- Heavy use of `RefreshDatabase`.
- Frequent direct model creation + explicit session setup (`setting_id`).
- Permission gating often arranged in test setup (`Spatie\Permission`).
- Livewire tests are used for form workflow and row/serial interactions.

## Notable Conventions Or Invariants
- Multi-tenant behavior is setting-scoped:
- Session key `setting_id` drives data visibility and role behavior.
- Middleware `role.setting` syncs user role dynamically based on selected setting.
- Authorization model:
- Permission strings are used extensively (`Gate` / Spatie roles-permissions).
- `Super Admin` bypass is handled in `Gate::before`.
- String normalization:
- `BaseModel` uppercases most string attributes on write.
- Domain statuses are often expected in uppercase, though historical mixed-case exists.
- Serial lifecycle intent:
- `ACTIVE` → `RETURN_IN_PROCESS` → `RETURNED` (or re-activated), plus `BROKEN`.
- History events are recorded (`RECEIVED`, `SOLD`, `PURCHASE_RETURNED`, `REPAIR_RECEIVED`, etc.).
- Receiving flow is two-stage:
- Input serials stored first in `received_note_details.pending_serial_numbers`.
- Serials committed into `product_serial_numbers` only on receiving approval.

## Assumptions And Risks
- Verified with Tinker on local data (MySQL):
- Purchase `id=3` exists (`TNC-BL-2026-02-00003`) with serial-tracked product `id=2`.
- A serial exists for that product: `202602080001`, status `RETURNED`, `purchase_return_id=1`.
- Current serial validation logic check outcome:
- `existsCommitted_currentQuery = true`
- `existsActive_ifFiltered = false`
- `existsReturned = true`
- This confirms current duplicate check flags returned serials as duplicates.
- Behavioral risk:
- `SerialNumberController::validateSerial()` and purchase receive duplicate checks do not filter out `RETURNED` serials.
- Test coverage risk:
- Many module tests exist but are outside default PHPUnit suite paths; regressions there may go unnoticed in standard runs.
- Data consistency risk:
- Mixed historical status casing and many lifecycle migrations can produce edge-case status behavior across environments.
- Architecture risk:
- Large closure-heavy `routes/api.php` and broad controller responsibilities increase regression risk for cross-flow changes.
- Security/ops risk:
- Helper-level hardcoded fallback Pusher credentials and mixed old/new frontend pipelines (Vite + module Mix) increase maintenance risk.
