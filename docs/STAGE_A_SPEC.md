# Stage A specification: first usable release (before Phase 6)

**Status: approved and built 2026-11-05** (section 6 answered; section 7 lists what the answers changed; section 8 is the as-built record). Requested by the product owner on 2026-11-05: build "half" first, so the institute can start using the system. Phase 6 (reservation, allocation, offers; `docs/PHASE6_SPEC.md`) stays **on hold** until it is discussed again.

## 1. What Stage A covers

| Need | Already built | Added in Stage A |
| --- | --- | --- |
| Bulk import of student files | Phase 2: Excel/CSV/API import of applicants, staging → check → approval; Phase 3: ZIP and link import of documents | Nothing new; the installer's checklist points to them |
| Bulk import of faculty | Users are invited one at a time | **Faculty bulk import** (§2) |
| Panel allocation for GD and PI, automatic with override | Phase 4: panels, members and slots entered by hand; "fill free places" for one panel; move and cancel a candidate | **Automatic allocation** of candidates across all panels of a stage, and of faculty to panels, both with manual override (§3) |
| Marks entry, average by a rule the admin decides, lock marks | Phase 4: live marking, autosave, submit, chair locks; result = simple average | **Admin-chosen rule** for combining panel members' marks, per stage (§4) |
| Merit list without reservation | Phase 5: merit formula, list with overall rank, approval, publication, exports | Nothing new: the overall rank is the merit list without reservation. Category ranks stay, for later use |
| Installation | Developers' commands and Docker only | **Installation wizard** in the browser (§5) |

## 2. Faculty bulk import

- **Where:** People → Users → **Import faculty**.
- **File:** Excel or CSV with columns **name**, **email**, and optionally **mobile**, **designation**, **department**, **campus** (branch code), **role** (Faculty / Panel Member by default; or Committee Member or Committee Chairperson).
- **Preview first:** every row is checked before anything is saved. The preview shows:
  - new users;
  - users who already exist (matched by email) and only get the new role;
  - errors, such as a bad email, unknown campus, unknown role, or a role the importer may not give (the no-escalation rule).
- **Confirm** creates the users, gives the roles (scoped to the campus when a campus is given) and sends each new user the usual **invitation email** to set a password. It is audited.
- Faculty need no two-factor sign-in (as today). Nothing is deleted or changed for existing users except the added role.

## 3. Automatic panel allocation, with override

**3a. Candidates to panels (GD, PI or any stage).** On the panels screen for a stage and date range, **Allocate automatically** works as follows:

