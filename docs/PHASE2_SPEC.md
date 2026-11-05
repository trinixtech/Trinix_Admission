# Phase 2 specification: Import and applicant master

**Status: approved 2026-11-05** (section 11: 1 = b, 2–5 OK). This is the build contract for Phase 2; any change to the tables below needs the product owner's approval (see `CLAUDE.md`).

Phase 2 brings shortlisted applicants from the external portal into the system, round by round, safely: nothing reaches the live tables until a person has seen a preview and approved it. It also gives staff the applicant and application screens.

This is the plan's **critical milestone**: prove that a real portal export imports accurately, with no duplicate applications, before building anything downstream.

Out of scope for Phase 2: document files (Phase 3 brings the ZIP and document-URL channels with private storage and scanning), scrutiny and verification (Phase 3), scores used for shortlisting rules (Phase 4), and pulling data from the portal's own API (waits for the portal's API documentation; see §2).

---

## 1. What a batch goes through

```
upload / API push → stored file → map columns → stage rows → validate → classify → preview → approve → write → reconcile
```

| Step | What happens |
| --- | --- |
| Upload | Staff choose the **source**, the **round** and upload an Excel (`.xlsx`) or CSV file (up to 100,000 rows, 50 MB). The original file is kept in private storage with its SHA-256 hash. Uploading the same file twice for the same round is refused. |
| Map | Staff match file columns to system fields on screen (§4). The mapping can be saved as a **template** for the source and reused. Value mappings are set here too (e.g. portal says `OBC` → system category `OBC-NCL`; course `MBA(IB)` → course `MBA-IB`). |
| Stage | Rows are copied as-is into `import_records` (the original row and the mapped values). Large files are processed on the queue in chunks of 1,000 with progress shown. |
| Validate | Each row is checked: required fields, formats (email, mobile, dates, percentages 0–100), known course and category values, the course is offered in the round's cycle. Problems are listed per row and field. |
| Classify | Each valid row becomes **New**, **Update** (same application already exists and some fields differ), **Unchanged**, or **Duplicate in file**. |
| Preview | Counts per class, an error report (downloadable CSV), and for Updates a field-by-field before/after view. Staff can **exclude** individual rows. |
| Approve | A user with `imports.approve` approves. **The person who uploaded the batch cannot approve it.** On approval the batch is classified again; if anything changed since the preview, it goes back to preview. |
| Write | New applicants and applications are created, Updates applied, and every application is marked as taking part in the round. Runs on the queue in chunks; a failed chunk can be retried; the batch shows partial success honestly. |
| Reconcile | Final counts: rows in file = imported + updated + unchanged + excluded + errors + duplicates. The batch is then **closed** and read-only. |

Every step is audited (who, when, counts). A batch can be **cancelled** any time before approval.

## 2. Channels in Phase 2

| Channel | Phase 2 | Notes |
| --- | --- | --- |
| Excel (`.xlsx`) and CSV upload | **Built** | Streaming reader (OpenSpout), so 100,000 rows do not need much memory |
| REST API **push** (portal sends to us) | **Built** | `POST /api/v1/integration/import-batches` with a source token; same staging, preview and approval as uploads; idempotency key so a repeated push is not imported twice |
| REST API **fetch** (we pull from the portal) | Later | Needs the portal's API documentation |
| ZIP archive, document URLs | Phase 3 | Together with document storage, scanning and verification |

## 3. Identity rules (from the decisions)

- **Duplicate key:** source + portal application number + course + cycle (D2). Unique in the database.
- Each portal application number is **its own applicant** (D12, D12a). A student in MBA IB and MBA BA has two applicant records; they are never merged.
- The **CAT/IPMAT application number** is stored and indexed. When the same entrance number appears on two applications, staff see a cross-reference ("also applied for MBA-BA, 2027-001234"), nothing more.
- The **same portal application number** appearing for two different courses in one cycle is reported as an error, because the portal gives a separate number per course (D12a).
- **Later rounds** (D3): importing Round 2 shortlists adds new applicants; applicants already imported are matched by the duplicate key, shown as Unchanged or Update, and also marked as taking part in Round 2.

## 4. Fields

