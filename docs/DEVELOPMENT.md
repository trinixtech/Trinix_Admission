# Development guide

## Quick start (no Docker)

Needs PHP 8.3+ (extensions: pdo_sqlite, intl, zip, redis optional), Composer and Node 22.

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed --class=DemoSeeder      # catalogue + institution + demo Super Admin
npm run build                               # or: npm run dev (hot reload) in another terminal
php artisan serve                           # http://localhost:8000
```

Sign in with `admin@example.test` / `change-me-please-123` (from `.env`). The Super Admin role needs two-factor sign-in, so the first screen asks you to set it up with an authenticator app.

With `QUEUE_CONNECTION=database` run `php artisan queue:work` so invitation emails go out; set `QUEUE_CONNECTION=sync` to send them at once. With `MAIL_MAILER=log` emails (including invitation links) are written to `storage/logs/laravel.log`.

## Full stack with Docker (two app servers)

```bash
docker compose up --build
```

| What | Where |
| --- | --- |
| App (through the load balancer) | http://localhost:8080 |
| Mail catcher (Mailpit) | http://localhost:8025 |
| Object storage console (MinIO) | http://localhost:9001 |

`app1` and `app2` run the same code behind an nginx load balancer, sharing sessions, cache, locks and queues in Redis and files in MinIO (S3-compatible). Stop one (`docker compose stop app1`) and the other keeps serving everyone without signing them out. The `worker` runs queues (Laravel Horizon, dashboard at `/horizon` for users with *System health*), and `scheduler` runs scheduled tasks; each scheduled task runs on one server only.

## Tests and checks

```bash
vendor/bin/pest                    # backend tests (SQLite in memory; CI also runs them on MySQL 8)
vendor/bin/pint                    # PHP code style
php tools/check-migrations.php     # migrations must be safe to run while the app is live
npm run lint && npx vue-tsc --noEmit && npm test    # frontend lint, types, Vitest
node tests/e2e/walkthrough.mjs          # browser walk-through; needs the app running (see the file header)
```

GitHub Actions (`.github/workflows/ci.yml`) runs all of these on every push and pull request.

## Project layout

| Path | What it is |
| --- | --- |
| `app/Modules/Organisation` | Institution, locations, branches, courses, branch–course links |
| `app/Modules/Cycles` | Academic years, admission cycles, course offerings, rounds and round dates |
| `app/Modules/Access` | Users, roles, permissions, scoped assignments, sign-in rules, invitations |
| `app/Modules/Teams` | Committees, admission cells, accounts teams and members |
| `app/Modules/Audit` | Append-only audit log (`Auditor` service) |
| `app/Modules/System` | Health checks and system settings |
| `app/Modules/Imports` | Sources, mapping templates, import batches, the `ImportPipeline` (stage → validate → classify → approve → write), portal push API |
| `app/Modules/Applicants` | Applicants, applications, entrance, academics, preferences, round participation, corrections; `ApplicationAccess` scope rules |
| `app/Modules/Documents` | Document types and requirements, `DocumentStore` (versions, scanning), ZIP/link imports, signed downloads |
| `app/Modules/Scrutiny` | Checklists, scrutiny records, field locks and field reviews, deficiency notices |
| `app/Modules/Eligibility` | Criteria (versioned, approved), evaluation with relaxations, decisions and confirmations |
| `app/Modules/Selection` | Call rules and lists (`ShortlistService`), stages and criteria, panels, slots and scheduling (`PanelService`), live marking, locking and sending on (`EvaluationService`), panel scope (`PanelAccess`), contact log, applicant emails (`Notifier`), day-before reminders |
| `app/Modules/Allocation` | Phase 6: reservation categories, rule sets, `SeatCalculator` (rounding methods), seat matrices, `AllocationEngine` (merit + preferences, open first, conversions until stable), `AllocationService` (runs, approval, publication, automatic vacancy runs), `OfferService` (answers, payments, holds, lapses, reminders) |
| `app/Modules/Accounts` | Phase 7: `FeeService` (fee structures, concessions, refund policies), `AdmissionService` (confirmation, numbering, dues, conditions, withdrawals), `Ledger` (balances; only verified, unreversed payments count), `FeePayments` (fee payments, reversals, adjustments, receipts, reminders), `RefundService`, `BankStatements`, `Reports` |
| `app/Modules/Merit` | Merit formulas (versioned, approved), `MeritEngine` (exact-decimal scoring, ranks, tie-breaks), merit lists (run, approve, publish, out-of-date check, compare), Excel and PDF exports |
| `config/selection.php` | Starting stage names per course level, categories, contact channels and outcomes, default email templates |
| `config/documents.php` | Scanner settings, ZIP/link limits, and the suggested document types, checklists and draft eligibility rules |
| `app/Support/Scope.php` | Branch/course/cycle scope and the *covers* / *overlaps* rules |
| `config/access.php` | Roles, permissions, role conflicts and default settings; `php artisan access:sync` applies it |
| `routes/api.php` | REST API under `/api/v1` (sign-in routes come from Laravel Fortify under the same prefix) |
| `resources/js` | Vue 3 single-page app (pages, components, Pinia store, router) |
| `tests/Feature`, `tests/Unit` | Pest tests |
| `tests/e2e/walkthrough.mjs` | Playwright walk-through of the Phases 1 to 7 acceptance steps |

## Imports

```bash
php artisan imports:make-sample storage/app/sample.csv --rows=200 --branches=BR1,BR2   # portal-style CSV, dummy data
php artisan imports:make-sample storage/app/round2.csv --rows=250 --vary=5             # same people, some changed, 50 new
php artisan imports:benchmark --applications=500000 --import-rows=100000               # scale check (never in production)
```

Upload the sample on **Imports → New import**, match the columns (save them as a template), check the rows, and have a different user approve. Import jobs run on the `imports` queue (`php artisan queue:work --queue=imports,default`, or Horizon). The portal can push records to `POST /api/v1/integration/import-batches` with a source token created on **Sources** (Super Admin).

## Documents and virus scanning

`DOCUMENT_SCANNER=clamav` (default; Docker Compose runs ClamAV) or `eicar` for local development and tests, which only flags the standard EICAR test file. Files are viewable only after a clean scan; `php artisan documents:scan-pending` (scheduled every 5 minutes) retries scans after the scanner was down. Document links must be on the allowed host list (**Settings → Document URL hosts**, IT Administrator).

To try it: **Scrutiny → Documents and checklists**, pick a course, *Add suggested*, *New from suggestions*, *Activate*; **Eligibility rules**, *Start from suggested rules*, fill in the source, *Approve*; then **Imports → Import documents** with a ZIP (see `tests/e2e/make-docs-zip.php`).

## Selection (Phase 4)

To try it after the Phase 3 steps: **Selection → Stages and criteria**, *Start from the usual stages*, add criteria; **Call lists**, *Enter cut-offs*, approve with a source, *Run the cut-offs*, and have another user publish (called applicants are emailed); **Interview panels**, *New panel*, add a chair and members, add slots, *Fill and email*. Panel members mark on **My interviews** (a GD slot shows the whole group; a PI slot shows one candidate with ± steppers); marks autosave. The chair locks results; **Results** on the panel sends locked candidates on to a PI panel. Staff record calls on the application's **Contact** tab; due follow-ups are on **Follow-ups**. `php artisan interviews:send-reminders` (scheduled daily, 09:00 IST) emails the applicants interviewed the next day.

## Admission and accounts (Phase 7)

**Admission and accounts → Fee structure**: per cycle, heads, instalments, amounts per campus and course, concessions, source; another person approves. **Refund policy**: load the suggested UGC slabs, check, add the source; another person approves. Set each campus's **last date of admission** on the round page. **Admissions**: set the number pattern, then *Confirm admission* for accepted offers with no blockers. The application's **Fees** tab records payments, adjustments, conditions and withdrawals. **Payments**: Accounts verifies, returns or rejects, and approves reversals and adjustments. **Refunds**, **Bank statements**, **Dashboard and reports**. `php artisan fees:send-reminders` runs daily from the scheduler.

## Seats and offers (Phase 6)

**Seats and offers → Categories**: *Load the suggested list* (names only) and edit. **Reservation rules**: per cycle, percentages, rounding, conversions and source; another person approves. **Seats and offer settings**: *Calculate seats*, adjust cells with a reason, another person approves; set reply days, reminders, acceptance and hold amounts, waitlist ratios. **Seat allocation**: *Run the allocation*, another person *Approve and publish* (offers emailed). **Offers and waitlist**: record answers with payment, hold payments, move-up wishes, confirm lapses past the deadline. **Payments**: Accounts verifies or rejects. Freed seats prepare a vacancy run automatically; it waits for approval. `php artisan offers:send-reminders` runs daily from the scheduler.

## Merit (Phase 5)

**Merit → Merit formula**: pick a course, *Start a formula*, add components (what each reads, how it becomes points, weight, optional cap and minimums), tie-breaks and the source, *Save draft*, try it on one application number, *Approve*. **Merit lists**: pick course and round, *Run the formula*, check the Ranked, Left out and Shared ranks tabs (tap a name for the breakdown), then another person approves and publishes. *Check for changes* compares a list with today's data and re-scores its stored inputs.

## Stage A

- **Faculty import:** People → Users → Import faculty (CSV/XLSX: Name, Email, Mobile, Designation, Department, Campus, Role).
- **Automatic panels:** Interview panels → Assign faculty automatically / Allocate candidates automatically (both preview first). The sample portal file has a `GDPI Centre 1` column.
- **Stage formula:** Stages and criteria → "Stage result from panel members' totals", e.g. `(SUM - MAX - MIN) / (COUNT - 2)`.
- **Installer:** on a fresh copy without `APP_KEY`, open `/install`. With `php artisan serve`, the server restarts when `.env` changes, so the request after "Save settings" may need a reload; Nginx/PHP-FPM is not affected. Set `INSTALLER_ENFORCE=false` to work without the redirect.

## How access works

- A user holds roles through **assignments**, each with an optional branch, course and cycle (empty = all).
- **Writes** need a grant whose scope *covers* the record; **reads** need one that *overlaps* it.
- Records outside what a user can see return **404**; visible records they may not change return **403**.
- Nobody can give a role or scope wider than their own, change their own roles, or hold two conflicting roles on overlapping scope.
- Every change writes an audit row in the same database transaction.

## Rules for database changes

Every migration must be safe while older app servers are still running (no downtime): add first, use in the next release, remove in a later one. `tools/check-migrations.php` rejects drops, renames and type changes in `up()` unless the migration says `live-safe: <reason>`.

## Production notes (for Phase 9)

- Run at least two app servers behind a load balancer that checks `GET /up` (503 when the database, Redis or storage fails).
- `SESSION_DRIVER`, `CACHE_STORE` and `QUEUE_CONNECTION` must be `redis`; `PRIVATE_DISK_DRIVER=s3`.
- Set `TRUSTED_PROXIES` to the load balancer's addresses.
- Grant the app's database user INSERT and SELECT only on `audit_logs` (no UPDATE or DELETE) so the audit log stays append-only at database level too.
- After each deploy: `php artisan migrate --force && php artisan access:sync`.
