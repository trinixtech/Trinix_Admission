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
| `app/Modules/Teams` | Committees, admission cells and their members |
| `app/Modules/Audit` | Append-only audit log (`Auditor` service) |
| `app/Modules/System` | Health checks and system settings |
| `app/Modules/Imports` | Sources, mapping templates, import batches, the `ImportPipeline` (stage → validate → classify → approve → write), portal push API |
| `app/Modules/Applicants` | Applicants, applications, entrance, academics, preferences, round participation, corrections; `ApplicationAccess` scope rules |
| `app/Modules/Documents` | Document types, `DocumentStore` (versions, scanning), ZIP/link imports (optional), signed downloads |
| `app/Modules/Notifications` | Email templates and the email log (addresses kept only as a hash) |
| `app/Modules/Selection` | Stages and criteria, panels, slots and scheduling (`PanelService`: clash and same-venue checks, fix-then-send emails), the schedule template (`ScheduleImport`), automatic slot and faculty assignment (`AutoAllocation`), live marking, admin lock and sending on (`EvaluationService`), round candidates (`RoundCandidates`), panel scope (`PanelAccess`), applicant emails (`Notifier`), day-before reminders |
| `app/Modules/Merit` | Merit formulas (versioned, approved), `MeritEngine` (exact-decimal scoring, ranks, tie-breaks), merit lists (run, approve, publish, out-of-date check, compare), Excel and PDF exports |
| `config/selection.php` | Starting stage names per course level, categories, default email templates |
| `config/documents.php` | Scanner settings, ZIP/link limits, and the suggested document types |
| `app/Support/Scope.php` | Branch/course/cycle scope and the *covers* / *overlaps* rules |
| `config/access.php` | Roles, permissions and default settings; `php artisan access:sync` applies it |
| `routes/api.php` | REST API under `/api/v1` (sign-in routes come from Laravel Fortify under the same prefix) |
| `resources/js` | Vue 3 single-page app (pages, components, Pinia store, router) |
| `tests/Feature`, `tests/Unit` | Pest tests |
| `tests/e2e/walkthrough.mjs` | Playwright walk-through of the whole workflow |

## Imports

```bash
php artisan imports:make-sample storage/app/sample.csv --rows=200 --branches=BR1,BR2   # portal-style CSV, dummy data
php artisan imports:make-sample storage/app/round2.csv --rows=250 --vary=5             # same people, some changed, 50 new
php artisan imports:benchmark --applications=500000 --import-rows=100000               # scale check (never in production)
```

Upload the sample on **Imports → New import**, match the columns (save them as a template), check the rows, and have a different user approve. Import jobs run on the `imports` queue (`php artisan queue:work --queue=imports,default`, or Horizon). The portal can push records to `POST /api/v1/integration/import-batches` with a source token created on **Sources** (Super Admin).

## Documents and virus scanning

`DOCUMENT_SCANNER=clamav` (default; Docker Compose runs ClamAV) or `eicar` for local development and tests, which only flags the standard EICAR test file. Files are viewable only after a clean scan; `php artisan documents:scan-pending` (scheduled every 5 minutes) retries scans after the scanner was down. Document links must be on the allowed host list (**Settings → Document URL hosts**, IT Administrator).

To try it: **Imports → Document types**, *Load the suggested types*, then **Imports → Import documents** with a ZIP (see `tests/e2e/make-docs-zip.php`).

## Interviews

1. **Interviews → Stages and criteria**: *Start from the usual stages*, add criteria and maximum marks, the stage-result formula and the slot order.
2. **Panels, slots and schedule**: *Download Excel template*, fill one row per slot (see `docs/SPEC.md`), *Upload and check*, *Save the schedule*; or *New panel* by hand.
3. *Assign faculty automatically* and *Allocate candidates automatically*: both preview first. For PI, candidates whose GD was in person stay at that venue.
4. Move or swap candidates by hand if needed, then *Send schedule emails*: booking itself never emails.
5. Panel members mark on **My interviews**: a GD slot shows the whole group; a PI slot shows one candidate with ± steppers. Marks autosave.
6. The admin uses *Lock all complete results* (stage, optionally a day) or *Lock* on a panel's Results tab.
7. **Results** on a GD panel sends locked candidates on to a PI panel.

`php artisan interviews:send-reminders` (scheduled daily, 09:00 IST) emails the applicants interviewed the next day.

## Merit

**Merit → Merit formula**:
1. Pick a course and *Start a formula*.
2. Add components: what each reads, how it becomes points, the weight, and an optional cap and minimums.
3. Add the tie-breaks and the source, then *Save draft*.
4. Try it on one application number, then *Approve*.

**Merit lists**:
1. Pick the course and round and *Run the formula*.
2. Check the Ranked, Left out and Shared ranks tabs; tap a name for the breakdown.
3. Another person approves and publishes it.

Every applicant imported into the round with locked results in the stages used is ranked.

## Faculty import and installer

- **Faculty import:** People → Users → Import faculty (CSV/XLSX: Name, Email, Mobile, Designation, Department, Campus, Role).
- **Installer:** on a fresh copy without `APP_KEY`, open `/install`. With `php artisan serve`, the server restarts when `.env` changes, so the request after "Save settings" may need a reload; Nginx/PHP-FPM is not affected. Set `INSTALLER_ENFORCE=false` to work without the redirect.

## How access works

- A user holds roles through **assignments**, each with an optional branch, course and cycle (empty = all).
- **Writes** need a grant whose scope *covers* the record; **reads** need one that *overlaps* it.
- Records outside what a user can see return **404**; visible records they may not change return **403**.
- Nobody can give a role or scope wider than their own, or change their own roles.
- Every change writes an audit row in the same database transaction.

## Rules for database changes

Every migration must be safe while older app servers are still running (no downtime): add first, use in the next release, remove in a later one. `tools/check-migrations.php` rejects drops, renames and type changes in `up()` unless the migration says `live-safe: <reason>`.

## Production notes

- Run at least two app servers behind a load balancer that checks `GET /up` (503 when the database, Redis or storage fails).
- `SESSION_DRIVER`, `CACHE_STORE` and `QUEUE_CONNECTION` must be `redis`; `PRIVATE_DISK_DRIVER=s3`.
- Set `TRUSTED_PROXIES` to the load balancer's addresses.
- Grant the app's database user INSERT and SELECT only on `audit_logs` (no UPDATE or DELETE) so the audit log stays append-only at database level too.
- After each deploy: `php artisan migrate --force && php artisan access:sync`.
