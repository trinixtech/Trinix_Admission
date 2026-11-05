# Decisions and open questions

Decisions given by the product owner on 2026-11-04, after reading the plan (`docs/PLAN.md`). Where they differ from the plan, these win.

## Decided

| # | Topic | Decision | Effect on the design |
| --- | --- | --- | --- |
| D1 | Import channels | Portal format unknown, so **Excel/CSV, ZIP and API are all built** | One staging → validation → preview → approval pipeline for all three. Build order: Excel/CSV, then ZIP (documents), then API once the portal's API is known. |
| D2 | Applicant identifiers | Every applicant has a **portal Application Number** and an **IPMAT or CAT Application Number** | Both stored and indexed. Duplicate key: source + portal application number + course + cycle. |
| D3 | Import population | **Import only shortlisted applicants, round by round** (Round 1 shortlist, then Round 2, and so on) | Every import batch belongs to a round. A later round adds new applicants; existing ones are matched, never duplicated, and changed fields go to update review. |
| D4 | Organisation | **4 branches**, many courses, each different | Branches and courses fully configurable. First focus: **MBA IB, MBA BA and IPM**. |
| D5 | Reservation | **Central Government higher-education reservation, following IIFT's admission rules, editable by the admin** | Reservation is a **rule set** stored in the database, not code: categories, percentages, vertical/horizontal type, PwBD, conversion of unfilled seats, supernumerary seats, order of filling. The default rule set is entered by the admin from IIFT's official admission policy (prospectus/notification); the code ships no hard-coded percentages. The admin can edit it at any time. Each change is a new **version** that needs approval, is audited, and applies only to rounds not yet allocated; a round keeps the version it was allocated with. |
| D6 | Merit formula | **Decided later** | Build a configurable, versioned formula engine; nothing hard-coded. |
| D7 | GD/PI marks | **Entered live** during the interview; **internet is reliable** at venues | Online phone/tablet screens with autosave; no offline mode needed. |
| D8 | Notifications | **Email only. No SMS** | Email templates, bulk queue, logs, retries. SMS is out of scope (the design leaves room to add a channel later). |
| D9 | Applicant contact | Admission staff **call students, record their response, and schedule or reschedule interviews** | Applicant contact log (who, when, channel, outcome, response). Scheduling/rescheduling from the contact screen sends an email. |
| D10 | Applicant access | **Applicants never log in** | No applicant portal. Staff record every applicant response, audited with who recorded it. |
| D11 | Offer responses | **By phone or email**, recorded by staff | No accept/decline links. Staff record the response with channel, date/time and, for email, the reply attached as evidence. |
| D12 | Identity across courses | **No need to recognise** the same person across courses (e.g. IPM then MBA) | Each portal application number is its own applicant record; no cross-course or cross-cycle person matching. |
| D12a | Same student, two MBA courses | A student applying to both MBA IB and MBA BA gets **different application numbers** | Each is a separate applicant record with its own application, scrutiny, GD/PI, merit and offer. The same CAT number may appear on both: allowed, and shown as a cross-reference to staff, never merged. |
| D13 | Sample portal export | **Not available** | The import column mapping must be fully configurable in the admin screen and saved as reusable mapping templates per source. Field list is designed from the plan and adjusted when the first real file arrives. |
| D14 | Language | **English only** | |
| D15 | Hosting | **Own server, VPS or cloud: decided later** | Provider-neutral: S3-compatible or local private storage, Redis, standard Linux/Nginx/PHP-FPM. |
| D16 | Data loss | **No data may be lost** | Point-in-time recovery for MySQL (binary logs shipped off the server), off-site document backups, tested restores. |
| D17 | Downtime | **Downtime is not acceptable**; **99.9% availability target and the extra hosting cost are approved** | High-availability design (see below). |
| D18 | Support | **IT team and institute team** share operation and support | Clear runbooks; admin Maintenance and health pages; roles for IT staff separate from admission roles. |
| D19 | Deadlines | **Set by the institute, branch-wise** | Cycle and round dates are per branch-course offering, not global. |
| D20 | Phase 1 specification | **Approved** with all points in its section 10 | Merged tables (course offerings, scoped role assignments, teams), new IT Administrator role, two-factor for the five listed roles, Vue SPA on the REST API. See `docs/PHASE1_SPEC.md`. |
| D21 | Campus of an application | Applicants apply to the **course** and give **campus preferences**; the campus is decided at allocation | Applications belong to course + cycle; preferences stored in order; branch staff see applications of courses their branch offers |
| D22 | Phase 2 specification | **Approved**: uploader cannot approve their own batch; later-round changes shown in preview and applied on approval; field list as proposed (guardian name and address kept); API push now, fetch later | See `docs/PHASE2_SPEC.md` |
| D23 | Phase 3 specification | **Approved**: suggested document types, requirements, checklists and eligibility rules, all editable (suggested eligibility rules stay drafts until approved with a source reference); Central Admin and Committee Chair approve criteria and confirm "not eligible"; a second person confirms every "not eligible"; ClamAV scanning; deficiency emails with a 7-day default reply time, editable | See `docs/PHASE3_SPEC.md` |
| D24 | Phase 4 specification | **Approved**: IIFT applies its own call cut-offs, entered per category by the admin (none suggested); each course has its own stages; every panel member marks each candidate and the stage result is the average; panel members see the whole application of their own candidates (views logged); interviews in person in several cities/locations and online; chair or Central Admin reopen marks with a reason; reminder email the day before | See `docs/PHASE4_SPEC.md` |
| D25 | Phase 5 specification | **Approved**: merit formula given later (engine built with test values; admin enters IIFT's formula); applicants give CGPA already converted to a percentage (no conversion by the system); no normalisation across panels; optional minimum marks per component and category; tie-break order entered by the admin; each round has its own merit list; officer runs, Committee Chair or Central Admin approves and publishes (never the runner), no emails in Phase 5 | See `docs/PHASE5_SPEC.md` |
| D26 | Build order | **Stage A first** (`docs/STAGE_A_SPEC.md`): faculty bulk import (panel members may also be Committee Members), automatic GD/PI panel allocation by the applicant's interview-centre preference or city with manual override, no panel chairpersons for now, a stage-result formula entered by the admin, GD/PI criteria and maximum marks set by the admin for every cycle (copy from the previous cycle), installation wizard. Phase 6 on hold; IPM discussed later | IIFT has no internal reservation policy beyond Government of India norms: categories and reservation rules will be admin-editable every year (Phase 6 spec) |
| D27 | Round dates | **Admissions run before the academic year** (e.g. the 2027-28 process from December 2026) | A round's dates may start before the academic year begins; they must end by its end. Overlapping rounds for the same offering are still refused |
| D28 | Phase 6 specification | **Approved** (2026-11-06) with offer payments and paid waitlist holds: categories as admin data (incl. NRI, Foreign Nationals, Kashmiri Migrants as supernumerary), admin's rounding, category locked after import, reply-by days, reminders, acceptance and hold amounts and per-category waitlist ratios set at cycle set-up, Accounts verifies payments, staff confirm every lapse, vacancy runs prepared automatically (approved by a second person), moving up holds both seats until the upgrade is accepted; imported candidates count as called; warning when a PI panel member marked the candidate before; slot order rules by name, gender, DOB or application number | See `docs/PHASE6_SPEC.md` sections 11 to 14 |
| D29 | Phase 7 specification | **Approved** (2026-11-06): fee structures with heads, instalments and concessions as admin data (versioned, approved); acceptance amount and waitlist hold count towards fees (hold refunded if never admitted); admission confirmed by the Admission Officer after the acceptance payment and documents are verified; admission number pattern set by the admin (year, cycle, round, campus, course, serial); UGC refund slabs as a suggested draft; reference date = last date of admission per campus and round; refunds prepared and approved by different people; payments verified, rejected or returned; PDF receipts; fee reminders; conditional admissions; bank statement matching; admissions dashboard and reports | See `docs/PHASE7_SPEC.md` section 11 |

### What "no downtime" means in practice

No system can promise zero downtime, but it can survive the failure of any single server and be updated without stopping:

- **Two or more application servers** behind a load balancer; deployments roll one server at a time.
- **MySQL primary with a replica** and automatic failover (for example MySQL InnoDB Cluster or a managed database with standby).
- **Redis with a replica** (Sentinel) or managed Redis; queued jobs are safe to retry.
- **Object storage** with redundancy (S3-compatible) or replicated storage on own servers.
- Database migrations written to run while the app is live (add first, remove later).
- Monitoring and alerts for every component; a tested failover drill before go-live.

Agreed target: **99.9% or better** (at most about 9 hours a year of unplanned outage) instead of the plan's 99.5%. Standby servers roughly double hosting cost compared with a single server; this is approved. The hosting choice (D15) must support it.

## Open questions

None at the moment. New questions are added here as they come up.
