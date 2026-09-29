---
kind: code
depends_on: []
---

# Proposal: timetabling-multi-year-hour-plan

## Summary

A programme gets an hour plan over its whole length: per subject (a course), per year of the programme and per period, how many contact hours a group must receive, with a norm per year. From that plan, and the cohorts that follow the programme this school year, learniq lists the teaching activities that have to be scheduled: group, subject, hours per period. That list is what the timetabler schedules from, in the outside timetabling system that integriq feeds and planninq stores (D10). Learniq plans the education; it does not place a lesson in the week.

## Why

This change covers two rows of planninq's matrix (`ConductionNL/planninq openspec/parity/capabilities.json`, planninq#665, corrections planninq#669), both owed to learniq.

`tt-multi-year-plan` ("Plan education over several years, not only one period or school year at a time."), `none`. Decision: build, tender demand. Learniq issue #1039.

- Tender: https://www.tenderned.nl/aankondigingen/overzicht/271977, Graafschap College, "Het Onderwijslogistieke Project": "Roostering ... zowel per periode als meerjarenplanning".
- Tender: https://www.tenderned.nl/aankondigingen/overzicht/414807, Firda market consultation, asks which functions support "meerjarenplanning".
- xedule, yes: https://xedule.nl/modules/meerjarenplanning "Krijg overzicht in vakken, lestijden en benodigde middelen met de meerjarige structuur ... verdeelt de beroepsspecifieke ondersteuningstijd en beroepspraktijkvorming over meerdere jaren"; https://support.xedule.nl/hc/nl/articles/34983057891218-Doorzetten-Meerjarenplanning-naar-Jaarplanning.
- zermelo, partial: https://support.zermelo.nl/kb/articles/pakketkeuze-over-meerdere-jaren, subject choice forms can depend on "wat de leerlingen in het voorgaande jaar hebben gekozen".
- timeedit, partial: https://timeedit.com/platform/curriculum/study-planner "Visualize the entire degree journey with semester-by-semester planning".

`tt-curriculum-to-activities` ("Turn the curriculum or education plan into the activities that have to be scheduled."), `none`. Decision: build, two competitors rate yes; the activities are derived from the same plan, so one change covers both rows.

- zermelo, yes: https://support.zermelo.nl/guides/roostermaker/lessen-plannen "In dit overzicht staat per regel een geplande groep met het aantal lesuren dat die groep moet krijgen".
- xedule, yes: https://xedule.nl/modules/jaarplanning "Jij creeert gedetailleerde structuren van onderwijsproducten en bijbehorende leeractiviteiten"; https://xedule.nl/modules/meerjarenplanning "met een druk op de knop plannen omzetten naar jaarplanningen".
- untis, partial: module Unterrichtsplanung, "die Aufteilung der anfallenden Unterrichtsstunden auf das Lehrpersonal".
- timeedit, partial: https://timeedit.com/platform/workload/workload "Plan all teaching activities down to the module level".

MBO colleges are the demand here: Graafschap College and Firda are both MBO buyers of an education logistics system.

## What learniq has today

Read at learniq `development` a84b6273 (the same reading as learniq#1039, re-checked).

- `Programme` (`lib/Settings/learniq_register.json:4881`): `courseIds` "Ordered list of Course UUIDs that form the programme's content spine", `curriculumPlanId`, `level`. No duration, no years.
- `CurriculumPlan` (`:5025`): `kind` includes `opleidingsplan` (MBO), `periods` of `{periodId, label, startDate, endDate}` for one plan, `components` for grading. No hours.
- `ReportPeriod` (`:10680`): one `academicYear`, `holidays` and `studyDays` (from the change school-year-shape), used for reporting.
- `Cohort`: `programmeId`, `academicYear`, `period` label. Nothing says which year of the programme a cohort is in.
- `SubjectTeacherAssignment` (`:25280`): `cohortId`, `courseId`, `teacherId`, the teacher of a subject for a group.
- Nothing holds hours per subject per year, and nothing lists what has to be scheduled.

## What this change builds

1. An `HourPlan` schema per programme and school year it starts in (the intake year): lines of subject (course), year of the programme, period, contact hours and optional other hours (practical training, self study), plus a yearly norm per year of the programme.
2. `Cohort.programmeYear` (integer): which year of its programme a cohort is in this school year.
3. A plan editor on the programme page: a grid of subjects by years and periods, with totals per year against the norm, and "Copy from the previous intake".
4. A derived list of teaching activities for a school year: for every cohort with a programme, its hour plan lines for the cohort's programme year, with the teacher from `SubjectTeacherAssignment` where one exists. Shown as a page, exportable as CSV, and readable at `GET /api/hour-plans/activities?academicYear=...` for integriq to hand to the timetabling system.
5. A warning on the plan when a year's contact hours fall below its norm.

## Out of scope

- Placing activities in weeks or rooms (the timetabling system's job; learniq's timetable design keeps generation out, `openspec/changes/archive/2026-07-16-timetabling-and-substitution/design.md:25`).
- Spreading annual hours over the available weeks (planninq row `tt-annual-hours`, deferred).
- Comparing delivered with planned hours (the change `timetabling-contact-hours`, which reads this plan).
- Teacher workload and staff deployment (humaniq's `tt-staff-deployment`).

## Affected projects

- [x] `learniq`: register (new `HourPlan`, `Cohort` 0.2.0), programme detail page, an activities page and route, seed data, l10n.
- Consumers: integriq can read the activity list for a timetabling system export; no integriq change is required for this one to be useful.

## Risks

- Schools model subjects differently (a course per subject per year, or one course per subject). Mitigation: a line names a course and a programme year, so both work.
- An hour plan per intake multiplies plans. Mitigation: "Copy from the previous intake" and a plan that stays valid until replaced.
