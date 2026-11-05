# Phase 4 specification: Shortlisting and GD/PI

**Status: approved 2026-11-05 (section 11 answered); built 2026-11-05 (section 12).** This is the build contract for Phase 4; any change to the tables below needs the product owner's approval (see `CLAUDE.md`).

Phase 4 decides **who is called** for the selection process (WAT/GD/PI), **organises** the panels and time slots, lets admission staff **contact** applicants and schedule or reschedule them (D9), and lets panel members **enter marks live** on a phone or tablet (D7). Marks are collected and locked here; turning them into a merit list is Phase 5.

Out of scope: merit formula and lists (Phase 5), reservation and seat allocation (Phase 6).

---

## 1. Shortlisting (who is called)

- Entrance scores are already part of the applicant data (Phase 2: score, overall percentile, sectional scores). Scores that arrive later are imported the same way, as updates.
- For each course, cycle and round, the admin enters **call rules** from IIFT's policy: for example an overall percentile cut-off and sectional minimums, **each per category** (GEN, EWS, OBC-NCL, SC, ST, PwBD), and "eligibility decided as eligible or provisional". **No values are suggested**: cut-offs are institutional decisions.
- Rules are **versioned and approved** before use, with a source reference, like eligibility criteria.
- **Simulation:** the system runs the approved rules and shows who would be called, by category, with the reason for each person (which rule they met or missed). Nothing is published.
- **Manual changes** to the list (add or remove a person) need a reason and are shown separately.
- **Publishing** the call list needs `shortlist.approve`, by someone other than the person who ran it. A published list is read-only; a later change is a new, audited version.
- Each application gets a **call status** for the round: called / not called, with the reason.

## 2. Selection stages and marks

For each course and cycle the admin sets the **stages** (e.g. Written Ability Test, Group Discussion, Personal Interview), and for each stage:

- **criteria** with maximum marks (DECIMAL), e.g. Communication 10, Subject knowledge 15, Overall 5;
- whether a **remark** is required (always / only when marks are below a set value / never);
- every current panel member evaluates each candidate (decision 3); the stage result is the average of their totals;
- the **outlier flag** threshold: an evaluator's total that differs from the panel's average by more than this many marks is highlighted (not changed).

Weights and how stages combine into merit are Phase 5.

## 3. Panels, slots and scheduling

- A **panel** belongs to a round and a stage, has a **chair** and **members** (users with the Faculty / Panel Member role, or committee members), a date, and a **venue** (in person) or a **meeting link** (online).
- **Slots**: start and end times with a capacity (1 for a PI; e.g. 10 for a GD).
- **Scheduling**: staff place called applicants into slots, one by one or in bulk (e.g. "fill these slots with the next 60 called MBA-IB applicants").
- **Conflict checks**, refused with a clear message:
  - an applicant in two overlapping slots, or twice in the same stage of a round;
  - a panel member on two panels at the same time;
  - more candidates than a slot holds;
  - a panel member who is (or is marked as) related to the candidate. Members can declare a **conflict of interest** with a candidate, and that candidate is moved to another panel.
- **Emails** (from editable templates, D8): "called for selection process" when the list is published; "your schedule" when scheduled; "changed schedule" when rescheduled; a reminder the day before. Each is logged (address hashed), with retries.

### Stage by stage, each with its own panels

Each stage has its **own panels**: a candidate takes the GD with a GD panel, then moves to a **different** PI panel (and WAT, if used, likewise). When a GD group's results are locked, the GD panel's chair or admission staff **send the group's present candidates on to a PI panel**: each gets that panel's next free PI slot and an email with the time and place. Candidates can also be scheduled for PI directly. Two **admin settings on each stage** (Stages and criteria screen) control this flow:

- **Panel sees marks from earlier stages** (yes/no, default no): whether e.g. PI panel members see the candidate's GD average.
- **Minimum average in the previous stage to be sent here** (optional, empty = no minimum): candidates below it cannot be sent on (the send screen shows them unticked with the reason). The value is entered by the admin from IIFT's practice; nothing is suggested.

 On a GD panel, the marking screen shows the **whole group** (one card per candidate) because a GD panel marks several candidates at once. Mockups: `docs/mockups/` (GD marking, send to PI, PI marking).

