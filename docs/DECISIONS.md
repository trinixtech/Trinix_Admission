# Decisions

Trinix Admission inherits the product owner's decisions for Trinix Admiso that apply to the Main workflow (numbers kept), plus its own (M-numbers).

## Trinix Admission (Main)

| # | Decision | Effect |
| --- | --- | --- |
| M1 | **Separate product and repository** (2026-11-06): Main = import students, files (optional) and faculty; interview schedule from an Excel template; automatic slot assignment with override; fix, then send emails; staff reschedule; GD and PI (different panels) attendance and marks; marks locked by the admin; merit list from the marks. Trinix Admiso stays as is (all modules) | Pruned copy of Trinix Admiso 0.8.0; version 1.0.0 |
| M2 | Schedule template columns: stage, panel, date, mode, venue, city, room, meeting link, slot start and end, candidates per slot, members' emails | `ScheduleImport`, `docs/SPEC.md` |
| M3 | **Same venue**: after an in-person GD, the PI is in person at the same location, on a different panel; a booking elsewhere is refused | `PanelService::earlierVenue`, automatic assignment |
| M4 | **Marks per criterion** on phones and tablets by every panel member (as in Admiso) | Unchanged marking screens |
| M5 | **Fix, then send**: booking does not email; "Send schedule emails" tells everyone not yet told | `interview_candidates.notified_at`, `PanelService::notifyPending` |
| M6 | **Merit by the admin's formula**, approved and published by a second person | Unchanged merit engine; everyone imported into the round with locked results is ranked |
| M7 | **Admin lock**: one result, or all complete results of a stage, panel or day; reopening by the admin with a reason | `evaluations.lock`, `EvaluationService::lockMany` |
| M8 | Every applicant imported into a round is a candidate (no call lists) | `RoundCandidates` |

## Inherited from Trinix Admiso

| # | Decision |
| --- | --- |
| D1 | Imports by Excel/CSV, ZIP and API through one staging → validation → preview → approval pipeline |
| D2 | Every applicant has a portal application number and a CAT/IPMAT application number; duplicate key: source + portal number + course + cycle |
| D3 | Only shortlisted applicants are imported, round by round |
| D4 | Many campuses and courses, all configurable |
| D6 | The merit formula is the institution's, entered by the admin; nothing hard-coded |
| D7 | GD/PI marks entered live; internet is reliable at venues (online screens with autosave) |
| D8 | Email only; no SMS |
| D10 | Applicants never log in |
| D12, D12a | No person matching across courses; two courses = two applications |
| D13 | The import column mapping is configured in the screen and saved as templates |
| D14 | English only |
| D15–D17 | Hosting-neutral; no data loss; high availability for production (see `docs/DEPLOYMENT.md` for the shared-hosting test site) |
| D19 | Round dates per campus and course |
| D21 | Applications belong to course + cycle |
| D22 | The uploader never approves their own import batch |
| D25 | Applicants give CGPA already converted to a percentage; no normalisation across panels |
| D26 | Faculty bulk import; automatic panel allocation by GD/PI centre preference or city, with override; the admin's stage-result formula; installation wizard |
| D27 | A round may start before its academic year and must end by its end |
