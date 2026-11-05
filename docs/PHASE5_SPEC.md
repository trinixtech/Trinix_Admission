# Phase 5 specification: Merit

**Status: approved 2026-11-05 (section 10 answered); built 2026-11-05 (section 11).** This is the build contract for Phase 5; any change to the tables below needs the product owner's approval (see `CLAUDE.md`).

Phase 5 turns each candidate's data into a **merit list**: a total score and a rank for every candidate in a course and round. The candidate's data includes entrance score, academics, work experience and the locked WAT/GD/PI results from Phase 4. The **formula comes only from IIFT** (decision D6). The system ships **no weights, cut-offs or tie-break order**. It provides a configurable, versioned **formula engine**: the admin enters IIFT's formula, it is approved, and every list records exactly which formula version and which inputs produced it.

Out of scope: reservation, the seat matrix and seat allocation (Phase 6); offers, waitlists and responses (Phase 7). Phase 5 does produce the **category and PwBD ranks** that Phase 6 will use.

---

## 1. The merit formula

A formula belongs to a **course and cycle** and is a **weighted sum of components**. Each component turns one piece of candidate data into points:

| Component source | Examples | Where the value comes from |
| --- | --- | --- |
| Entrance | CAT/IPMAT overall percentile or score; a sectional score | Phase 2 entrance details |
| Academics | Class 10, Class 12, graduation marks | Phase 2 academics: the **percentage the applicant gave** (no CGPA conversion by the system, §10 point 2) |
| Work experience | Months of full-time experience | Phase 2 application |
| Selection stage | WAT, GD or PI result | Phase 4 locked stage average |
| Applicant field with a points table | e.g. academic background or gender, **only if IIFT's policy has such points** | Phase 2 applicant and academics |

How a value becomes points, chosen per component:

- **Scale to weight:** points = value ÷ maximum possible value × weight. For example, a percentile out of 100 scaled to the weight IIFT sets.
- **Bands:** a table of ranges and points, for example months of experience → points. The admin enters the ranges.
- **Points by value:** a table of values and points, for example a qualification group → points.
- **As is:** the value itself, optionally capped.

For each component the admin also sets:

