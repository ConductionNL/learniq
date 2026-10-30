---
kind: spec
depends_on: [placement-and-bookings-follow-the-boards, internship-hours, portal-public-index]
---

# Proposal: board-data-the-schemas-lacked

## Why

Round 4 (08 Oct) stopped on three board items for which no schema had a place:

- "Nog 1 plek" and "6 plekken vrij" under the academy's course days: a cohort had no capacity.
- "Afspraken" on Milan's placement: no fields for his workdays, the workplace address or the qualification with its crebo.
- "Werkprocessen" with hours and the student's own estimate. `werkproces-assessment` holds the trainer's judgement, and requires an assessor and an outcome, so it cannot carry a student's own record before the trainer has judged.

## What changes

Additive only: new optional properties and one new schema. No type, format or required list of an existing property changes. Register 0.39.0 to 0.40.0 (register entity 0.22.0 to 0.23.0).

- **`cohort.capacity`** (integer, optional; cohort 0.2.0 to 0.3.0). The cohort is the course date both a company booking (`course-booking.cohortId`) and an enrolment point at, so it owns the places.
- **`bpv-placement`** gets `workdaysLabel`, `workplaceAddress`, `qualificationName` and `crebo` (strings, optional; 0.4.0 to 0.5.0).
- **New schema `werkproces-progress`** (0.1.0): a student's own record of one work process during a placement. It holds the placement, the student, the code and name, `hoursSpent` and `selfAssessment` (goed, voldoende, onvoldoende), and stays a sibling of `werkproces-assessment`.
- **Seeds**, through the generators (`scripts/example-sets/training.py`, `mbo.py`):
  - the academy's four story course dates get a capacity of 4, 6, 4 and 12, which gives "Nog 1 plek", "6 plekken vrij", "Nog 3 plekken" and "10 plekken vrij";
  - Milan's placement gets the board's agreements;
  - six `werkproces-progress` rows carry the board's hours and estimates. The last two have no estimate, the board's "Nu invullen".
  `werkproces-progress` is appended to the mbo schema order, so no existing uuid moves. Its rows carry no dates, and the cohort dates still move with the load week (`demo-dates-follow-the-load-week`).
- **Public index:** `CoursePlaces` works out the places left as the capacity minus the places of live bookings and live enrolments without a booking. The course row's `note` is "Vol", "Nog 1 plek" or "Nog N plekken" (warning, up to 3), else "N plekken vrij" (positive). There is no note without a capacity.
- **Placement page:**
  - the record shows the four agreements, labelled, and the detail block is headed "Afspraken";
  - under the hours a table "Werkprocessen" lists `studentWorkProcesses`, narrowed to the open placement: code, work process, hours and "Jouw inschatting" in words.

## Also in this change (portaliq round 5, merged)

- The child's name on each absence report: a lookup by the report's own `learnerRef` into `parentChildren` (portaliq #1408).
- "Daarna" skips the next course day (`skip: 1`, `limit: 4`, portaliq #1407).
- "Volgende stap": a steps highlight card (portaliq #1409). The steps provider gives the current step its visit's day as `date`, and the visit's narrative as its description. There is no button yet, because the self-assessment has no page.
- "Je begeleiders": `studentTrainers`, the praktijkopleider read through a forward join on her own placements' `practicalTrainerId`; and `studentSchoolCoaches`, the staff row whose user id is her placements' `schoolCoachId` (reverse join, shown as a Nextcloud user). Both are readable by the student only, and show names and the company only.

## Not in this change
- The guardian's task title with the child's name: a conference round names groups (`cohortIds`), never one child, so a lookup by row field has nothing to key on.

- "Nu invullen" as an action: a student filling in her own estimate needs an update action on `werkproces-progress`.
- The begeleider names: these wait on portaliq's lookup by row field (FIX-P).