## 4. Contact log (D9)

Staff record every contact with an applicant: **channel** (phone call made, call received, email sent, email received), **outcome** (reached, no answer, wrong number, asked to reschedule, confirmed attendance, declined), a note, and an optional follow-up date. From the same screen they can **schedule or reschedule** the interview, which sends the email and links the contact entry to the change. A **follow-ups** list shows what is due today.

Applicants never log in (D10); their replies come by phone or email and are recorded here, always with who recorded them.

## 5. Live evaluation (D7)

- A panel member signs in on a phone or tablet and sees **today's slots for their panels only**, and only their assigned candidates.
- For each candidate: the application (decision 4) and documents, then one field per criterion with its maximum, and remarks.
- **Autosave** every few seconds and on every change (online, D7); a clear "saved" indicator; nothing is lost if the screen locks.
- **Attendance** (present / absent) is marked by the chair or admission staff; marks can be entered only for present candidates.
- **Submit** locks that evaluator's marks. Panel members cannot see each other's marks until they have submitted their own.
- **Reopening** submitted marks needs a reason and the `evaluations.reopen` permission (proposed: panel chair and Central Admin); old values are kept.
- When every evaluator has submitted, the panel chair **locks** the candidate's result for that stage. A **panel summary** shows totals, the panel average and outlier flags.
- Marks are stored per evaluator per criterion (DECIMAL). The combined result (e.g. average of evaluators) is shown for information; how it counts toward merit is Phase 5.

## 6. Tables (proposed data model)

Columns `id`, `created_at`, `updated_at` on every table; `uid` (ULID) where used in URLs.

### Shortlisting

**shortlist_rules**: `uid`, `course_id`, `admission_cycle_id`, `admission_round_id`, `version`, `status` (draft/approved/retired), `rules` (JSON: per category, field, operator, value), `require_eligibility` (bool), `source_reference`, `created_by`, `approved_by`, `approved_at`.

**shortlists**: `uid`, `shortlist_rule_id`, `admission_round_id`, `course_id`, `version`, `status` (simulated/published/superseded), `counts` (JSON by category), `run_by`, `run_at`, `published_by`, `published_at`.

**shortlist_entries**: `shortlist_id`, `application_id`, `called` (bool), `reason`, `manual` (bool), `manual_reason`, `manual_by`. Unique (`shortlist_id`, `application_id`).

### Stages and panels

**selection_stages**: `uid`, `course_id`, `admission_cycle_id`, `code` (WAT/GD/PI…), `name`, `sort_order`, `remark_rule` (JSON), `outlier_threshold` (DECIMAL), `show_previous_marks` (bool), `min_previous_average` (DECIMAL, nullable), `status`.

**evaluation_criteria**: `selection_stage_id`, `name`, `max_marks` (DECIMAL 6,2), `sort_order`.

**interview_panels**: `uid`, `admission_round_id`, `selection_stage_id`, `name`, `panel_date`, `mode` (in_person/online), `location_id` (in person; city and address come from the location), `venue` (room or hall), `meeting_link` (online), `status`.

**interview_panel_members**: `interview_panel_id`, `user_id`, `role` (chair/member), `added_by`, `removed_at`.

**interview_slots**: `uid`, `interview_panel_id`, `starts_at`, `ends_at`, `capacity`.

**interview_candidates**: `uid`, `interview_slot_id`, `application_id`, `selection_stage_id`, `admission_round_id`, `status` (scheduled/present/absent/completed/cancelled), `active` (1, or null once cancelled or moved), `scheduled_by`, `reminded_at`, `result_locked_at`, `result_locked_by`. Unique (`application_id`, `selection_stage_id`, `admission_round_id`, `active`), so only one active booking per stage and round.

**panel_conflicts**: `interview_panel_id`, `user_id`, `application_id`, `reason`, `declared_at`.

### Evaluation

**interview_evaluations**: `uid`, `interview_candidate_id`, `evaluator_id`, `status` (draft/submitted/reopened), `remarks`, `submitted_at`, `reopened_by`, `reopened_at`, `reopen_reason`. Unique (`interview_candidate_id`, `evaluator_id`).

