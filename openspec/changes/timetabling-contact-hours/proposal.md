---
kind: code
depends_on: [timetabling-multi-year-hour-plan]
---

# Proposal: timetabling-contact-hours

## Summary

A coordinator opens a report for a school year or period and sees, per group and per course, the contact hours the group was owed by its hour plan next to the hours it was given (lessons held, cancelled ones left out), and per learner the hours they actually attended. Groups and learners that fall short are marked, with how many hours. Owed hours come from the hour plan of `timetabling-multi-year-hour-plan`; given and attended hours come from the lessons and the register learniq already keeps.

## Why

Row `tt-contact-hours` of planninq's matrix (`ConductionNL/planninq openspec/parity/capabilities.json`, planninq#665), owed to learniq ("Track the contact hours each group or learner receives against the hours they are owed."), `none`. Decision: build, tender demand. Learniq issue #1038.

- Tender: https://www.tenderned.nl/aankondigingen/overzicht/271977, Graafschap College, scope "Contacturen, in relatie tot de roostering".
- zermelo, yes: https://support.zermelo.nl/guides/medewerker/leerlingstatistiek-3 "Onderwijstijd (lessen + activiteiten + toetsen)" per student; https://support.zermelo.nl/guides/medewerker/docentstatistiek "een apart overzicht met de contacttijd per docent, afgezet tegen de jaarnorm".
- xedule, yes: https://support.xedule.nl/hc/nl/articles/33451483885202-Jaarplanning-Analyses-Studenten-Geplande-Tijd planned education time per group; https://support.xedule.nl/hc/nl/articles/35763810462482-Jaarplanning-Analyses-Studenten-Afwijkingen-van-normuren "Als een opleiding in een jaar niet aan de norm voldoet worden de onderwijsuren in rood aangeven".
- untis, partial: https://help.untis.at/hc/de/articles/360009226080-Diagnose reports "Stunden zu viel oder zu wenig für Klassen und Lehrkräfte".
- timeedit, partial: https://www.academy.timeedit.com/guides-tutorials/how-to-define-conditions-and-calculated-fields "'contact hours' is automatically calculated based on the number of credits * <norm hours per credit>".

## What learniq has today

Read at learniq `development` 8bb8401d.

- `AttendanceRecord` (`lib/Settings/learniq_register.json:15474`) has a materialised calculation `lesuren` (:15613): attended minutes divided by 60, or the session's `durationMinutes` divided by 60 for a full absence, "Used for the 16-lesuur leerplicht threshold". It is per record.
- `Session` has a materialised calculation `durationMinutes` (:6376, the difference between `endsAt` and `startsAt`) and a `lifecycle` with `cancelled`.
- `ReportPeriod` (:10680) has `startDate`, `endDate`, `holidays` and `studyDays`; its schema says the holidays are not yet used by any hours count.
- Nothing holds owed hours, and nothing sums hours per group or learner over a period (learniq#1038, re-checked).
- The Reports page (`src/manifest.json:286`) is the card launcher for readings like this one (D8).

## What this change builds

1. A `ContactHoursService` that, for a period (a report period or a school year), returns per cohort and course: owed contact hours from the cohort's hour plan lines, given hours from the cohort's non-cancelled sessions, and the difference; and per learner of the cohort: attended hours summed from `lesuren` of their present, late and left-early records.
2. A report page "Contact hours" as a card under Reports, category attendance: a table per cohort with owed, given and difference per course, a total row, shortfalls marked, and a drill-down to learners with their attended hours against the group's given hours. CSV export.
3. `GET /api/reports/contact-hours?periodId=...` for staff.

## Out of scope

- Teacher contact time against a teacher's annual norm (the Zermelo docentstatistiek). That is a staff deployment question (humaniq's `tt-staff-deployment`).
- Changing the timetable to make up hours (the timetabler's job).
- Counting non-lesson activities that have no session (excursions, self study) until they are scheduled as sessions.

## Affected projects

- [x] `learniq`: a service, a controller and route, a report page, a Reports card, l10n.

## Risks

- After D10 the sessions live in planninq. Mitigation: the service reads sessions through one reader method, which today reads learniq `Session` and after the follow-up `sessions-from-planninq` reads planninq's `timetableSession` (its `status: cancelled` and `startsAt`/`endsAt` carry the same information).
- Large schools. Mitigation: the report is computed per cohort on demand, with the attendance sum as one aggregated read per cohort.
