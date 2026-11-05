# Phase 1 specification: Foundation

**Status: approved 2026-11-04** (all points in section 10 accepted). This is the build contract for Phase 1; any change to the tables below needs the product owner's approval (see `CLAUDE.md`).

Phase 1 delivers the skeleton every later phase depends on: the project, sign-in, roles and scoped permissions, the organisation (institution, locations, branches, courses), admission cycles and rounds, teams, the audit log, and a setup that runs on more than one server from day one.

Out of scope for Phase 1: imports, applicants, documents, scrutiny, GD/PI, merit, reservation, offers, accounts, email notices other than password reset, and reports other than the audit log.

---

## 1. Technical setup

| Part | Choice |
| --- | --- |
| Backend | Latest stable Laravel, PHP 8.3 or newer, modular monolith (`app/Modules/<Module>`): Organisation, Cycles, Access, Audit, System |
| API | REST under `/api/v1`, JSON, Laravel Form Requests for validation, API Resources for output, policies on every endpoint |
| Frontend | Vue.js 3 single-page app (Vite, TypeScript, Vue Router, Pinia), served by Laravel from the same domain |
| Sign-in | Laravel Fortify (password, reset by email, two-factor with an authenticator app); Laravel Sanctum cookie sessions for the SPA |
| Database | MySQL 8 InnoDB, utf8mb4, foreign keys everywhere, time zone `Asia/Kolkata` for display, UTC in the database |
| Queue, cache, sessions, locks | Redis (Laravel Horizon to watch queues) |
| Files | S3-compatible private storage (any provider, or MinIO on own servers) |
| Email | SMTP (provider chosen later); Mailpit locally |
| Tests | Pest (backend), Vitest (frontend), Playwright for a few end-to-end checks |
| Code style | Laravel Pint, ESLint, Prettier |
| CI | GitHub Actions: tests, style, frontend build on every push and pull request |
| Local development | Docker Compose: PHP, MySQL 8, Redis, MinIO, Mailpit |

**IDs.** Every table has a `bigint` primary key for joins. Records that appear in URLs or the API also have a `uid` (ULID) so internal numbers are never exposed or guessable.

**Money and marks** are `DECIMAL` (none in Phase 1; the rule applies from Phase 4).

## 2. Built for more than one server (no downtime)

From the first commit the app keeps no state on the web server, so two or more servers can run behind a load balancer:

- Sessions, cache, queue and locks in Redis; files in object storage, never on local disk.
- Scheduled tasks use `onOneServer()` and `withoutOverlapping()` so only one server runs each task.
- `/up` health check tests the database, Redis and storage; the load balancer uses it to remove a failing server.
- Trusted proxy and HTTPS settings for running behind a load balancer.
- Optional read-replica connection in `config/database.php` (off until hosting is chosen).
- **Migration rule:** every migration must be safe while the old code is still running (add columns/tables first, start using them in the next release, remove old ones in a later release). A CI check rejects migrations that drop or rename columns in the same release that stops using them.
- Deployment scripts and the failover setup come with the hosting decision (Phase 9), but nothing in Phase 1 may block them.

## 3. Roles

The 11 roles from the plan, plus one new role for decision D18 (IT team):

| Key | Role | Phase 1 abilities |
| --- | --- | --- |
| `super_admin` | Super Admin | Everything, including roles, permissions and settings |
| `it_admin` | **IT Administrator** (new) | System health, queues, backups status, settings marked technical, user unlock/reset. **No applicant data** in later phases |
| `central_admin` | Central Admission Administrator | Organisation, cycles, rounds, teams, users (except Super Admin/IT), audit log |
| `branch_admin` | Branch Administrator | View organisation; manage teams and users within own branches; view own branches' audit log |
| `committee_chair` | Committee Chairperson | View own committees (approvals arrive in later phases) |
| `committee_member` | Committee Member | View own committees |
| `admission_officer` | Admission Cell Officer | View cycles and rounds in scope |
| `admission_staff` | Admission Cell Staff | View cycles and rounds in scope |
| `accounts_officer` | Accounts Officer | View cycles in scope |
| `accounts_staff` | Accounts Staff | View cycles in scope |
| `faculty` | Faculty / Panel Member | Sign in and see own profile (assignments arrive in Phase 4) |
| `reporting_officer` | Reporting Officer | View organisation and cycles in scope |

Roles and their permissions ship as seed data. The Super Admin can change which permissions a role has; role keys used by the code cannot be deleted.

### Scope

