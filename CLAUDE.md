# CLAUDE.md: Trinix Admission

Centralised Admission Management System. Read `docs/PLAN.md` and `docs/DECISIONS.md` before changing anything. `DECISIONS.md` overrides `PLAN.md` where they differ.

## Current stage

- **Phase 1 (foundation): built and tested** (`docs/PHASE1_SPEC.md`).
- **Phase 2 (import and applicant master): built and tested** (`docs/PHASE2_SPEC.md`). Its first real test needs a portal export from the institute (D13).
- **Phase 3 (documents and scrutiny): built and tested** (`docs/PHASE3_SPEC.md`). Real use needs IIFT's document list and eligibility rules checked by the admin.
- **Phase 4 (shortlisting and GD/PI): built and tested** (`docs/PHASE4_SPEC.md`). Real use needs IIFT's call cut-offs, stages and criteria entered by the admin.
- **Phase 5 (merit): built and tested** (`docs/PHASE5_SPEC.md`, D25). IIFT's formula is entered later by the admin; never put weights, cut-offs or tie-break orders in code.
- **Stage A (first usable release): built and tested** (`docs/STAGE_A_SPEC.md`, D26): faculty bulk import, GD/PI centre preferences, automatic panel allocation with override, the admin's stage-result formula, copying stages to a new cycle, installation wizard, Maintenance.
- **Phase 6 (reservation, seats, offers): built and tested** (`docs/PHASE6_SPEC.md` §11–14, D28): categories as data (incl. NRI, Foreign Nationals, Kashmiri Migrants as supernumerary), yearly rule sets, seat matrices, allocation by merit and campus preference (open seats first, conversions until stable), offers with recorded payments verified by Accounts, paid waitlist holds with per-category waitlist ratios, moving up (both seats held until the upgrade is accepted), staff-confirmed lapses, automatic vacancy runs approved by a second person, reminders. Also D28: imported candidates count as called (round setting), PI panel warning, slot order rules. IPM is discussed later.
- **Phase 7 (admission, fees, payments, refunds): built and tested** (`docs/PHASE7_SPEC.md` §11, D29): fee structures with heads, instalments and concessions (versioned, approved), admission confirmed by the Admission Officer after the acceptance payment and documents are verified, admission numbers from the admin's pattern, dues and ledger, fee payments verified/rejected/returned with PDF receipts, reversals and adjustments approved by another person, conditional admissions, withdrawals and cancellations confirmed by another person, refunds by the approved policy (UGC slabs as a suggested draft) against the campus's last date of admission, hold refunds, fee reminders, bank statement matching, dashboard and reports. Scholarships are not built.
- **Releases:** a version tag builds the upload-ready ZIP and, with the `test-host` environment secrets, uploads it over FTPS/SFTP (`.github/workflows/release.yml`, `docs/DEPLOYMENT.md`). The test host is shared hosting: scheduled tasks run in-process from the cron, which also works the queue when there is no Redis (`QUEUE_FROM_SCHEDULER`).
- Open questions are listed in `docs/DECISIONS.md`; do not guess answers to them.
- Institution: IIFT. First courses: MBA (IB), MBA (BA), IPM. A student in two MBA courses has two application numbers and two separate records.
- Applications belong to course + cycle; the campus is decided at allocation (D21). Branch staff see applications of courses their branch offers (`ApplicationAccess`).

## Commands

```bash
vendor/bin/pest && vendor/bin/pint --test && php tools/check-migrations.php
npm run lint && npx vue-tsc --noEmit && npm test && npx vite build
php artisan access:sync          # roles/permissions/settings from config/access.php
php artisan imports:make-sample FILE --rows=100   # portal-style CSV with dummy data
node tests/e2e/walkthrough.mjs   # browser walk-through of Phases 1 to 7 (app must be running, DOCUMENT_SCANNER=eicar)
```

See `docs/DEVELOPMENT.md` for layout, access rules and the e2e walk-through.

## Hard rules