- It takes every called applicant (Phase 4 call list) who is not yet scheduled for that stage. For PI after GD, it only takes those whose GD result is locked, and checks the stage's minimum previous average.
- It places each applicant in a slot of an active panel of that stage:
  1. **Same city first:** a panel whose location city matches the applicant's city, then any in-person panel, then online panels. The admin can turn city matching off.
  2. **Balanced:** applicants are spread evenly over panels and slots. GD groups are filled up to the slot capacity.
  3. **No clashes:** the existing checks apply (overlapping slots, a full slot, declared conflicts of interest, the applicant's other stages).
  4. **PI after GD:** a PI slot that starts after the applicant's GD slot ends.
- It shows a **preview** (who goes where, and who could not be placed with the reason). Nothing is saved or emailed until **Confirm**.
- **Override:** move any candidate to another slot or panel (with a reason), swap two candidates, or remove one. Emails go out for every change, as today.

**3b. Faculty to panels.** **Assign faculty automatically** works as follows:

- The admin chooses the **faculty pool** (people with the panel role in scope; filter by campus) and **members per panel** (e.g. 3).
- The system fills each panel that has no members yet, naming one chair per panel. The pool's committee chairpersons are preferred as chair; otherwise the most senior designation; otherwise the first in the list.
- It respects the existing rules: no one on two panels at the same time, and no one with a declared conflict.
- It spreads the workload evenly: the fewest panels per person first.
- The result is shown as a **preview**, then **Confirm**. **Override:** add, remove or swap members, and change the chair, as today.

## 4. Rule for combining panel members' marks (per stage, chosen by the admin)

On **Stages and criteria**, a new setting **"Stage result from panel members' marks"** offers:

| Rule | Meaning |
| --- | --- |
| Average of all members (default, as today) | Sum of totals ÷ number of members |
| Average after dropping the highest and lowest | Used only when at least 3 members marked; otherwise the plain average |
| Median | The middle total (average of the two middle totals when the count is even) |
| Average per criterion | Each criterion is averaged across members, then the averages are added; same total, but shown per criterion |

- Results are rounded half up to 2 decimals and kept exact (hundredths), as today.
- The rule is shown on the panel summary and stored with the locked result. **Changing the rule after any result is locked is refused** for that stage.
- Locking stays as it is: when every member has submitted, the chair or Central Admin locks; reopening needs a reason.
- The merit formula (Phase 5) reads the stage result calculated by this rule.

## 5. Installation wizard

A browser wizard at **`/install`** for a fresh server, so nothing needs typing on the command line except starting the web server.

1. **Check:**
   - PHP version and extensions;
   - writable folders;
   - whether the built front end is present;
   - Redis, ClamAV and storage, each shown as "needed for production; can be added later".
2. **Database:** host, port, name, user and password; tested before going on. MySQL 8; SQLite only for a trial.
3. **Site and email:** institution name, site URL, time zone (Asia/Kolkata), mail server settings with a **test email**.
4. **Storage and services:** private file storage (local folder, or S3-compatible bucket with keys); Redis (optional at first); virus scanner (ClamAV address, or "test mode", which is shown as a warning on System health until real scanning is set).
5. **Save settings:** writes `.env`. It keeps any lines already there and **never replaces an existing `APP_KEY`**, so a second app server can copy the same file.
6. **Create tables:** runs the migrations and `access:sync` (roles, permissions, default settings).
7. **First Super Admin:** name, email and password. Two-factor setup is asked at the first sign-in, as today.
8. **Finish:** links to sign in, plus a **checklist**:
   - organisation (institution, campuses, courses);
   - cycle and rounds;
   - invite or import staff and faculty;
   - import sources;
   - document types;
   - stages.

**Safety:**
- `/install` is **open only until the first Super Admin exists**; after that it returns 404 for good.
- Until the app has a key and tables, every page sends visitors to `/install`.
- Answers stay in the form (no session) and nothing is written before step 5.
- Passwords are never logged or shown again.
- Later database changes run from **System → Maintenance**, which shows pending migrations to an IT Administrator and runs them with confirmation and an audit row. This is for servers without command-line access; a rolling deploy still runs `migrate` as documented.

## 6. Points to confirm

1. **Faculty import columns** (§2): name, email, mobile, designation, department, campus, role. Anything else? Should existing users only **gain** the role (never lose one)? *Recommended:* yes.
2. **Automatic candidate allocation** (§3a): same city first, then any in-person panel, then online; spread evenly; preview, then confirm. OK? Is the applicant's **city** the right field to match, or should it use a **GD/PI centre preference** from the portal? (We have no such field yet.)
3. **Automatic faculty assignment** (§3b): number of members per panel chosen each time; chair taken from committee chairpersons, then by designation. OK?
4. **Marks rules** (§4): average / drop highest and lowest / median / per-criterion average. Any other rule IIFT uses (for example the **chair's marks count double**, or **a given weight for each member**)? This adds one setting to each stage (a small data-model change). OK?
5. **Installer** (§5): open only until the first Super Admin exists; database changes later from System → Maintenance for IT Administrators. OK?

## 7. Answers (2026-11-05) and what they change

1. **Faculty import: OK.** Panel members may **also be Committee Members**: the import has a tick box "Also give the Committee Member role" and the role column may list several roles (separated by `;`). Existing users only gain roles.
2. **Allocation by city or by the applicant's own GD/PI centre preference.** Applications get **interview centre preferences** (up to 3 cities, in order) as a new import field. Allocation tries the preferred centres in order, then the applicant's city, then any in-person panel, then online.
3. **No chairpersons for now: panels have members only.** Automatic faculty assignment names **no chair** unless the admin ticks "name a chair". On a panel **without a chair**, any current member may mark attendance. Locking results and sending candidates on is done by admission staff or Central Admin (`evaluations.reopen` / `panels.manage`), as today.
4. **The stage result formula is entered by the admin.** Instead of a fixed list, each stage has a **formula over the panel members' totals**, using `SUM`, `COUNT`, `AVG`, `MIN`, `MAX`, `MEDIAN`, numbers, `+ - * /` and brackets. For example `(SUM - MAX - MIN) / (COUNT - 2)` drops the highest and lowest. The admin also sets the **minimum number of members** the formula needs; with fewer, the plain average is used.
   - The formula is checked when saved, and calculated with exact decimals, rounded half up to 2 places.
   - It is never run as code: a small built-in reader understands only these words and signs.
   - The default is `AVG`. It **cannot change once any result in the stage is locked**.
5. **Installer: OK.**
6. **Added:** GD/PI **marks components (criteria) and maximum marks are set by the admin for every cycle.** Stages and criteria already belong to a course and cycle. Stage A adds **"Copy from the previous cycle"**, which copies the stages, criteria, maximum marks and settings into a new cycle as a starting point to edit.

**Data-model changes (approved with these answers):**
- `applications.interview_centres` (JSON list, nullable);
- `selection_stages.result_formula` (string) and `selection_stages.formula_min_members` (small integer).

Both are added columns, safe to run on a live system.

## 8. As built (2026-11-05)

| Item | What was built | Notes |
| --- | --- | --- |
| Faculty import | People → Users → **Import faculty** (`POST /faculty-imports`, then `/faculty-imports/{token}/confirm`). Columns are matched by name (Name, Email/Email ID, Mobile, Designation, Department, Campus, Role). Roles may be listed with `;` | Only panel roles can be given this way (Faculty / Panel Member, Committee Member, Committee Chairperson); no-escalation and own-roles checks apply. Rows are checked again on confirm. Only the person who previewed can confirm, within an hour. Up to 2,000 rows |
| New user columns | `users.designation`, `users.department` | Shown on the user profile |
| GD/PI centre preferences | Import fields **GD/PI centre preference 1–3**, stored in `applications.interview_centres`; shown on the application | A new list replaces the old one as a whole; empty cells change nothing |
| Automatic candidate allocation | Interview panels → **Allocate candidates automatically**: preview, then confirm (schedules and emails through the normal scheduling checks) | Placement goes **level by level**: everyone who can get their first centre preference first, then second, third, then their city, then another in-person panel, then online; within a level, the least-filled panel. "New Delhi" matches "Delhi". PI: only after a locked GD, in a later slot, with the stage's minimum previous average |
| Override | **Move** (as before), **Swap** two candidates (any panels of the same stage, by application number, with a reason; both emailed), **Cancel** | Swap checks conflicts of interest and clashes |
| Automatic faculty assignment | Interview panels → **Assign faculty automatically**: members per panel, optional campus, optional chair; preview, then confirm | Fewest panels per person first; never two panels at the same time. With no chair, any member may mark attendance |
| Stage-result formula | Stages and criteria → **Stage result from panel members' totals** and **Members needed for this formula** | `StageFormula` is a small parser (SUM, COUNT, AVG, MIN, MAX, MEDIAN, numbers, + - * /, brackets), exact decimals, 2 places half up. Division by zero or too few members falls back to the plain average, and the result says so. It cannot change once any result in the stage is locked. Merit (Phase 5) uses this result |
| Copy stages | Stages and criteria → **Copy from cycle** (for a cycle without stages) | Copies stages, active criteria, maximum marks and settings |
| Installation wizard | `/install`: check → database → site and email (test email) → storage and services → save `.env` → tables → first Super Admin → finish | Runs without sessions, cookies or CSRF. Every page redirects to `/install` until the first Super Admin exists (API: 503); then `/install` returns 404 and a marker file is kept per server. Off in automated tests (`INSTALLER_ENFORCE`). Tried in a browser on a fresh copy of the app (no key, no database) through to the first sign-in |
| Maintenance | System → **Maintenance** (`system.maintain`, IT Administrator and Super Admin): pending database updates, applied after typing MIGRATE and the user's password; audited | Roles and permissions are reloaded after each update |
