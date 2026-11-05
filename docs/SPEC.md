# Trinix Admission: product specification (1.0)

Trinix Admission is the **Main** product split from Trinix Admiso 0.8.0 (decision M1). It runs the interview-based admission of shortlisted applicants, from import to merit list, for one institution with many campuses, courses, cycles and rounds, in one installation and one database. Trinix Admiso keeps the optional modules (scrutiny and eligibility, call lists, contact log, reservation and seat allocation, offers and waitlist, admission and accounts).

## The workflow

| Step | Who | Where |
| --- | --- | --- |
| 1. Setup: institution, campuses, locations (interview venues), courses, academic year, cycle, rounds with campus-wise dates | Super Admin / Central Admin | Setup |
| 2. **Import students**: the portal's shortlist for a round (Excel/CSV, or the portal's API). Staged, checked, previewed, approved by another person | Officer, then Central Admin | Imports → Student imports |
| 3. **Import files** (optional): applicants' documents as a ZIP or as links from the portal, matched by file name, virus-scanned | Officer | Imports |
| 4. **Import faculty**: panel members from Excel/CSV (optionally also Committee Members) | Super Admin / Central Admin | People → Users |
| 5. **Stages and criteria**: GD, PI (and others) per course and cycle, criteria and maximum marks, the stage-result formula, slot order | Central Admin | Interviews → Stages and criteria |
| 6. **Interview schedule**: dates, panels, venues, slots and members, from the **Excel template** (download, fill, upload, preview, save) or by hand | Officer / staff | Interviews → Panels, slots and schedule |
| 7. **Run the simulation to assign slots** (preview, then confirm) and **assign faculty**; **override** by moving or swapping candidates | Officer / staff | Panels |
| 8. **Fix, then send emails**: booking does not email; when the schedule is final, "Send schedule emails" tells everyone not yet told. Later changes are emailed at once | Officer / staff | Panels |
| 9. **Staff reschedule** with a reason | Staff | Panel page |
| 10. **GD**: attendance and marks on phones and tablets (autosave), per criterion; the total is computed | Panel members | My interviews |
| 11. **PI** on a **different panel**: candidates are sent on from GD, or assigned automatically. After an in-person GD, the PI is at the **same venue**; the automatic assignment prefers a panel without the GD panel's members | Officer, panel members | Panels, My interviews |
| 12. **Marks lock by the admin**: one candidate, or every complete result of a stage, panel or day at once. Reopening needs a reason | Central Admin | Panels |
| 13. **Merit list** from the admin's formula (stage results, entrance score, academics, work experience; tie-breaks), run, approved and published by another person, exported | Officer, Central Admin / Committee Chair | Merit |

## Rules (in addition to CLAUDE.md)

- Every applicant imported into a round is a GD/PI candidate of that round (no call lists).
- Booking a slot, by hand or automatically, never emails the applicant; "Send schedule emails" does, once per booking. A reschedule or swap emails at once only if the applicant was already told.
- Same venue: if an applicant's earlier stage in the round was in person, a later in-person stage must be at the same location; an online later stage is refused. A booking elsewhere is refused with the reason.
- Marks are entered per criterion by every panel member without a declared conflict. Only the admin (`evaluations.lock`) locks results; a result locks only when every expected member has submitted.
- The merit list ranks every applicant of the round with a locked result in every stage the formula uses.

## Interview schedule template

One row per slot. Rows with the same stage, panel name and date form one panel; an existing panel with that name, stage and date in the round gets the new slots and members.

| Column | Meaning |
| --- | --- |
| Stage | Stage code of the course, e.g. GD, PI |
| Panel | Panel name |
| Date | DD-MM-YYYY (or YYYY-MM-DD) |
| Mode | In person / Online |
| Venue | Location code or name (Setup → Locations); in person only |
| City | Optional check: must match the venue's city |
| Room | Room or hall (optional) |
| Meeting link | https link; online only |
| Slot start, Slot end | Times, e.g. 10:00 and 10:45 (12-hour times with AM/PM also work) |
| Candidates per slot | 1 to 50 |
| Members | Panel members' email addresses separated by ; (any row of the panel) |

The upload is checked as a whole. Every problem is listed by row:
- unknown stage, venue or member;
- a member who cannot mark this course;
- a city that does not match the venue;
- times out of order;
- overlapping slots;
- a member on two panels at the same time (in the file or already).

Nothing is saved until every row is correct and the preview is confirmed; then all of it is saved, or none.

## Not in this product

Scrutiny and eligibility, call lists, the applicant contact log, reservation and seat allocation, offers and waitlists, admission, fees, payments and refunds. They are in Trinix Admiso.
