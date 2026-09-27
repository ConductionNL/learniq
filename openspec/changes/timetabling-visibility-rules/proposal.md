---
kind: code
depends_on: []
---

# Proposal: timetabling-visibility-rules

## Summary

A school decides per role whose timetables people may look at: a learner their own only, or also their groups' teachers and the rooms; a teacher their own groups, or every group, teacher and room. Learniq gets a "Timetables" page to look up the timetable of a group, a teacher or a room, and it shows only what the caller's role may see. The same rule applies to the API, so reading sessions directly does not get round it.

## Why

Row `tt-visibility-rules` of planninq's matrix (`ConductionNL/planninq openspec/parity/capabilities.json`, planninq#665), owed to learniq ("Choose per role which other people's timetables and availability teachers and students may see."), `none`. Decision: build, all four timetabling competitors rate yes.

- zermelo, yes: https://support.zermelo.nl/guides/applicatiebeheerder/instellingen-voor-eind-gebruikers, per user type rights such as "Aanwezigheid mijn docenten bekijken" and "Alle roosters bekijken en Leerlingnamen bekijken"; https://support.zermelo.nl/guides/leerling-ouder/alle-roosters-bekijken "Het is afhankelijk van de instellingen van de school welke roosters je mag bekijken".
- untis, yes: https://help.untis.at/hc/de/articles/360012874579, the right "Stundenplan Lehrkraft" per user group set to "Eigene" or "Alle"; https://help.untis.at/hc/de/articles/18097028071708 "für einzelne Benutzergruppen die Einsicht der Stundenpläne zeitlich begrenzen".
- xedule, yes: https://support.xedule.nl/hc/nl/articles/37415902430738-Beheer-Configuratie-MyX "Docent mag roosters zien van ... Student mag roosters zien van ... Docent mag op het rooster de beschikbaarheid zien van".
- timeedit, yes: https://timeedit.com/platform/scheduling/viewer "Let students and staff see only what's relevant to them with role-based, filtered schedule views".

Learniq renders the school timetable under D10 (planninq stores it; planninq's school-timetable-target keeps a timetable page out of planninq), so who sees which timetable is learniq's rule.

## What learniq has today

Read at learniq `development` 8bb8401d.

- `GET /api/timetable/mine` (`lib/Controller/TimetableController.php:111`) returns the caller's own lessons; `MyTimetable.vue` shows them.
- `CohortTimetableView.vue` (`/cohorts/:id/timetable`, `src/manifest.d/learning.json:2508-2518`) reads a cohort's sessions straight from the object API (`src/views/CohortTimetableView.vue:60`).
- `Session` (`lib/Settings/learniq_register.json:6130`) has no `authorization` block, so its object API read is open to every signed-in user: any learner can list every lesson of the school.
- There is no setting for who may see which timetable, and no way to look up a teacher's or a room's timetable.

## What this change builds

1. A `TimetableVisibilityPolicy` (one per tenant): per role (learner, instructor) and per kind (group, teacher, room): `own`, `related` (a learner's own groups' teachers and rooms; a teacher's own groups) or `all`.
2. `GET /api/timetable/of?kind=cohort|teacher|room&id=&from=&to=`, which projects that timetable only when the policy allows the caller, and otherwise answers 403 with the reason.
3. A "Timetables" page with a picker for group, teacher or room and a week view, offering only what the caller may open.
4. An `authorization` block on `Session` so the object API follows the same line: staff groups read sessions directly, learners read them only through the timetable endpoints; `CohortTimetableView` moves to the new endpoint.

## Out of scope

- Hiding learner names inside a lesson (a later setting).
- Time-limited visibility (Untis' "zeitlich begrenzen"); the policy is on or off.
- After D10, planninq's own read rule: planninq's `timetableSession` is readable by every signed-in user (school-timetable-target design). Once learniq reads sessions from planninq, a school's policy only holds if planninq narrows that read or learniq is its only reader. That is named for planninq's lane, not specified here.

## Affected projects

- [x] `learniq`: register (new `TimetableVisibilityPolicy`, `Session` authorization), `TimetableController`, a Timetables page, `CohortTimetableView.vue`, seed data, l10n.

## Risks

- Adding an authorization block to `Session` can hide lessons a screen reads today. Mitigation: task 1 lists every read of `session` in `src/` and `lib/` and moves learner-facing ones to the endpoint before the block lands; the block lands last.