- **Ask before changing the data model or the merit formula.** Reservation and merit rules come only from the institution; never invent them.
- **Reservation rules are data, not code:** the default set comes from IIFT's official admission policy (Central Government reservation), entered and edited by the admin; no percentages in code. Every change is a new version, approved and audited; a round keeps the version it was allocated with.
- **One installation, one database.** Branches are records, not separate installs.
- **Applicants never log in.** Staff record applicant responses (by phone or email, including offer accept/decline); every record shows who entered it.
- **Email only.** No SMS.
- **No payment gateway.** Payments and refunds are recorded, reviewed and reconciled; originals are never edited or deleted (use adjustments, reversals, refunds).
- **Imports never write straight to live tables:** staging → validation → duplicate check → preview → approval. Verified data is never silently overwritten. A missing document never drops a valid application.
- **Duplicate key:** source + portal application number + course + cycle. Store the IPMAT/CAT application number too. No cross-course person matching.
- **Imports are round-wise:** each batch belongs to a round; only shortlisted applicants are imported.
- **Never publish** an unapproved merit list or allocation. Closed rounds are read-only unless an audited reopening.
- **Server-side permissions** scoped by branch, course, cycle, round and panel; menu hiding is not security.
- **DECIMAL** for marks and money. Restrictive deletes on approved admission, merit, payment and audit records.
- **No data loss, no downtime:** point-in-time recovery, tested restores, no single point of failure (2+ app servers, database failover), rolling deploys, migrations safe to run live.
- **Hosting-neutral:** nothing may depend on one cloud provider.
- **Privacy:** DPDP Act 2023; no Aadhaar without a documented legal basis; documents private, served through authorised or signed downloads; no secrets or personal data in logs.
- **Imports:** `ImportPipeline` is the only way into the applicant tables. Empty cells never erase stored data. The uploader never approves their own batch. Source tokens never reach staff endpoints.
- **Documents:** only through `DocumentStore` (content check, new version, scan); never served before a clean scan; only via 5-minute signed links, every opening logged in `file_access_logs`. Suggested eligibility rules are drafts until approved with a source reference; "not eligible" needs a second person.
- **Every change is audited** through `Auditor`, inside the same transaction. New endpoints check permission and scope with `AccessControl` (404 outside scope, 403 when visible but not allowed) and get Pest tests.
- **Selection:** call cut-offs come only from the admin (nothing suggested), approved with a source; a call list is published by someone other than who ran it. Every current panel member without a declared conflict marks every candidate; the stage result is the average (marks in hundredths, `EvaluationService::cents`). Members see others' marks only after submitting their own. Only the panel chair or `evaluations.reopen` reopens marks, with a reason. Times are entered in IST and stored in UTC.
- **Allocation:** only through `AllocationEngine` (a pure function of its stored inputs) via `AllocationService`; runs are approved and published by someone other than the runner; vacancy runs are prepared automatically but never published without approval. Offers change only through `OfferService`; payments are recorded, verified by another person, never edited or deleted; nothing lapses without staff confirming it. Category codes, percentages, amounts and ratios are admin data only.
- **Accounts:** dues are written once at admission; changes only through approved `fee_adjustments`. Only verified, unreversed payments count (`Ledger`). Every payment, reversal, adjustment, withdrawal, refund decision and refund transaction is checked by someone other than who entered it; nothing is edited or deleted. Refunds are capped at what is refundable and never reinstate an offer. Fee amounts, concessions, refund slabs and the number pattern are admin data only.
- **Merit:** only through `MeritEngine` (exact decimals, half up); a list stores each candidate's inputs and the formula version and must re-score identically; a list is approved and published by someone other than who ran it; published lists are read-only and flagged out of date when their data changes.
- **Installer:** `/install` runs without sessions or cookies and never uses helpers that start a session (plain responses only); it is open only until the first Super Admin exists. Web code cannot call `access:sync` (console only): use `AccessCatalog::sync()`.
- **UI:** English only, mobile first (360 px), 44 px touch targets, light and dark themes; GD/PI evaluation must work on phones and tablets (online, autosave).
