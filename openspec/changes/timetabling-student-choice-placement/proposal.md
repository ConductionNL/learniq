---
kind: code
---

# Place a student's elective choices in their timetable

## Why

Two competitors (Zermelo among them) let students choose electives or flexible hours and place those choices in the timetable. `SubjectChoiceEnrolmentBridge` (`lib/Listener/SubjectChoiceEnrolmentBridge.php`) turns a locked `SubjectChoice` into `Enrolment` rows and the `SubjectChoicePicker` page exists (`src/manifest.d/learning.json:240-257`), but `TimetableController::mine` resolves lessons by the caller's cohorts and teaching (`lib/Controller/TimetableController.php:121-160`), so an elective session of another cohort never appears. The row sits in the planninq matrix and is owned by learniq.

One row, one change.

### Matrix rows (`planninq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `tt-student-choice` | Let students choose electives or flexible hours themselves and place those choices in the timetable. | `no`: `building`: an approved `SubjectChoice` becomes an enrolment, but the sessions of the chosen electives do not reach the learner's timetable and the picker shows no clash |

### Demand

- `tt-student-choice`: no demand row.

### Competitors rated yes

- `tt-student-choice`, zermelo: "docs read 2026-09-27: https://support.zermelo.nl/guides/leerling-ouder/inschrijven-voor-keuzelessen 'Als leerling kun je je via de Zermelo WebApp inschrijven voor keuzelessen' ; docs read 2026-09-27: https://zermelo.nl/software 'Z"
- `tt-student-choice`, timeedit: "docs read 2026-09-27: https://timeedit.com/platform/scheduling/allocation 'Self-Registration of Students: Let students sign up to specific classes or groups ... Preferential Allocation: Let student submit their preferences and our"

## What Changes

- Resolve a learner's sessions also from their active enrolments: sessions whose course the learner is enrolled in (any source) and whose cohort the learner does not belong to.
- Show the timetable slot of each elective in the `SubjectChoicePicker` and warn when two chosen electives, or an elective and a core lesson, overlap.

## Capabilities

### New Capabilities

- `timetable-student-choice`

### Modified Capabilities

- None in delta form.

## Impact

- **Backend**: a `sessionsForEnrolments` step in the timetable source and `TimetableController::mine`, clash check in the picker's data route.
- **Frontend**: `SubjectChoicePicker` slot display and warning.
- **Database**: none.
- **Cross-row**: `attendance-timetable-calendar-feed` reuses the same resolver once extracted.