A user gets a role **with a scope**: branch, course and cycle, each optional (empty means "all"). Example: *Admission Cell Officer for Delhi branch, MBA IB, cycle 2027-28*. One user may hold several assignments. Every API request checks the permission **and** the scope on the server.

### Conflicting duties

A `role_conflicts` list stops one person holding two roles that check each other's work on the same scope. Seeded pair: Accounts Staff + Accounts Officer on overlapping scope. The Super Admin can add more pairs. Assigning a conflicting pair is refused with a clear message.

### Phase 1 permissions

`organisation.view`, `organisation.manage`, `cycles.view`, `cycles.manage`, `rounds.close`, `rounds.reopen`, `users.view`, `users.manage`, `roles.manage`, `teams.view`, `teams.manage`, `audit.view`, `settings.manage`, `system.health`. Later phases add their own.

## 4. Tables (proposed data model)

Columns `created_at`, `updated_at` are on every table and not repeated. "Status" columns use short string values checked by the app.

### Organisation

**institutions**: `id`, `uid`, `code` (unique), `name`, `short_name`, `address`, `email`, `phone`, `website`.
One row (IIFT). Kept as a table so reports and settings have an owner.

**locations** (physical campuses, the plan's `branch_locations`): `id`, `uid`, `institution_id`, `code` (unique), `name`, `address`, `city`, `state`, `pincode`, `status` (active/inactive).

**branches**: `id`, `uid`, `institution_id`, `code` (unique), `name`, `location_id` (nullable), `email`, `phone`, `status`.
Branch and location are separate (plan §3): a branch normally sits at one location, but the data does not force it.

**courses**: `id`, `uid`, `institution_id`, `code` (unique, e.g. `MBA-IB`), `name`, `short_name`, `level` (UG/PG/Integrated), `duration_months`, `entrance_exam` (CAT/IPMAT/other, text), `status`.

**branch_courses**: `id`, `branch_id`, `course_id`, `status`. Unique (`branch_id`, `course_id`). Says which branch can offer which course.

### Cycles and rounds

**academic_years**: `id`, `code` (unique, e.g. `2027-28`), `starts_on`, `ends_on`.

**admission_cycles**: `id`, `uid`, `academic_year_id`, `code` (unique), `name`, `status` (draft → open → closed).

**course_offerings** (a branch-course in a cycle; merges the plan's `course_offerings` and `admission_cycle_offerings`): `id`, `uid`, `admission_cycle_id`, `branch_course_id`, `sanctioned_intake` (int), `status` (draft/active/closed). Unique (`admission_cycle_id`, `branch_course_id`).

**admission_rounds**: `id`, `uid`, `admission_cycle_id`, `number` (1, 2, 3…), `type` (initial/fresh), `name`, `status` (draft → active → closed), `closed_at`, `closed_by`, `close_reason`. Unique (`admission_cycle_id`, `number`).

**admission_round_offerings**: `id`, `admission_round_id`, `course_offering_id`, `starts_on`, `ends_on`, `status`. Unique (`admission_round_id`, `course_offering_id`).
Dates live here because deadlines are set **branch-wise** by the institute (D19).

### Access

**users**: `id`, `uid`, `name`, `email` (unique), `phone`, `password`, `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `status` (active/suspended), `must_change_password`, `last_login_at`, `failed_logins`, `locked_until`.

**roles**: `id`, `key` (unique), `name`, `description`, `is_system`, `requires_two_factor`.

**permissions**: `id`, `key` (unique), `module`, `description`.

**role_permissions**: `role_id`, `permission_id` (primary key on both).

**user_role_assignments**: `id`, `uid`, `user_id`, `role_id`, `branch_id` (nullable), `course_id` (nullable), `admission_cycle_id` (nullable), `valid_from`, `valid_to` (nullable), `assigned_by`, `revoked_at`, `revoked_by`, `revoke_reason`.
Replaces the plan's separate `user_branch_assignments` and `user_course_assignments` with one scoped row per role. Assignments are revoked, never deleted.

**role_conflicts**: `role_a_id`, `role_b_id`.

### Teams

One table for all kinds of team (replaces the plan's separate `committees`, `admission_cells` and `accounts_teams`; GD/PI panels stay separate and come in Phase 4):

**teams**: `id`, `uid`, `type` (admission_committee/admission_cell/accounts_team), `name`, `branch_id` (nullable), `course_offering_id` (nullable), `admission_cycle_id` (nullable), `valid_from`, `valid_to`, `approval_powers` (text, as constituted), `status`.

**team_members**: `id`, `team_id`, `user_id`, `member_role` (chair/member/officer/staff), `valid_from`, `valid_to`, `added_by`, `removed_at`, `removed_by`.

### Audit and system

**audit_logs** (append-only): `id`, `occurred_at`, `actor_id` (nullable for system jobs), `actor_type` (user/system/import), `action` (e.g. `branch.updated`, `round.closed`, `auth.login_failed`), `subject_type`, `subject_id`, `old_values` (JSON), `new_values` (JSON), `reason`, `ip`, `user_agent`, `request_id`.
- Written in the same database transaction as the change it records.
- The app's database user has **no UPDATE or DELETE** right on this table (documented in the deployment guide).
- Passwords, two-factor secrets and tokens are never written to it.

**system_settings**: `id`, `key` (unique), `value` (JSON), `is_technical` (IT Administrator may edit), `updated_by`.

### Deletion rule

Organisation, cycle, round, user, role and team rows are **never deleted** once anything refers to them; they are made inactive or closed. Foreign keys use `RESTRICT`.

## 5. Rules

1. **Codes** (branch, course, location, cycle) can be changed only while nothing refers to them; afterwards they are fixed.
2. A **cycle** opens only when it has at least one active offering. A closed cycle is read-only.
3. A cycle has exactly one **initial round** (number 1); later rounds are **fresh rounds**, created separately and each needing `cycles.manage`.
4. **Closing a round** needs `rounds.close`, a reason, and a confirmation; it records who and when. A closed round is read-only.
5. **Reopening a round** needs `rounds.reopen` (Super Admin and Central Admin only), a reason, and is audited with old/new status.
6. A round's offering dates must end by the end of the cycle's academic year; they may start before it, because admissions run ahead of the year (D27). Overlapping rounds for the same offering are refused.
7. **Sign-in:** 5 failed attempts lock the account for 15 minutes; every success and failure is audited. Idle sessions end after 30 minutes. Password at least 12 characters and checked against known-breached lists.
8. **Two-factor** is required for roles marked `requires_two_factor`: Super Admin, IT Administrator, Central Admin, Branch Admin, Accounts Officer. Such a user cannot do anything except set up two-factor until it is confirmed.
9. **New users** get an email link to set their own password; admins never see or type a user's password.
10. A user can never grant a role or scope wider than their own (a Branch Admin cannot create a Central Admin or assign another branch).
11. Every create, update, status change, assignment and revocation writes one audit row.

## 6. Screens (responsive, English, usable from 360 px wide)

| Area | Screens |
| --- | --- |
| Sign-in | Sign in, two-factor code, forgot/reset password, set password from invitation, set up two-factor |
| Home | Dashboard placeholder: my roles and scopes, open cycles, active rounds |
| Organisation | Institution profile; Locations; Branches; Courses; Branch–course grid (tick which branch offers which course) |
| Cycles | Academic years; Cycles (list, create, open, close); Cycle detail with offerings and intake; Rounds (create fresh round, set branch-wise dates, close, reopen) |
| People | Users (list with search/filter, invite, suspend, unlock); User detail with role assignments (add with scope, revoke with reason); Roles and permissions (Super Admin); Role conflicts |
| Teams | Teams list by type; team detail with members and validity |
| Audit | Audit log with filters (date, user, action, branch); detail shows old and new values side by side |
| System | Health (database, Redis, storage, queue backlog, last scheduler run); Settings |
| My account | Profile, change password, two-factor, recovery codes |

All lists are paged on the server (25 per page by default) and searchable. Menus show only what the user may use, but the server checks again on every request.

## 7. API (Phase 1 endpoints, all under `/api/v1`)

- Auth (Fortify/Sanctum): `POST /login`, `POST /logout`, `POST /two-factor-challenge`, `POST /forgot-password`, `POST /reset-password`, `GET /me`
- Organisation: `GET/POST /institution`, `GET/POST/PATCH /locations`, `/branches`, `/courses`, `GET/PUT /branch-courses`
- Cycles: `GET/POST /academic-years`, `GET/POST/PATCH /admission-cycles`, `POST /admission-cycles/{uid}/open`, `/close`, `GET/POST/PATCH /admission-cycles/{uid}/offerings`
- Rounds: `GET/POST /admission-cycles/{uid}/rounds`, `PATCH /rounds/{uid}`, `PUT /rounds/{uid}/offerings`, `POST /rounds/{uid}/close`, `POST /rounds/{uid}/reopen`
- People: `GET/POST/PATCH /users`, `POST /users/{uid}/suspend`, `/unlock`, `/resend-invite`, `GET/POST /users/{uid}/assignments`, `POST /assignments/{uid}/revoke`, `GET/PUT /roles/{key}/permissions`, `GET/PUT /role-conflicts`
- Teams: `GET/POST/PATCH /teams`, `GET/POST /teams/{uid}/members`, `POST /team-members/{id}/remove`
- Audit: `GET /audit-logs`, `GET /audit-logs/{id}`
- System: `GET /system/health`, `GET/PATCH /system/settings`

Rate limits: sign-in 5 per minute per account and IP; API 600 per minute per user (raised from 120 in Phase 2: a busy screen makes several requests).

## 8. Tests (Pest, written with the code)

- Every endpoint: allowed role succeeds; other roles get 403; out-of-scope records get 404 (not 403, so their existence is not revealed).
- Scope: a Branch Admin of branch A cannot see, edit or assign anything in branch B.
- No privilege escalation: rule 10 for every role.
- Role conflicts refused.
- Round life cycle: create, close with reason, edit refused when closed, reopen only by allowed roles, all audited.
- Cycle open/close rules; branch-wise date checks.
- Codes fixed once referenced; deletion refused with `RESTRICT`.
- Audit row written for every change, inside the same transaction (a failed change leaves no audit row and vice versa); no secrets in audit rows.
- Sign-in: lockout, two-factor enforcement, invitation link expiry.
- Health check fails when Redis or the database is down.
- Migration check: the CI rule for live-safe migrations.
- Frontend: key screens render at 360 px; Playwright: invite user → set password → set up two-factor → sign in → create branch.

## 9. Done when

- A fresh clone starts locally with one command (`docker compose up`) and seeds a demo Super Admin.
- CI is green: backend and frontend tests, style, build.
- On the local stack: create IIFT, its locations and 4 branches, MBA IB, MBA BA and IPM, an academic year, a cycle with offerings and intake, Round 1 with branch-wise dates; invite users with scoped roles; close and reopen a round; see every step in the audit log.
- Two app containers behind a local load balancer serve the same session correctly, and stopping one does not sign anyone out.
- `docs/DEVELOPMENT.md` explains local setup; `CLAUDE.md` is updated to "Phase 1 built".

## 10. Points confirmed (approved 2026-11-04)

1. **Merged tables** (§4): one `course_offerings` table instead of two, one `user_role_assignments` table with scope instead of separate branch/course assignment tables, and one `teams` table for committees, admission cells and accounts teams. They keep the plan's meaning with fewer tables. **Approved.**
2. **New role** `it_admin` for the IT team (D18), with no access to applicant data. **Approved.**
3. **Two-factor** required for Super Admin, IT Administrator, Central Admin, Branch Admin and Accounts Officer (§5 rule 8). **Approved as listed.**
4. **Vue single-page app** using the REST API (as the plan's API catalogue implies), rather than Inertia. **Approved.**
5. **Branch and campus names:** the admin enters them in the app, so they are not needed now. Demo data uses placeholder branch names until the institute supplies them.

---

## 11. As built (2026-11-04): additions and differences

Small additions made while building, all inside the approved design. None changes the meaning of the tables above.

| Item | What was added or differs | Why |
| --- | --- | --- |
| Permissions | `users.support` (unlock, resend invitation) and `settings.technical` (edit settings marked technical) | Gives the IT Administrator exactly the support tasks in §3 without `users.manage` or `settings.manage` |
| `audit_logs` | Three scope columns: `branch_id`, `course_id`, `admission_cycle_id` | §6 filters the audit log by branch, and Branch Admins may see only their branches' rows |
| `user_invitation_tokens` | New token table (like `password_reset_tokens`) | Invitation links last 72 hours, password-reset links 60 minutes |
| `system_settings` | `description` column | Shown on the Settings screen |
| Round activation | `POST /rounds/{uid}/activate` (draft → active) | §4 lists the status draft → active → closed; this is the step from draft to active |
| User reactivation | `POST /users/{uid}/reactivate` (with a reason) | Undo of suspend |
| My account | `PATCH /me` (name, phone) | §6 "My account → Profile" |
| Round offerings | Removing an offering from a round is allowed only while the round is a draft (the row is deleted and audited) | Nothing refers to round offerings until Phase 2 imports |
| End-to-end test | `tests/e2e/walkthrough.mjs` (Playwright) runs the whole §9 walk-through, including invitation and two-factor | Wider than the single flow in §8 |
| Two app servers | Checked with two app processes sharing sessions in Redis; stopping one kept the user signed in. The Docker Compose stack (`docker compose up`) is written for this but could not be started in the build environment | No Docker daemon there; CI does not run Compose |