The portal's export format is not known yet (D13), so the system fields are fixed and the mapping is configurable. Only what admission work needs is collected (DPDP Act 2023): **no Aadhaar, no bank details, no photographs or signatures in data columns.**

| Group | Fields | Required |
| --- | --- | --- |
| Identity | portal application number, course (code or mapped value), full name | Yes |
| Entrance | exam (CAT/IPMAT/other), entrance application number | Yes |
| | entrance score, overall percentile, sectional scores (as a list) | No |
| Contact | email, mobile | Yes |
| | alternate mobile, address, city, state, PIN code | No |
| Personal | date of birth, gender | Yes |
| | nationality, state of domicile, father's/guardian's name | No |
| Category | category (GEN / SC / ST / OBC-NCL / EWS), PwBD (yes/no and type), other category flags the institute uses | Category required |
| Academics | for each of Class 10, Class 12, graduation, post-graduation: board/university, year, marks (percentage or CGPA with scale) | Class 10 and 12 required for IPM; Class 10, 12 and graduation for MBA |
| Experience | work experience in months | No |
| Preferences | campus/branch preferences in order (branch code or mapped value) | No |
| Other | any extra columns | Kept as-is in an `extra` field, shown on the application, not validated |

Percentages, CGPA and scores are stored as **DECIMAL**. Category values are mapped to the reservation categories configured by the admin (D5), not hard-coded beyond the default list.

## 5. Tables (proposed data model)

Columns `id`, `created_at`, `updated_at` are on every table and not repeated. `uid` is a ULID for URLs.

### Import

**external_sources**: `uid`, `code` (unique), `name`, `status`, `description`.
One row per portal or channel (e.g. `PORTAL`). API tokens for push belong to a source.