**interview_evaluation_scores**: `interview_evaluation_id`, `evaluation_criterion_id`, `marks` (DECIMAL 6,2), `remark`. Unique (`interview_evaluation_id`, `evaluation_criterion_id`).

**evaluation_score_history**: `interview_evaluation_id`, `snapshot` (JSON), `reason`, `actor_id`, `occurred_at` (kept on submit and reopen).

### Contact and notices

**applicant_contacts**: `uid`, `application_id`, `channel`, `outcome`, `note`, `follow_up_on`, `interview_candidate_id` (if the contact led to a schedule change), `actor_id`, `occurred_at`.

Notices reuse `notification_templates` and `notification_logs` (Phase 3) with new templates: `called_for_selection`, `interview_scheduled`, `interview_rescheduled`, `interview_reminder`.

## 7. Permissions and roles

| Permission | Meaning |
| --- | --- |
| `shortlist.manage` | Draft call rules, run simulations, make manual changes |
| `shortlist.approve` | Approve call rules; publish a call list (never one they ran) |
| `stages.configure` | Set up stages and criteria |
| `panels.manage` | Create panels and slots, assign members, schedule and reschedule candidates, mark attendance |
| `contacts.record` | Record applicant contacts; schedule or reschedule from the contact screen |
| `evaluations.submit` | Enter and submit marks for candidates on own panels only |
| `evaluations.reopen` | Reopen submitted marks with a reason; lock results (also: the panel's own chair) |
| `evaluations.view` | See all marks and panel summaries in scope |

| Role | Gets by default |
| --- | --- |
| Super Admin | All |
| Central Admission Administrator | All |
| Committee Chairperson | `shortlist.approve`, `evaluations.view`, `evaluations.reopen`, `evaluations.submit` |
| Committee Member | `evaluations.submit` |
| Admission Cell Officer | `shortlist.manage`, `panels.manage`, `contacts.record`, `evaluations.view` |
| Admission Cell Staff | `contacts.record`, `panels.manage` |
| Faculty / Panel Member | `evaluations.submit` |

Panel scope: `evaluations.submit` works **only** for candidates on panels the user is a current member of (the plan's "panel assignment" scope), on top of course and cycle scope.

## 8. Screens

| Screen | For | What it shows |
| --- | --- | --- |
| Call rules and lists | Officers, approvers | Rules per category, simulate, results by category with reasons, manual changes, publish |
| Stages and criteria | Admin | Stages per course and cycle, criteria and maxima |
| Panels and slots | Officers, staff | Panels by day, members, slots with filled/capacity, bulk scheduling, conflicts |
| Applicant contact | Staff | On the application: contact history, add contact, schedule/reschedule; follow-ups due list |
| My interviews (phone-first) | Panel members | Today's slots, candidate list, mark entry with autosave, submit |
| Panel summary | Chair, officers | Marks by evaluator, averages, outliers, lock results, reopen |

## 9. Tests

- Call rules per category (including PwBD), eligibility requirement, missing scores reported, simulation changes nothing, publishing by a different person only, published list read-only, manual changes need reasons.
- Conflicts: overlapping slots, duplicate stage, member double-booked, capacity, declared conflict of interest.
- Scheduling and rescheduling send the right emails; the contact entry links to the change; reminders the day before, once.
- Evaluation: only own panels' candidates (404 otherwise); marks within 0–maximum; DECIMAL kept exactly; autosave; submit locks; other members' marks hidden until own submit; reopen needs reason and permission, history kept; absent candidates cannot be marked; outlier flag.
- Panel members see the whole application and its documents only for candidates on their own panels (404 otherwise), and each view is logged.
- Browser walk-through: publish a call list, build a panel, schedule, record a phone call and reschedule, enter marks on a phone with autosave (including a page reload mid-entry), submit, chair locks.

## 10. Done when

- For the sample cycle: call rules approved and a call list published; a GD panel and a PI panel with slots; candidates scheduled with emails in the test inbox; a reschedule recorded from a phone call; two panel members enter and submit marks on phones; the chair locks results; the panel summary shows averages and an outlier.
- CI green; `CLAUDE.md` updated to "Phase 4 built".

## 11. Points confirmed (approved 2026-11-05)

1. **Who is called?** Is the list the portal sends (D3: only shortlisted applicants are imported) **already** the WAT/GD/PI call list, or does IIFT apply **its own cut-offs** to it?
   - (a) Already the call list: everyone imported for the round (and found eligible) is called; §1 becomes a simple "call everyone eligible" step with manual changes.
   - (b) IIFT applies its own cut-offs: build the call rules in §1 (values entered by the admin from IIFT's policy).

   **Decided: (b)** IIFT applies its own cut-offs; the admin enters them per category (nothing suggested).

2. **Stages:** which stages does each course have (e.g. MBA IB and BA: WAT + GD + PI; IPM: WAT + PI)? Only the names are needed now; criteria and marks are entered by the admin.

   **Decided:** each course has its own stages. A starting set (MBA: WAT, GD, PI; IPM: WAT, PI) can be loaded and edited; criteria and marks are entered by the admin.

3. **Evaluators:** how many panel members mark each candidate, and should the system show their **average** as the stage result (for information; merit is Phase 5)?

   **Decided:** every panel member marks every candidate; the stage result is their average. (`evaluators_required` is therefore not stored: all current members who have not declared a conflict must submit.)

4. **What panel members see about a candidate.** Proposed: name, application number, course, academics, work experience, entrance percentile, and the photo identity document. **Hidden from panels:** category, PwBD status (unless the candidate needs an accommodation, shown as a note), contact details, date of birth. OK?

   **Decided:** panel members see the whole application (including category, PwBD, contact details, date of birth and documents), but only for candidates on their own panels, and every view is logged.

5. **Interviews:** in person, online, or both (venue and/or meeting link)?

   **Decided:** both, in several cities and locations. Panels link to a location (Phase 1 `locations`: city, address) or are online with a meeting link; bulk scheduling can filter applicants by city.

6. **Reopening submitted marks:** only the panel chair or a Central Admin, with a reason. OK?

   **Decided: OK.**

7. **Reminder email the day before** the interview. OK?

   **Decided: OK.**

---

## 12. As built (2026-11-05): additions and differences

| Item | What was added or differs | Why |
| --- | --- | --- |
| `interview_candidates.active` | Nullable flag, unique with application + stage + round; cleared on cancel | One live booking per stage and round, enforced by the database, while cancelled rows stay as history |
| `interview_candidates.reminded_at` | Set when the day-before reminder is sent; cleared on reschedule | Reminder sent once (§3, decision D24); a moved interview gets a new reminder |
| `applicant_contacts.follow_up_done_at` | Marks a follow-up as dealt with | The follow-ups list shows only open ones |
| `evaluation_criteria.status` | Removed criteria become `inactive` instead of being deleted | Marks already entered keep their criterion |
| `evaluation_score_history.event` | `submitted` or `reopened`, with a snapshot of the marks | Full history of each evaluator's marks |
| `selection_stages.show_previous_marks`, `min_previous_average` | Admin settings per stage: the panel sees earlier stages' averages (default off); minimum earlier-stage average to be sent on (optional, entered by the admin) | GD by one panel, PI by another (user's request) |
| Send on (GD → PI) | `POST /interview-candidates/send-on`: locked candidates get the next free slot on a later stage's panel that starts after their current slot ends, and an email | GD and PI run by different panels |
| Branch Administrator | Also gets `contacts.record`, `panels.manage` and `evaluations.submit` | Otherwise the no-escalation rule would stop them giving Admission Cell Staff and Faculty roles in their branch |
| Panel members and applications | Panel members open the application and documents of candidates on their own panels; every application opening is audited (`application.viewed_by_panel`), every file opening is in `file_access_logs`; any other application returns 404 to them | Decision D24 (point 4) |
| Who sees other members' marks | A panel member sees the others' totals only after submitting their own; the panel summary is for the chair and users with `evaluations.view` | §5 |
| Member picker | `GET /panels/{panel}/eligible-members` lists active users who may mark candidates of that course and cycle | Admission staff do not need access to the user list |
| Reminders | `php artisan interviews:send-reminders`, scheduled daily at 09:00 IST on one server | Day-before email (D24) |
| Times | Slot times are entered and shown in IST and stored in UTC | Panels in several cities, one time zone |
