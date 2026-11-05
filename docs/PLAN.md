# Trinix Admission: Centralised Admission Management System (plan)

Source: `docs/original/Centralised_Admission_Management_System_Final_Plan.pdf` (Final Product Plan).
Decisions taken after that plan are in `docs/DECISIONS.md` and override this file where they differ.

## Baseline

| Item | Decision |
| --- | --- |
| Deployment model | One centralised application and one central database |
| Organisation | One institution, multiple branches, each offering multiple courses |
| Minimum capacity | 500,000 applicant master records, with headroom |
| Application intake | External portal retained; application data and documents are imported |
| Technology | Laravel, Vue.js 3, MySQL 8, Redis, private object storage |

**Scope principle.** The external portal stays responsible for online application submission and fee collection. This system imports applicant data and documents and manages admission from scrutiny/shortlisting onwards. It does not recreate online applications and does not operate a payment gateway.

## 1. Objectives

- Import applicant records and documents from the external portal.
- Keep one applicant identity with a separate application per course and cycle.
- Manage scrutiny, eligibility, GD/PI, merit, reservation, allocation, offers and confirmation.
- Support multiple branches, courses, committees, admission cells and accounts teams centrally.
- Record externally received payments and refunds without a payment gateway.
- Provide secure, auditable, consolidated reporting.

## 2. End-to-end workflow

| Stage | System responsibility |
| --- | --- |
| External portal | Applicant applies and uploads documents in the existing portal (outside this system) |
| Import management | Staff import data through Excel, CSV, API, ZIP or secure document URLs |
| Validation | Map fields, validate, find duplicates/missing data, preview and approve |
| Scrutiny and eligibility | Verify documents and assess course-specific eligibility |
| Shortlisting | Apply approved shortlisting rules and prepare candidate lists |
| GD/PI | Create panels, schedule candidates, assign faculty, record evaluations |
| Merit | Course-wise composite scores using approved, versioned criteria |
| Allocation | Apply approved seat and reservation rules; allocate branch/course seats |
| Offers and waitlists | Issue offers, record responses, manage vacancies and waitlist movement |
| Admission and accounts | Record confirmation, verify payments manually, manage refund records |
| Reporting and closure | Produce reports and lock completed rounds |

**Explicit exclusions:** online application form, registration and submission; application-fee collection or payment gateway; automatic bank debit, payment initiation or automatic refund execution; applicant login; SMS (see `DECISIONS.md`).

## 3. Organisational model

Centralisation means one installation, one logical database, one identity/access framework and one reporting layer. Branches are records inside the system, not separate installations or databases.

| Level | Configuration and data |
| --- | --- |
| Institution | Profile, global settings, governance, consolidated dashboards |
| Branch | Code, name, location, contacts, status, staff assignments |
| Course | Code/title, eligibility, selection policy, fee structure |
| Branch-course offering | Which course is offered by which branch in a cycle |
| Admission cycle | Academic year/cycle, dates, status, participating offerings |
| Admission round | Initial/fresh round, seat matrix, rule version, merit and approval history |
| Committee/team | Constitution, members, roles, validity, scope, approval powers |

Hierarchy: Institution → Branches → Course offerings → Admission cycles → Admission rounds. A course may be offered by several branches with different policies, committees, fees, panels and seat matrices. Branch and physical campus/location are separate concepts.

## 4. Roles and access control

| Role | Main responsibility |
| --- | --- |
| Super Admin | System setup, users, roles, permissions, integrations, global audit |
| Central Admission Administrator | Cycles, offerings, import governance, central operations |
| Branch Administrator | Authorised branch-level operations |
| Committee Chairperson | Review and approve committee recommendations within delegated authority |
| Committee Member | Selection and committee decisions within assigned scope |
| Admission Cell Officer | Scrutiny, eligibility, scheduling, offers, admission processing |
| Admission Cell Staff | Document processing, applicant contact, assigned tasks |
| Accounts Officer | Review payment entries (approve/reject/return); oversee refunds |
| Accounts Staff | Enter payment particulars, evidence and actual refund transactions |
| Faculty / Panel Member | View assigned candidates and submit evaluations |
| Reporting Officer | View approved reports within assigned scope |

