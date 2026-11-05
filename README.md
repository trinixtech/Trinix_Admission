# Trinix Admission

Centralised Admission Management System for a multi-branch institution: one application and one database for all branches, courses, cycles and rounds.

Applicants apply on the institution's existing external portal. Trinix Admission imports shortlisted applicants and their documents round by round, and manages everything after that: scrutiny, eligibility, GD/PI scheduling and live evaluation, merit, government reservation and seat allocation, offers and waitlists, applicant contact, manual payment verification and refunds, reports and audit. Applicants never log in; staff record their responses, and all notices go by email.

First courses: **MBA (International Business), MBA (Business Analytics), IPM**.

**Status:** Phases 1 (foundation), 2 (import and applicant master), 3 (documents and scrutiny), 4 (shortlisting and GD/PI) and 5 (merit) built and tested. See [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) to run it.

## Documents

| File | What it is |
| --- | --- |
| [`docs/PLAN.md`](docs/PLAN.md) | Product and architecture plan |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | Decisions taken after the plan, and open questions |
| [`docs/PHASE1_SPEC.md`](docs/PHASE1_SPEC.md) | Phase 1 (foundation) specification, approved and built |
| [`docs/PHASE3_SPEC.md`](docs/PHASE3_SPEC.md) | Phase 3 (documents and scrutiny) specification, approved and built |
| [`docs/PHASE4_SPEC.md`](docs/PHASE4_SPEC.md) | Phase 4 (shortlisting and GD/PI) specification, approved and built |
| [`docs/PHASE5_SPEC.md`](docs/PHASE5_SPEC.md) | Phase 5 (merit) specification, approved and built |
| [`docs/PHASE6_SPEC.md`](docs/PHASE6_SPEC.md) | Phase 6 (reservation, allocation, offers) specification, on hold |
| [`docs/STAGE_A_SPEC.md`](docs/STAGE_A_SPEC.md) | Stage A: first usable release (faculty import, automatic panel allocation, marks formula, installation wizard), approved and built |
| [`docs/research/`](docs/research/) | Research notes (reservation law, IIFT prospectus) |
| [`docs/mockups/`](docs/mockups/) | Approved mockups of the GD and PI marking screens, sending on to PI, and stage settings |
| [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) | Running, testing and project layout |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Releasing to a test host (shared hosting, no SSH): package, upload, install, cron, updates |
| [`docs/PHASE2_SPEC.md`](docs/PHASE2_SPEC.md) | Phase 2 (import and applicant master) specification, approved and built |
| [`docs/original/`](docs/original/) | The original plan (PDF) |

## Planned stack

Laravel (modular monolith, REST API `/api/v1`), Vue.js 3, MySQL 8 (InnoDB), Redis (queues and cache), private object storage, Nginx + PHP-FPM. Hosting (own server, VPS or cloud) is not decided yet.

## Installing on a server

Upload the release (with `vendor/` and `public/build/`), point the web server at `public/`, and open **`https://your-site/install`**. The wizard checks the server, sets up the database, email, storage and services, writes `.env`, creates the tables and the first Super Admin. Later database updates: **System → Maintenance**. Shared hosting without SSH: see [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Quick start (development)

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate && php artisan db:seed --class=DemoSeeder
npm run build && php artisan serve
```

Or `docker compose up --build` for the full stack with two app servers (http://localhost:8080).
