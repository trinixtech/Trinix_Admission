# CLAUDE.md: Trinix Admission

Interview-based admission (Main product, split from Trinix Admiso 0.8.0). Read `docs/SPEC.md` and `docs/DECISIONS.md` before changing anything.

## Current stage

- **1.0.0: built and tested.**
  - Setup, people, imports (students; files by ZIP or API, optional; faculty).
  - Interview schedule from the Excel template, automatic slot and faculty assignment with override.
  - Fix, then send schedule emails; staff reschedule.
  - GD/PI attendance and marks on phones (PI on a different panel, at the same venue after an in-person GD).
  - Admin lock; merit lists.
  - Installation wizard and Maintenance page.
  - Dashboard: admission workflow per course and round (`WorkflowController`, live counts and next step); today's interviews for panel members.
- **1.1.0:** System → Backups (`BackupService`: PHP SQL dump, AES-256 ZIP with the admin's backup password, daily schedule at a set IST time with email, weekly full backup with documents, restore with a safety backup); printable login sheet for panel and committee members (`LoginSheetController`, temporary password, change forced at first sign-in); cleaner marks entry.
- **1.2.0:** Stage map (`StageMapController`: stages in order with components, counts and venues; add, rename, reorder, delete; components locked once marks exist, code fixed once panels or a merit formula use it, delete only when unused); Reports (`ReportController`: interview progress, marks and results, faculty workload, imports and applicants; Excel); exports (applications, audit log, panel attendance PDF); audit log filters by person and record type.
- **1.3.0:** Import templates (`ImportLayouts`, `ImportLayoutController`; Imports → Import templates): the admin chooses, orders and renames the student and faculty template columns and adds own student columns (text, number, date, yes/no, choice; stored in system_settings `import_layout.*`, values kept in `applications.extra` under the heading, information only, never merit); Excel template with an Instructions sheet (CSV + printable page in limited mode); uploads are matched by heading. Backups: streamed encryption in 1 MB pieces, `.sql.enc` without zlib, failures shown on Backups and Health, daily retries (3 a day).
- The optional modules live in Trinix Admiso (repository `trinixtech/Trinix-Admiso`); do not add them here unless the user asks.
- Releases: a version (workflow_dispatch input, e.g. `1.0.0`) builds the upload-ready ZIP on the GitHub Release. The user uploads it by hand (cPanel File Manager, Extract) with the subdomain's document root at `public/`; FTP upload is optional and not used (`.github/workflows/release.yml`, `docs/DEPLOYMENT.md`).

## Commands

```bash
vendor/bin/pest && vendor/bin/pint --test && php tools/check-migrations.php
npm run lint && npx vue-tsc --noEmit && npm test && npx vite build
php artisan access:sync          # roles/permissions/settings from config/access.php
php artisan imports:make-sample FILE --rows=100   # portal-style CSV with dummy data
node tests/e2e/walkthrough.mjs   # browser walk-through (app must be running, DOCUMENT_SCANNER=eicar)
```

## Hard rules

- **Ask before changing the data model or the merit formula.** Merit weights, cut-offs and tie-breaks come only from the institution; never put them in code.
- **One installation, one database.** Campuses are records.
- **Applicants never log in. Email only, no SMS.**
- **Imports never write straight to live tables:** staging → validation → duplicate check → preview → approval by someone other than the uploader. Empty cells never erase data. `ImportPipeline` is the only way into the applicant tables. Duplicate key: source + portal application number + course + cycle.
- **Every applicant imported into a round is a candidate of that round** (`RoundCandidates`); there are no call lists.
- **Documents** only through `DocumentStore` (content check, versions, virus scan); served only after a clean scan through 5-minute signed links, every opening logged.
- **Scheduling:** booking never emails; `PanelService::notifyPending` sends the schedule once; a change is emailed at once only if the applicant was already told. After an in-person stage, later in-person stages are at the same location (`PanelService::earlierVenue`); the check runs on every booking. The schedule template is all-or-nothing after a preview.
- **Marking:** every current panel member without a declared conflict marks every candidate per criterion (marks in hundredths); members see others' marks only after submitting. Only `evaluations.lock` (the admin) locks results; reopening needs `evaluations.reopen` and a reason. Times are entered in IST and stored in UTC.
- **Merit** only through `MeritEngine` (exact decimals, half up); lists store inputs and the formula version, re-score identically, are approved and published by someone other than the runner, are read-only once published, and are flagged out of date when data changes.
- **Every change is audited** through `Auditor` in the same transaction. New endpoints check permission and scope with `AccessControl` (404 outside scope, 403 when visible but not allowed) and get Pest tests.
- **DECIMAL** for marks. Restrictive deletes on merit and audit records. Migrations must be live-safe (`tools/check-migrations.php`).
- **No SSH on the host:** nothing may depend on running artisan, composer or npm on the server; migrations run from System → Maintenance.
- **Installer:** `/install` runs without sessions or cookies, is open only until the first Super Admin exists, and uses `AccessCatalog::sync()`.
- **Privacy:** DPDP Act 2023; no secrets or personal data in logs.
- **Backups:** only through `BackupService`; always encrypted with the backup password (stored encrypted, never shown, logged or emailed); only this app's own database is read or replaced; restore needs `backups.restore`, RESTORE + the admin's password, and makes a safety backup first.
- **Login sheets:** temporary passwords are shown once and never stored or audited in plain text; roles that need two-factor never get one.
- **UI:** English only, mobile first (360 px), 44 px touch targets, light and dark themes.