- **Weight** and **cap** (DECIMAL).
- **When the value is missing:** count as zero, or leave the candidate out with the reason shown.
- **Minimum to qualify (optional):** per category, for example a minimum PI average. A candidate below it is not ranked and the reason is shown. Values come only from the admin (§10 point 4).
- **Academic marks recorded only as CGPA:** applicants convert CGPA to a percentage themselves and give it (§10 point 2). If an application has only a CGPA for a level the formula uses, the value counts as missing (the component's missing rule applies) and the list shows "percentage not given", so staff can get it from the applicant and correct the field with a reason.

Formula-wide settings:

- **Rounding:** decimal places for component points and for the total (default 4 internally, shown to 2), always rounded half up. Arithmetic is exact decimal, never floating point.
- **Tie-break order:** an ordered list chosen from the components, entrance percentile, a sectional score, academic marks, and date of birth (older or younger first). The admin enters IIFT's order (§10 point 5).
- **Who is included:** applicants called in the round (Phase 4) with eligibility decided as eligible or provisional, and a locked result in every stage the formula uses. A candidate absent from a required stage is not ranked, and the reason is shown.

The formula is a **structured list**, not free text. Every component can be explained and checked, and no typed expression is ever run on the server.

### Versions and approval

Formulas are **versioned like eligibility criteria**:

- The admin edits a draft.
- Approving it needs `merit.approve` and a **source reference**, for example "Admissions Committee minutes, 12 Jan 2027".
- An approved version is read-only; a change is a new version.
- A merit list always records the formula version it used.

### No normalisation

GD and PI results enter the formula **as they are** (the locked panel average from Phase 4); there is no adjustment between panels (§10 point 3).

## 2. Running, checking and approving a merit list

1. **Run (simulation).** A user with `merit.manage` runs the approved formula for a course and round. The system calculates every candidate's points per component, total, overall rank, category rank and PwBD rank, and the reason for anyone left out. Nothing is published, and running again replaces the earlier simulation.
2. **Check.**
   - The list shows each candidate's breakdown: raw value, points per component, total, and which tie-break decided their place.
   - It also shows counts by category, who was left out and why, and any **unresolved ties**: candidates still level after every tie-break, who share a rank until the committee decides.
3. **Manual change.** Rarely needed. A committee decision, such as resolving a remaining tie, is recorded with a reason, shown separately, and audited. Marks are never edited here; a wrong mark is corrected in Phase 4 (reopen with a reason) and the list is run again.
4. **Approve.** A user with `merit.approve` approves the list. That user cannot be the person who ran it.
5. **Publish.** This makes the list final for the round, and Phase 6 allocates from it. A published list is read-only.
   - If marks or data change afterwards, the system flags the list as out of date. A new version can be run, approved and published, and the screen shows the difference from the previous one.
   - An unapproved list can never be published (`CLAUDE.md`).

**Reproducibility.** Each entry stores the exact input values it used (a snapshot) and the formula version. A stored hash of inputs and formula proves that running the same version on the same snapshot gives the same totals and ranks. A test checks this.

## 3. Ranks

- **Overall (common) rank** among all ranked candidates of the course and round.
- **Category rank** within the candidate's own category (GEN, EWS, OBC-NCL, SC, ST), and **PwBD rank** among PwBD candidates.
  - Phase 6 needs these for reservation.
  - Open seats are never limited to unreserved candidates (PLAN §7). That rule belongs to allocation; ranks only record order.
- Equal totals are ordered by the tie-break order. If candidates are still level, they share the rank, flagged as above.

## 4. Exports

The merit list can be exported:

- to **Excel/CSV**, with the full breakdown, for the committee;
- to **PDF**, with a notice-board layout: application number, name, category, total and ranks, and no contact details.

Every export is logged. Applicants do not log in (D10), and Phase 5 sends **no emails**; offers are emailed in Phase 7 (§10 point 7).

## 5. Tables (proposed data model)

The plan names these tables: `merit_formulas`, `merit_components`, `merit_calculations`, `merit_component_scores`, `merit_lists`, `merit_list_entries`, `merit_approvals`.

Proposed changes to that set:

- A list run **is** the calculation, so `merit_calculations` is merged into `merit_lists`.
- Approvals are columns on the list and the formula, with every step in the audit log, so `merit_approvals` is not a separate table.

**merit_formulas**: `uid`, `course_id`, `admission_cycle_id`, `version`, `status` (draft/approved/retired), `name`, `rounding` (JSON: component and total decimal places), `tie_breaks` (JSON, ordered), `inclusion` (JSON: required stages, eligibility statuses), `source_reference`, `created_by`, `approved_by`, `approved_at`. Unique (`course_id`, `admission_cycle_id`, `version`).

**merit_components**: `merit_formula_id`, `key`, `label`, `source` (entrance_percentile, entrance_score, sectional, academic, work_experience, stage, field_points), `source_ref` (sectional name, academic level, stage id or field), `method` (scale/bands/points_by_value/as_is), `params` (JSON: maximum input, bands, value table), `weight` DECIMAL(10,4), `cap` DECIMAL(10,4) nullable, `when_missing` (zero/exclude), `minimum` (JSON per category, nullable), `sort_order`.

**merit_lists**: `uid`, `merit_formula_id`, `course_id`, `admission_round_id`, `version`, `status` (simulated/approved/published/superseded), `counts` (JSON), `inputs_hash`, `run_by`, `run_at`, `approved_by`, `approved_at`, `published_by`, `published_at`, `stale_since` (set when underlying data changes after publishing).

**merit_list_entries**: `merit_list_id`, `application_id`, `ranked` (bool), `reason` (left out or tie note), `total` DECIMAL(12,4), `overall_rank`, `category_rank`, `pwbd_rank`, `tie_shared` (bool), `decided_by` (tie-break key that set the order), `inputs` (JSON snapshot), `manual` and `manual_reason`, `manual_by`. Unique (`merit_list_id`, `application_id`).

**merit_component_scores**: `merit_list_entry_id`, `merit_component_id`, `raw_value` DECIMAL(14,4) nullable, `points` DECIMAL(12,4).

Approved and published merit rows use **restrictive deletes** (`CLAUDE.md`).

## 6. Permissions and roles

| Permission | Meaning |
| --- | --- |
| `merit.configure` | Draft merit formulas (components, weights, tie-breaks) |
| `merit.manage` | Run merit lists, record manual decisions with a reason, export |
| `merit.approve` | Approve formulas; approve and publish merit lists (never one they ran) |
| `merit.view` | See merit lists and breakdowns in scope |

| Role | Gets by default |
| --- | --- |
| Super Admin, Central Admission Administrator | All |
| Committee Chairperson | `merit.approve`, `merit.view` |
| Admission Cell Officer | `merit.configure`, `merit.manage`, `merit.view` |
| Branch Administrator, Reporting Officer | `merit.view` |

Scope is course and cycle, as in earlier phases: 404 outside scope, 403 when visible but not allowed.

## 7. Screens

| Screen | For | What it shows |
| --- | --- | --- |
| Merit formula | Admin, officer | Components (source, method, weight, cap, missing rule, minimum per category), rounding, tie-break order, inclusion, source reference; versions; a live example on one chosen candidate showing each step |
| Merit lists | Officer, approvers | Pick course and round; run, counts by category, ranked and left-out tabs, unresolved ties, approve, publish, compare with the previous version, export |
| Candidate breakdown | Officer, approvers | One candidate: raw values, points per component, total, ranks, tie-break used, formula version |
| Application | Staff with `merit.view` | A Merit tab with the candidate's place in the published list |

All screens are phone-first like earlier phases; the list is also readable on a desktop for committee meetings.

## 8. Tests

- Each component method (scale, bands, points by value, as is), caps, weights, rounding half up at the set places, exact decimals (no floating-point drift on 10,000 candidates).
- Missing values (zero or leave out), minimum per category, absent from a required stage, not eligible, not called.
- Tie-breaks in the set order; shared rank flagged when still level; overall, category and PwBD ranks.
- Academic level with only a CGPA: counted as missing with "percentage not given".
- Formula versions: draft only editable, approval needs a source, a list records its version.
- List approval by someone other than the runner; publishing only an approved list; published list read-only; stale flag when marks are reopened after publishing; a new version shows the difference.
- Reproducibility: re-running a version on the stored snapshot gives identical totals, ranks and hash.
- Scope: 404 outside the course and cycle, 403 without the permission; exports logged.
- Browser walk-through: enter a formula, approve it, run the list, check a breakdown on a phone, approve as another person, publish, export.

## 9. Done when

- For the sample cycle, a formula has been entered and approved with a source. A merit list has been run, approved by a second person and published. A candidate's breakdown can be checked by hand against the formula. Ties are ordered by the set tie-breaks, and the exports open.
- CI green; `CLAUDE.md` updated to "Phase 5 built".

## 10. Points confirmed (approved 2026-11-05)

1. **The formula itself.** Can IIFT share the merit formula for MBA (IB), MBA (BA) and IPM now (components and weights, from the prospectus or the committee), or later? The build does not depend on it: the engine and screens are built with dummy test values, and the real values are entered by the admin. **Is a weighted sum of components (§1) enough**, or does IIFT's formula do something else, such as multiplying components or taking the better of two scores?

   **Decided: later.** The engine and screens are built and tested with dummy values; IIFT's real formula is entered by the admin when available. The weighted sum of components is the structure built.
2. **CGPA to percentage.** Academics may arrive as CGPA. How should CGPA count in merit? *Recommendation:* the admin enters a conversion per CGPA scale (for example 10-point → × 10, or a university's own rule). It is used only in merit; the applicant's original value is kept unchanged.

   **Decided:** applicants convert CGPA to a percentage themselves and give it. The system does no conversion; a level with only a CGPA counts as missing in merit ("percentage not given").
3. **Normalising GD/PI across panels.** Should a stage's results be adjusted when different panels mark more strictly or leniently? *Recommendation:* (a) no normalisation by default, with the option available per stage if the committee wants it later. (b) Normalise from the start. Please choose; if (b), which method does IIFT use?

   **Decided: no adjustment or normalisation.**
4. **Minimum marks to be ranked.** Does IIFT require minimum marks in a stage or component (for example a minimum PI average, possibly per category) to appear in the merit list? *Recommendation:* allow it as an optional setting, values entered by the admin. And a candidate **absent** from a required stage is left out of the list. OK?

   **Decided: yes, an optional setting**, values entered by the admin; a candidate absent from a required stage is left out.
5. **Tie-break order.** Does IIFT have a set order (for example a higher PI result, then a higher CAT percentile, then older candidate first)? *Recommendation:* the admin enters the order with the formula. If candidates are still level after every step, they share a rank and the committee records its decision with a reason.

   **Decided: the admin enters it.**
6. **Rounds.** *Recommendation:* each round has its **own** merit list for the candidates selected in that round, and candidates ranked in an earlier round keep their place in that round's list. Or should a later round's list include everyone ranked so far?

   **Decided: each round has its own merit list.**
7. **Approval and publishing.** *Recommendation:* the Admission Cell Officer runs the list; the Committee Chairperson or Central Admin approves it (never the person who ran it) and publishes it. Publishing is internal plus PDF/Excel exports for the notice board or website, with no emails in Phase 5 (offers by email come in Phase 7). OK?

   **Decided: OK.**

---

## 11. As built (2026-11-05): additions and differences

| Item | What was added or differs | Why |
| --- | --- | --- |
| Module | `app/Modules/Merit`: `MeritEngine` (inputs, scoring and ranking as pure functions of the formula and the stored inputs), `MeritService` (formula versions, runs, ties, approval, publishing, out-of-date checks) | Reproducibility (§2) |
| Exact decimals | `brick/math` for every calculation; points and totals rounded half up at the formula's places (at most 4) | No floating-point drift (§8) |
| Weight with ranges and value tables | Points = table points × weight (weight 1 uses the table as entered); for "scale", the weight is the points at the highest value | One rule for all methods |
| Stage components | A stage result is always required: absent, not scheduled or not locked leaves the candidate out with the reason, whatever "when missing" says | §1 "Who is included" |
| Minimums | Checked on the value read (e.g. the GD average), per category; a PwBD candidate may meet the PwBD value instead (as in call lists) | §10 point 4 |
| Committee tie decisions | Only for candidates who share a rank, all of them at once, on a list not yet approved; recorded with the reason, the person and the audit log; category and PwBD ranks follow the new order | §2 point 3 |
| Out of date | Set automatically when GD/PI marks are reopened or an application field is corrected after publishing; **Check for changes** compares today's data with the stored inputs (and counts new applicants of the round) and also re-scores the stored inputs to prove the result is reproducible | §2 point 5 |
| Merit tab | `GET /applications/{uid}/merit` shows the application's place in published lists to users with `merit.view` | §7 |
| Exports | Excel via OpenSpout (full breakdown); notice-board PDF via `dompdf/dompdf` (number, name, category, total, ranks; no contact details); both need `merit.manage`, and each is audited | §4 |
| Points tables | Fields allowed: gender, graduation and post-graduation qualification, entrance exam; no category (reservation is Phase 6) | Keep reservation out of merit |
