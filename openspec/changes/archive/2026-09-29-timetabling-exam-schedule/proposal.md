---
kind: code
---

# Schedule exams and test weeks with rooms and invigilators

## Why

TenderNed 271977 (Graafschap College, toetsroosterproces) asks for exam and test week scheduling with rooms and invigilators. Learniq lets an `Assessment` point at a lesson `Session` (`Assessment.sessionId`), holds room capacity (`Room.capacity`) and flags an `exam-clash` (`lib/Timetabling/TimetableConflictDetector.php:222`), and it approves `ExamAccommodation` entitlements (`lib/Lifecycle/ExamAccommodationApprovalGuard.php`), but it schedules no exams: there is no test week, no placement of a sitting in a room, no application of the accommodations to that placement and no invigilator duty. Four competitors rate yes on the tender row and three on accommodations. Invigilator assignment is one competitor only, but the tender row names invigilators, so it is part of the same change. Placement is by a person with checks; the recorded non-goal of timetable generation (no solver) is respected. These rows sit in the planninq matrix and are owned by learniq.

The rows share one screen or service, so they are one change.

### Matrix rows (`planninq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `tt-exam-schedule` | Schedule exams and test weeks with rooms and invigilators. | `no`: `building`: an exam can sit in a lesson slot and clashes are flagged, but there is no test week and no exam placement with rooms |
| `tt-exam-accommodations` | Give students with special arrangements extra exam time or a separate room in the exam schedule. | `no`: `building`: an approved `ExamAccommodation` exists but nothing places extra time or a separate room in an exam schedule |
| `tt-invigilator-assignment` | Collect invigilator availability and assign invigilators to exam sessions with a confirmation step. | `no`: `none`: no availability and no assignment of invigilators to exam sessions |

### Demand

- `tt-exam-schedule`: tender, https://www.tenderned.nl/aankondigingen/overzicht/271977
- `tt-exam-accommodations`: no demand row.
- `tt-invigilator-assignment`: no demand row.

### Competitors rated yes

- `tt-exam-schedule`, zermelo: "docs read 2026-09-27: https://support.zermelo.nl/guides/roostermaker/toetsen-roosteren steps include 'Surveillanten op toetsen plaatsen. Lokalen op toetsen plaatsen. Toetsen verdelen over de lokalen, rekening houdend met tijdverle"
- `tt-exam-schedule`, untis: "docs read 2026-09-27: https://www.untis.at/produkte/webuntis-die-online-erweiterung 'Auch eine Prüfungsverwaltung ... über das WebUntis Grundpaket nutzbar' ; https://help.untis.at/hc/de/articles/360016423100 'Prüfungsplanung ... P"
- `tt-exam-schedule`, xedule: "docs read 2026-09-27: https://support.xedule.nl/hc/nl/articles/35942489427602-Uitvoeren-Toetsen-roosteren 'de toetsautomaat plant toetsen binnen toetstijdsloten in een toetsperiode ... zal Xedule voor elke ingeroosterde toets een "
- `tt-exam-schedule`, timeedit: "docs read 2026-09-27: https://timeedit.com/platform/scheduling/assessment 'Automatically or manual schedule exams efficiently with a separate rule set ... Invigilator Management ... Book invigilators based on competence and type'"
- `tt-exam-accommodations`, zermelo: "docs read 2026-09-27: https://support.zermelo.nl/guides/roostermaker/leerlingen-met-tijdverlenging 'U kunt leerlingen die recht hebben op tijdverlenging, bijvoorbeeld dyslectische leerlingen, apart inroosteren voor hun toetsen'"
- `tt-exam-accommodations`, xedule: "docs read 2026-09-27: https://support.xedule.nl/hc/nl/articles/33907908453906-Hulpmiddelen 'Het is mogelijk om extra tijd aan toetsen toe te voegen voor studenten die dit nodig hebben ... Extra tijd kan worden ingesteld als extra "
- `tt-exam-accommodations`, timeedit: "docs read 2026-09-27: https://timeedit.com/platform/scheduling/assessment 'Manage extra time, alternative venues, and other special requirements to ensure equitable conditions'"
- `tt-invigilator-assignment`, timeedit: "docs read 2026-09-27: https://timeedit.com/platform/scheduling/assessment 'Complete management of invigilators, from availability collection to assiging session and confirmation flows'"

## What Changes

- Add `ExamPeriod` (a test week with dates and the cohorts and courses in scope) and `ExamSitting` (assessment, period, start, end, rooms, invigilators, headcount, status).
- Check each placement: room capacity against headcount, room and cohort clashes through the existing conflict detector, and the learner's other lessons.
- Apply approved `ExamAccommodation` entitlements to a sitting: extra time lengthens the end for that learner, a separate room creates a second room slot.
- Add invigilator availability and assignment: invigilators state availability for the period, a planner assigns them to sittings, and the invigilator confirms or declines.

## Capabilities

### New Capabilities

- `exam-schedule`

### Modified Capabilities

- None in delta form.

## Impact

- **Register**: new schemas `ExamPeriod`, `ExamSitting`, `InvigilatorAvailability`; `Session` and `Assessment` unchanged.
- **Backend**: a sitting placement service that calls `TimetableConflictDetector`, an accommodation resolver, notifications for invigilator requests.
- **Frontend**: exam schedule page and dialogs in `src/manifest.d/`, an invigilator availability page.
- **Scope**: no solver, no automatic placement (design non-goal of `2026-07-16-timetabling-and-substitution`).
- **Cross-row**: matrix rows live in the planninq matrix; the coordinator sets them, this repo sets none.