**import_mapping_templates** (the plan's `import_field_mappings`): `uid`, `external_source_id`, `name`, `column_map` (JSON: system field → file column), `value_map` (JSON: field → {file value → system value}), `created_by`.

**import_batches**: `uid`, `external_source_id`, `admission_round_id`, `channel` (xlsx/csv/api), `status` (uploaded → mapped → staging → validated → approved → writing → completed / partially_completed / failed / cancelled), `original_filename`, `file_path` (private storage), `file_sha256`, `idempotency_key` (API), `column_map` and `value_map` (snapshot used), counts (`rows_total`, `rows_valid`, `rows_error`, `rows_new`, `rows_update`, `rows_unchanged`, `rows_duplicate`, `rows_excluded`, `rows_written`), `uploaded_by`, `approved_by`, `approved_at`, `completed_at`, `cancel_reason`.
Unique (`admission_round_id`, `file_sha256`) and (`external_source_id`, `idempotency_key`).

**import_records** (staging): `import_batch_id`, `row_number`, `raw` (JSON, original row), `mapped` (JSON), `classification` (new/update/unchanged/duplicate/error), `excluded` (bool), `diff` (JSON, for updates), `application_id` (after writing), `written_at`.
Index (`import_batch_id`, `classification`).

**import_errors**: `import_batch_id`, `import_record_id`, `row_number`, `field`, `message`, `severity` (error/warning).

### Applicants and applications

**applicants**: `uid`, `external_source_id`, `full_name`, `date_of_birth`, `gender`, `email`, `mobile`, `alternate_mobile`, `nationality`, `domicile_state`, `guardian_name`, `address`, `city`, `state`, `pincode`.
One per portal application number (D12).

**applications**: `uid`, `applicant_id`, `external_source_id`, `portal_application_no`, `course_id`, `admission_cycle_id`, `category`, `pwbd` (bool), `pwbd_type`, `work_experience_months`, `extra` (JSON), `status` (`imported` in Phase 2; later phases move it on), `first_import_batch_id`, `last_import_batch_id`.
Unique (`external_source_id`, `portal_application_no`, `course_id`, `admission_cycle_id`). Index on (`course_id`, `admission_cycle_id`, `status`).

**application_entrance_details**: `application_id`, `exam`, `entrance_application_no` (indexed), `score` (DECIMAL), `percentile` (DECIMAL 7,4), `sectional` (JSON of name → DECIMAL as string).

**application_academics**: `application_id`, `level` (class10/class12/graduation/postgraduation), `board_or_university`, `qualification`, `year_of_passing`, `marks_type` (percentage/cgpa), `marks` (DECIMAL), `cgpa_scale` (DECIMAL). Unique (`application_id`, `level`).

**application_preferences**: `application_id`, `rank`, `branch_id`. Unique (`application_id`, `rank`) and (`application_id`, `branch_id`). Campus preferences in order (decision 1 = b).

**application_round_participations**: `application_id`, `admission_round_id`, `import_batch_id`, `added_at`. Unique (`application_id`, `admission_round_id`).

**application_status_history**: `application_id`, `from_status`, `to_status`, `reason`, `actor_id`, `occurred_at`.

**application_corrections** (manual correction, plan §6): `application_id`, `field`, `old_value`, `new_value`, `reason`, `actor_id`, `occurred_at`. Also written to the audit log.

No applicant data is ever deleted by an import. Rows are only added or updated, and every update keeps the old value in the batch's `diff` and in the audit log.

## 6. Permissions and roles

New permissions (added by `access:sync`):

| Permission | Meaning |
| --- | --- |
| `imports.view` | See batches, previews and error reports in scope |
| `imports.manage` | Upload, map, save templates, run validation, exclude rows, cancel |
| `imports.approve` | Approve a batch for writing (never one they uploaded) |
| `applicants.view` | See applicants and applications in scope |
| `applicants.edit` | Correct an application field, with a reason |
| `sources.manage` | Manage external sources and their API tokens |

| Role | Gets by default |
| --- | --- |
| Super Admin | All |
| Central Admission Administrator | `imports.*`, `applicants.*` |
| Admission Cell Officer | `imports.view`, `imports.manage`, `applicants.view`, `applicants.edit` |
| Admission Cell Staff | `applicants.view` |
| Branch Administrator, Committee roles | `applicants.view` |
| Accounts, Faculty, Reporting, IT Admin | none in Phase 2 |

Scope: an application's scope is its **course and cycle**. Applications have no branch until allocation (decision 1 = b), so a branch-scoped user sees applications of the courses their branch offers in that cycle. Batches take the scope of their round's cycle. Personal fields (email, mobile, date of birth, address) are shown only to `applicants.view` holders and are never written to logs.

## 7. Screens

| Screen | What it shows |
| --- | --- |
| Imports | Batches with status, round, counts, uploaded by; filters by round and status |
| New import | Source, round, file; then the column-mapping screen with template choice and a sample of 5 rows |
| Batch detail | Progress bar while staging/writing; counts; tabs for Errors, New, Updates (before/after), Unchanged, Duplicates; exclude rows; download error report; approve / cancel |
| Sources | External sources; API tokens (shown once on creation, revocable) |
| Applications | Search by name, portal number or entrance number; filter by course, cycle, round, category, status; paged on the server for 1,000,000+ rows |
| Application detail | Applicant details, entrance, academics, preferences, rounds taken part in, import history, corrections, status history; *Correct a field* with reason |

## 8. API (Phase 2 endpoints, under `/api/v1`)

- Sources: `GET/POST/PATCH /sources`, `POST /sources/{uid}/tokens`, `POST /source-tokens/{id}/revoke`
- Templates: `GET/POST/PATCH /sources/{uid}/mapping-templates`
- Batches: `GET /import-batches`, `POST /import-batches` (upload), `GET /import-batches/{uid}`, `PUT /import-batches/{uid}/mapping`, `POST /import-batches/{uid}/validate`, `GET /import-batches/{uid}/records?classification=`, `GET /import-batches/{uid}/errors` (also as CSV), `POST /import-batches/{uid}/exclusions`, `POST /import-batches/{uid}/approve`, `POST /import-batches/{uid}/cancel`, `POST /import-batches/{uid}/retry`
- Integration push (source token): `POST /integration/import-batches` with `round`, `idempotency_key`, `records[]` (up to 1,000 per call)
- Applications: `GET /applications`, `GET /applications/{uid}`, `POST /applications/{uid}/corrections`

## 9. Tests

- Import reconciliation: rows in = written + unchanged + excluded + errors + duplicates, for CSV and XLSX.
- Duplicate key: the same row twice in one file; the same application in two batches; the same portal number for two courses; the same entrance number on two applications (cross-reference only).
- Round 2 re-import: unchanged rows, updated rows with correct diffs, round participation added, nothing duplicated.
- Approval: uploader cannot approve; re-classification on approval returns the batch to preview if data changed; cancelled and completed batches are read-only.
- Same file twice for one round is refused; API idempotency key replays return the first batch.
- Validation: each field rule; value mapping; unknown course; course not offered in the cycle.
- Scope: an officer for MBA-IB cannot see MBA-BA batches or applications (404).
- Chunked writing: a failure mid-way leaves earlier chunks written, the batch partially completed, and retry finishes it without duplicates.
- Privacy: no personal fields in logs or audit rows beyond what the change itself needs.
- Scale: a generated 100,000-row CSV stages, validates and writes within the queue limits; application search stays paged and indexed (checked against MySQL in CI with a smaller set, and a documented manual run at full size).

## 10. Done when

- A sample file shaped like a portal export (we create one with dummy data until the institute provides a real one) imports for Round 1, re-imports for Round 2 with changes, and reconciles exactly.
- The same steps work through the API push.
- Staff find any application by portal or entrance number in under 2 seconds on 500,000 applications (seeded test data).
- CI green; `CLAUDE.md` updated to "Phase 2 built".

## 11. Points confirmed (approved 2026-11-05)

1. **Branch of an application.** For IIFT's MBA IB, MBA BA and IPM, does an applicant apply to **one campus**, or to the **course** and give **campus preferences** that decide their campus at allocation?
   - (a) One campus: each application has a course offering (branch + course) from the start; branch staff see only their campus's applications.
   - (b) Course with preferences (recommended if that is how IIFT works): applications belong to course + cycle; preferences are stored in order; the branch is fixed at allocation (Phase 6). Until then, branch-scoped staff see applications of courses their branch offers.

   **Decided: (b).**
2. **Separation of duties:** the person who uploads a batch cannot approve it. OK? **Decided: OK.**
3. **Updates in later rounds:** when Round 2 brings changed data for an existing applicant, the change is shown in the preview and applied on approval (field-level review of *verified* fields comes in Phase 3 with verification). OK? **Decided: OK.**
4. **Fields (§4):** anything to add or remove? In particular, should father's/guardian's name and full address be stored? **Decided: OK.**
5. **API:** build the push API now and the fetch (pull) later when the portal's API is known. OK? **Decided: OK.**

---

## 12. As built (2026-11-05): additions and differences

| Item | What was added or differs | Why |
| --- | --- | --- |
| `import_batches.course_id` | Optional: a batch can be limited to one course; rows for other courses are errors | Course-scoped staff (e.g. an MBA-IB officer) may upload and see only their course's batches |
| `import_batches` | `headers` (column names), `rows_processed` (progress), `status_message` | Mapping screen, progress bar, and plain-language messages such as "data changed since the preview" |
| Same file twice | Checked in the application, ignoring cancelled batches (an index, not a unique key) | A cancelled upload can be uploaded again |
| Source tokens | Stored in Laravel Sanctum's `personal_access_tokens` table | Standard, hashed, revocable tokens |
| Security | Staff endpoints accept only tokens that belong to a person; source tokens work only on `/integration/*` | Found while testing: without this, a source token could have reached staff endpoints |
| Campus preferences | A file that brings a new preference list replaces the old list; the old list stays in the batch's `diff` and the audit log | Preferences are an ordered list, not separate facts |
| Extra endpoints | `GET /import-fields`, `GET /import-batches/{uid}/sample`, `GET /integration/import-batches/{uid}` (status for the portal) | Mapping screen and portal status checks |
| Access log | Opening an application writes `application.viewed` to the audit log | DPDP: who looked at whose personal data |
| Application list | Next/previous paging without a total count | Counting a million rows on every search is slow; search stays fast |
| API rate limit | 600 requests per minute per user (was 120) | A busy screen makes several requests; the walk-through hit 120 |
| Branch Administrator | Can no longer give the Admission Cell Officer role | That role can now import, which a Branch Administrator cannot; the no-escalation rule applies |
| Excel | First sheet only; first non-empty row is the header | Portal exports are single-sheet |
| Tools | `php artisan imports:make-sample` (portal-style CSV with dummy data) and `php artisan imports:benchmark` (scale check, refuses production) | Until the institute provides a real export (D13) |
