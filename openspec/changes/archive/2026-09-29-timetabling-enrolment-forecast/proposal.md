---
kind: code
depends_on: [timetabling-multi-year-hour-plan]
---

# Proposal: timetabling-enrolment-forecast

## Summary

In spring a school plans next year's groups and staff. Learniq forecasts the number of learners per programme, programme year and subject for next school year from what it already knows: the current cohorts and their size, a progression rate per programme year (moving up, staying down, leaving), the expected intake, and for secondary schools the subject choices already made. The planner adjusts the rates and the intake, saves the forecast as a scenario, and sees how many groups of a chosen size each programme year and subject needs.

## Why

Row `tt-enrolment-forecast` of planninq's matrix (`ConductionNL/planninq openspec/parity/capabilities.json`, planninq#665), owed to learniq ("Forecast next year's student numbers per programme and subject to size staffing and groups."), `none`. Decision: build, two competitors rate yes.

- zermelo, yes: https://support.zermelo.nl/guides/medewerker/prognoses-bewerken "Prognosticeren doet u altijd van een bronproject (huidige schooljaar) naar een doelproject (volgend schooljaar)"; https://support.zermelo.nl/guides/medewerker/procesbewaking, progress of prognoses per department.
- xedule, yes: https://xedule.nl/modules/leerlingroute "Prognose op leerlingniveau; individuele overgangspercentages per leerling; Vakkenpakketkeuze per leerling"; https://xedule.nl/modules/formatie "verwachte leerlingenaantallen voor de aankomende 6 jaar en de effecten op het personeelsbestand".
- timeedit, partial: https://timeedit.com/platform/curriculum/study-planner lists "Demand Forecasting".

## What learniq has today

Read at learniq `development` 8bb8401d.

- `Cohort` (`lib/Settings/learniq_register.json:5526`): `programmeId`, `academicYear`, `learnerIds`; with `timetabling-multi-year-hour-plan`, also `programmeYear`.
- `SubjectChoice` (:5376): per learner and school year, `programmeId`, `selectedElectiveCourseIds`, a lifecycle with approval.
- `AdmissionsRound` and `Application` (change 2026-07-16-admissions-and-subject-choice): intake with placed applications.
- `BsaProgressEvaluator` and the study progress pages: who is at risk of not moving on (HE and MBO).
- The rollover wizard (change school-year-rollover) moves cohorts up once the year is over. Nothing forecasts before that.

## What this change builds

1. An `EnrolmentForecast` schema: a scenario for a target school year with, per programme and programme year, a progression rate (up, repeat, leave), an intake number, and a target group size.
2. A forecast service that computes from the current cohorts, the scenario's rates and intake, the placed applications and the approved subject choices: learners per programme and programme year next year, learners per elective subject, and groups needed at the target size.
3. A page under Reports: pick or copy a scenario, adjust rates and intake, see the result table and the groups needed, export CSV.

## Out of scope

- Forecasting more than one year ahead (the 6-year Xedule formatie view); one year first.
- Staff needed per subject (humaniq's `tt-staff-deployment` reads this forecast's groups).
- Per learner progression chances from grades; rates are set per programme year.

## Affected projects

- [x] `learniq`: register (new `EnrolmentForecast`), a service and route, a Reports page, seed data, l10n.

## Risks

- A forecast reads as a promise. Mitigation: every figure is labelled forecast with its scenario name and the date it was computed; nothing writes cohorts or enrolments.
