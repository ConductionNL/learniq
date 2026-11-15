---
kind: code
depends_on: []
---

# Proposal: timetabling-standby-slots

## Summary

A coordinator plans standby hours (piket, vervanguren): per teacher, the weekly slots in which they are on call to cover a colleague. Teachers see their standby hours in their own timetable. When a coordinator assigns a substitute, the substitution dialog lists the teachers on standby at that lesson's time first, then the teachers who are free then, instead of asking for a user id typed by hand. Learniq suggests; the coordinator chooses.

## Why

Row `tt-standby-slots` of planninq's matrix (`ConductionNL/planninq openspec/parity/capabilities.json`, planninq#665), owed to learniq ("Plan standby slots for teachers so cover is available when a colleague is absent."), `none`. Decision: build, two competitors rate yes.

- zermelo, yes: https://support.zermelo.nl/kb/articles/opvanguren-inroosteren "Opvang-, stip- en vervanguren ... In deze tutorial laten we zien hoe u de opvanguren met de roosterautomaten in kunt roosteren", with a "Vervangerspool".
- xedule, yes: https://support.xedule.nl/hc/nl/articles/33451954299154-Uitvoeren-Standby-RE-in-de-roosterautomaat-van-Xedule "Zijn standby RE niet vastgezet ... Dan maakt Xedule automatisch standby lessen aan voor de RE's".
- untis, partial: https://help.untis.at/hc/de/articles/360011117639, cover suggestions show teachers who "Hat in dieser Stunde Bereitschaft" (paid module).

Substitution is learniq's (archived change 2026-07-16-timetabling-and-substitution; planninq's school-timetable-target leaves substitutions with learniq under D10). Standby hours feed it.

## What learniq has today

Read at learniq `development` 8bb8401d.

- `src/dialogs/SubstitutionModal.vue:72-80`: the substitute is a free-text field labelled "Substitute teacher (Nextcloud user ID)".
- `SessionChangeGuard` (`lib/Lifecycle/SessionChangeGuard.php`) requires a reason and a `substituteTeacherId` for `substitute-teacher`, and a caller who teaches the cohort or is in `admin` or `coordinators` (:63).
- `Staff` (`lib/Settings/learniq_register.json:25183`): `ncUserId`, `roles`, `qualifications`, `workingDays` (weekdays only); no standby.
- `TeacherAvailability` covers conference rounds only (change 2026-07-13-parent-evening-planner).

## What this change builds

1. A `StandbySlot` schema: a teacher, a weekday and a time window (or one date), a location, valid from and until.
2. A standby planning page per school year: a grid of weekdays by lesson hours with the teachers on standby, where a coordinator adds or removes a teacher.
3. Standby hours in the teacher's own timetable, marked as standby.
4. `GET /api/substitution/candidates?sessionId=`: teachers on standby during the lesson, then teachers with no lesson at that time on their working day, each with the reason they are listed.
5. `SubstitutionModal` picks the substitute from that list (a search over staff for anyone else), and still requires a person to choose.

## Out of scope

- Assigning a substitute automatically, and a fairness count of cover taken per teacher (deferred rows `tt-cover-counter`, `tt-cover-request`).
- Generating standby slots with the timetable (the optimiser's job; learniq's design keeps generation out, `openspec/changes/archive/2026-07-16-timetabling-and-substitution/design.md:25`).

## Affected projects

- [x] `learniq`: register (new `StandbySlot`), a candidates service and route, `SubstitutionModal.vue`, the teacher timetable, a planning page, seed data, l10n.

## Risks

- A teacher on standby is in fact teaching (a changed timetable). Mitigation: the candidate list checks the teacher's lessons at that time and moves a busy standby teacher to the bottom with the reason "has a lesson then".