- Permissions are enforced on the server; hiding menus is not security.
- Access is scoped by branch, course, cycle, round and panel assignment.
- Multiple roles per user where authorised, with controls for conflicting duties.
- Every approval records actor, timestamp, decision and remarks.
- Faculty see only assigned candidates and evaluation tasks.

## 5. Functional modules

1. **Master configuration:** institution, branches, locations, courses, branch-course mappings, academic years, cycles, rounds, categories, document types, fee heads, payment modes, settings.
2. **Applicant/application management:** common applicant master; separate application per course/cycle; external application reference; contacts, academics, entrance data, category, preferences, status history.
3. **Import management:** Excel/CSV/ZIP/API import, mapping, staging, validation, preview, duplicate detection, approval, execution, retry, error reports.
4. **Document management:** private storage, classification, versioning, verification, missing-document tracking, authorised downloads, retention.
5. **Scrutiny and eligibility:** configurable checklists, course-specific criteria, verification decisions, deficiency notices, approval history.
6. **Entrance and shortlisting:** import entrance scores, map and validate, thresholds/rules, publish approved shortlists.
7. **GD/PI scheduling:** panels, chairpersons, members, slots, candidate assignments, venues/links, conflict prevention.
8. **Evaluation:** configurable criteria and marks, individual scoring, remarks, submit/lock, controlled reopening, aggregation, outlier flags. Entered live on phones/tablets, online (see `DECISIONS.md`).
9. **Merit calculation:** versioned course formula, normalisation, breakdown, tie-breaks, simulation, approval.
10. **Reservation and allocation:** reservation rule sets (from IIFT's admission policy, Central Government reservation; editable and versioned by the admin), seat matrices, simulation, committee approval, publication.
11. **Offers and waitlists:** generation, offer email, response recorded by staff (phone or email), acceptance, decline, expiry, vacancies, waitlist movement, confirmation.
12. **Fresh admission rounds:** separately authorised, own seat matrix, policy version, merit and allocation history.
13. **Payments and refunds:** manual entry, evidence, review, refund request, approval, transaction recording, reconciliation.
14. **Notifications:** email only: templates, bulk queue, shortlist/schedule/offer notices, reminders, logs, retries.
15. **Applicant contact log** (added, see `DECISIONS.md`): staff record calls and emails, applicant responses (including offer accept/decline), and schedule or reschedule interviews.
16. **Reports and audit:** dashboards, counts, scrutiny, GD/PI, merit, seats, offers, accounts, imports, audit history.

## 6. Import and document integration

| Channel | Expected operation |
| --- | --- |
| Excel / CSV | Upload, select source/cycle/round, map columns, validate, preview, approve |
| REST API | Authorised fetch/push with documented schemas, credentials and idempotency |
| ZIP archive | Upload archive with manifest or stable external application IDs |
| Document URLs | Fetch files from trusted, allowlisted URLs in background jobs |
| Manual correction | Authorised field correction with before/after values, reason and audit |

Pipeline: source → import batch → staging records → mapping → validation → duplicate checks → preview/error report → authorised approval → operational records → reconciliation.

Key controls:
- Never write raw imports directly into live applicant/application tables.
- Keep source files, external identifiers, row numbers and import metadata.
- Duplicate key: source + external application number + course + cycle. No cross-course person matching.
- Column mapping is configured on screen and saved as reusable templates per source (no portal sample yet).
- Applicant identity is separate from applications; one person may apply to several courses/cycles.
- Never silently overwrite internally verified data; changed fields go to a field-level update review.
- Large imports run in chunks on background queues, with retries and partial-success reports.
- Application data and documents are separate, linked jobs; a missing document never discards a valid application.
- Validate real file content, scan for malware, restrict ZIP paths/sizes, prevent unsafe URL fetching (SSRF).

## 7. Selection, GD/PI, merit and allocation

Selection policy is configurable per course and cycle; no single selection model is hard-coded.

| Capability | Design requirement |
| --- | --- |
| Selection stages | Entrance score, academics, work experience, diversity, written test, GD, PI or other approved stages |
| Panel constitution | Multiple panels, chair/member eligibility, availability, workload |
| Candidate assignment | By course, stage, slot and panel; no duplicate or conflicting assignments |
| Evaluation criteria | Criteria, maximum marks, weightage, rating scales, mandatory remarks |
| Score aggregation | Approved methods; keep individual marks and moderation history |
| Merit formula | Versioned weights/formula; keep inputs, outputs and formula version |
| Tie-break | Configurable, approved sequence |
| Seat matrix | Branch/course/cycle/round capacity and reservation categories |
| Allocation | Draft simulation, eligibility/capacity checks, approval, publication |
| Waitlist | Policy-aware waitlists and vacancy processing |

Safeguards:
- Reservation rules are data, not code: a default rule set entered from IIFT's official admission policy (Central Government reservation), which the admin can edit. No percentages are hard-coded. Every change is a new version, needs approval, is audited, and applies only to rounds not yet allocated.
- Open-merit seats must not be limited to unreserved-category applicants.
- Configure vertical/horizontal reservation, PwBD, conversion, supernumerary seats and tie-breaks explicitly.
- Never publish an unapproved draft allocation.
- Manual overrides record old/new values, reason, actor, time and approval.

Fresh rounds are distinct from waitlist movement: own approval, round ID, offerings, seat matrix, policy version, eligibility, merit and allocation history. Closed rounds are read-only unless an audited, authorised reopening happens.

## 8. Payments and refunds

No payment gateway. The system records and tracks payments made through approved external channels.

| Step | Operation |
| --- | --- |
| Payment entry | Accounts records application, branch, course, fee head, amount, date, mode, payer, UTR/challan, evidence |
| Submission | Accounts submits the entry for review |
| Review | Admission Officer/designated authority approves, rejects or returns with remarks |
| Verified status | Only an approved payment counts as verified |
| Refund request | Reason, requested/eligible amount, deductions, supporting documents |
| Refund approval | Authorised approver approves, rejects or returns |
| Refund execution | Paid outside the system through authorised channels |
| Refund recording | Accounts records actual date, amount, mode, reference, evidence |
| Reconciliation | Approved payments, refunds, reversals and outstanding balances |

- Full and partial refunds; multiple refund transactions.
- Refunds can never exceed the refundable balance.
- Original payment records are never overwritten or deleted; use adjustments, reversals and refunds.
- A refund does not automatically reinstate an offer or seat.

## 9. Database (MySQL 8, InnoDB)

Foreign keys, indexed relationship columns, transactions and audit history. Documents in private object storage; metadata and storage keys in the database.

| Domain | Principal tables (indicative) |
| --- | --- |
| Organisation | institutions, branches, branch_locations, courses, branch_courses, course_offerings |
| Cycles/rounds | academic_years, admission_cycles, admission_cycle_offerings, admission_rounds |
| Users/access | users, roles, permissions, user_role_assignments, user_branch_assignments, user_course_assignments |
| Teams/committees | committees, committee_members, admission_cells, admission_cell_members, accounts_teams, accounts_team_members |
| Import | external_sources, import_batches, import_files, import_field_mappings, import_records, import_errors, import_update_reviews |
| Applicants | applicants, applications, external_applications, application_academics, application_entrance_details, application_preferences |
| Documents | document_types, applicant_documents, document_versions, document_verifications, file_access_logs |
| Scrutiny | scrutiny_records, eligibility_criteria, eligibility_assessments, eligibility_results, application_status_history |
| GD/PI | faculty, interview_panels, interview_panel_members, interview_schedules, interview_candidates, evaluation_criteria, interview_evaluations, interview_evaluation_scores |
| Contact | applicant_contacts (call/email log, outcome, recorded response) |
| Merit | merit_formulas, merit_components, merit_calculations, merit_component_scores, merit_lists, merit_list_entries, merit_approvals |
| Allocation | reservation_rule_sets, reservation_rule_versions, reservation_categories, seat_matrices, seat_allocation_rounds, seat_allocations, waitlists, admission_offers, offer_responses |
| Admission | admission_confirmations, admission_enrolments |
| Accounts | fee_heads, application_fee_dues, payment_records, payment_reviews, payment_adjustments, refund_requests, refund_transactions, payment_reconciliations |
| System/audit | audit_logs, notification_templates, notification_logs, integration_api_logs, system_settings |

- Unique constraints on external IDs, internal application numbers and round participation.
- DECIMAL for marks and money; never floating point.
- Restrictive deletion for approved admissions, merit, payment and audit records.

## 10. API (versioned, `/api/v1`)

Authentication, server-side permissions, validation, pagination, rate limits and audit logging on every endpoint.

| Area | Indicative endpoints |
| --- | --- |
| Authentication | POST /auth/login, POST /auth/logout, GET /auth/me |
| Organisation | GET/POST /branches, GET/POST /courses, POST /branch-courses, GET /course-offerings |
| Cycles/rounds | GET/POST /admission-cycles, POST /cycles/{id}/rounds, POST /rounds/{id}/close |
| Users/committees | POST /users, POST /committees, POST /committees/{id}/members, POST /assignments |
| Imports | POST /imports/excel, POST /imports/csv, POST /imports/api-fetch, POST /imports/documents/zip |
| Import review | GET /imports/{id}/preview, GET /imports/{id}/errors, POST /imports/{id}/validate, /approve, /execute |
| Applicants | GET /applicants, GET /applicants/{id}, GET /applications, GET /applications/{id} |
| Scrutiny | POST /applications/{id}/scrutiny, POST /applications/{id}/eligibility-assessment |
| GD/PI | POST /panels, /schedules, /candidate-assignments, /evaluations, /evaluations/{id}/submit |
| Merit | POST /merit-calculations/simulate, /merit-lists, /merit-lists/{id}/approve, /merit-lists/{id}/publish |
| Allocation/offers | POST /allocations/simulate, /allocations/{id}/approve, /offers, /offers/{id}/response |
| Payments/refunds | POST /applications/{id}/payments, /payments/{id}/submit, /payments/{id}/approve, /refund-requests, /refund-requests/{id}/approve, /refund-transactions |
| Reports | GET /reports/dashboard, /reports/imports, /reports/merit, /reports/seat-allocation, /reports/accounts |

Async job IDs for large imports, exports and bulk notifications; idempotency keys for integrations; documents via authorised or short-lived signed downloads; workflow state transitions validated on the server.

## 11. Architecture and capacity

| Layer | Design |
| --- | --- |
| Frontend | Vue.js 3 responsive app; role-aware navigation; server-side data grids |
| Backend | Laravel modular monolith: REST APIs, policies, services, events, queued jobs |
| Database | MySQL 8 InnoDB, indexes, foreign keys, query review, tested backup/restore |
| Queue/cache | Redis for queues, caching and rate limits |
| File storage | Private scalable object storage; database holds metadata only |
| Web serving | Linux, Nginx, PHP-FPM, HTTPS, managed deployment |
| Workers | Separate workers for imports, documents, notifications, reports |
| Monitoring | Application logs, queue monitoring, database metrics, uptime and storage alerts |

| Measure | Initial design target |
| --- | --- |
| Applicant master | At least 500,000 records |
| Application records | 1,000,000 or more |
| Documents | Scalable; sized from real file counts and sizes |
| Concurrent users | 300, validated by load testing |
| Bulk imports | Up to 100,000 rows per batch, chunked in the background |
| Growth | Beyond one million applicants without redesign |

These are design targets, not guarantees; benchmark with realistic data before production. Hosting is undecided (own server, VPS or cloud), so nothing may depend on one provider.

**High availability** (downtime not acceptable, see `DECISIONS.md` D17): two or more app servers behind a load balancer with rolling deploys, MySQL primary + replica with automatic failover, replicated Redis, redundant object storage, migrations safe to run live, monitoring and a tested failover drill.

## 12. Security, privacy and audit

- HTTPS everywhere; secure password hashing; MFA for privileged accounts.
- Role and organisational-scope checks on every protected request.
- Protection against SQL injection, XSS, CSRF, insecure direct object references and mass assignment.
- Private document storage, authorisation, malware scanning, download logging.
- Validate file content, size, archive paths and document URLs; allowlist sources (SSRF).
- Protect and rotate integration credentials; least-privilege service accounts.
- Audit logins, imports, changes, document access, evaluations, approvals, merit publication, payments, refunds, applicant contacts.
- Backups with tested restores and restricted access.
- DPDP Act 2023 and rules in force at deployment; institutional privacy, retention and breach-response policies.
- Collect only necessary data; no Aadhaar or other high-risk identifiers without a documented legal basis.

Service objectives: interactive requests under 2 seconds, 99.9% or better availability (agreed), no single point of failure, subject to hosting and load tests. Large imports/exports run asynchronously with progress.

## 13. Reports and dashboards

| Report | Key information |
| --- | --- |
| Central dashboard | Applicants, applications, branch/course distribution, cycle status, alerts |
| Branch/course dashboard | Applications, scrutiny, eligibility, shortlist, GD/PI, merit, admission progress |
| Import dashboard | Batch status, rows, duplicates, failures, document status, reconciliation |
| GD/PI dashboard | Panel workload, schedules, pending evaluations, locked scores |
| Merit dashboard | Draft/final merit, score breakdown, approvals, tie-breaks, publication |
| Seat dashboard | Sanctioned intake, category matrix, allocations, vacancies, waitlists, offers |
| Admission dashboard | Offers issued, accepted, declined, expired, confirmed, pending |
| Accounts dashboard | Payments entered, pending review, approved, rejected, refunds, reconciliation |
| Contact dashboard | Calls made, pending follow-ups, unreachable applicants, reschedules |
| Audit reports | User activity, overrides, approvals and changes by date, user and module |

Filters: cycle, round, branch, course, category, status, date range. Large exports are generated in the background and delivered through secure, time-limited links.

## 14. Development phases (ordered by dependency)

| Phase | Scope and deliverables |
| --- | --- |
| 1. Foundation | Laravel/Vue project, authentication, RBAC, institution, branches, courses, offerings, cycles, rounds, audit foundation |
| 2. Import and applicant master | Sources, Excel/CSV/ZIP/API import, staging, mapping, validation, duplicate controls, applicant/application master |
| 3. Documents and scrutiny | Document import, private storage, verification, checklists, eligibility criteria and decisions |
| 4. Shortlisting and GD/PI | Entrance-score import, shortlist rules, faculty/panels, scheduling, contact log, live evaluation, email notifications |
| 5. Merit engine | Configurable formula, components, tie-breaks, simulation, approval, publication |
| 6. Reservation and allocation | Seat matrices, approved reservation rules, simulation, approval, offers, waitlists |
| 7. Admission and accounts | Confirmation, manual payment entry/review, refunds, reconciliation |
| 8. Fresh rounds | Fresh-round creation, round-wise imports, round-specific rules, merit and allocation history |
| 9. Reporting and go-live | Dashboards, exports, security/load tests, backup/restore, monitoring, training, deployment |

**Critical milestone:** before building downstream modules, prove that a representative portal export imports accurately: no duplicate applications, correct applicant-to-application matching and reliable document association.

## 15. Testing and acceptance

- Import: reconcile row counts, mappings, duplicates, rejected rows and outcomes.
- Documents: matching, private storage, access controls, scanning, failure handling.
- Scale: at least 500,000 applicants with representative applications and documents.
- Concurrency: 300 users across search, scrutiny, scheduling, evaluation, reports, accounts.
- Access: users cannot reach records outside their scope.
- Workflow: status transitions, approvals, rejections, returns, reopening, closure.
- Merit: independently verified formulas, normalisation, rounding, tie-breaks, reproducibility.
- Allocation: seat capacity, category rules, waitlist movement, overrides.
- Accounts: approvals, partial/full refunds, refund caps, evidence, reconciliation.
- Recovery: restore database and documents from backup (including point-in-time) and verify integrity.
- Failover: stop the primary database and an app server during use; the system keeps working without data loss.

**Go-live** needs signed acceptance of core workflows, import reconciliation, security review, load-test results, restore evidence, user training and agreed operational ownership.
