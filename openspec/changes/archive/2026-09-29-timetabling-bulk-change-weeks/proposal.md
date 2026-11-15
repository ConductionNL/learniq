---
kind: code
depends_on: []
---

# Proposal: timetabling-bulk-change-weeks

## Summary

A teacher is away for three weeks, a lab is closed for renovation until the holidays, a workshop replaces a Friday lesson for a month. Today each lesson must be cancelled, covered or moved one at a time. With this change a coordinator opens one lesson, chooses "Apply to more weeks", ticks the weeks in which the same weekly lesson recurs, and applies one change (cancel, substitute teacher or other room) with one reason to all of them. Each lesson still passes the same checks, conflicts are still detected per lesson, and the people affected get one message listing the lessons, not one per week.

## Why

Row `tt-bulk-change-weeks` of planninq's matrix (`ConductionNL/planninq openspec/parity/capabilities.json`, planninq#665), owed to learniq ("Apply one timetable change to several selected weeks at once."), `none`. Decision: build, two competitors rate yes.

- xedule, yes: https://support.xedule.nl/hc/nl/articles/33475055369746-Weekroosters-Leswijziging-Planning-en-mutaties "Bulkmutaties over meerdere weken uitvoeren ... wijzigingen in de huidige week automatisch ook doorgevoerd in de geselecteerde weken".
- timeedit, yes: https://timeedit.com/platform/scheduling/scheduling "Comprehensive bulk replace and updating features"; https://www.academy.timeedit.com/guides-tutorials/cancelling-and-restoring-reservations "Canceling a Cluster of Reservations ... Multi-week calendar".
- zermelo and untis, unknown: their day timetable pages do not describe it.

Cancellations, substitutions and room changes are learniq's operational record of change (archived change 2026-07-16-timetabling-and-substitution, `design.md:36`; planninq's school-timetable-target leaves them with learniq under D10).

## What learniq has today

Read at learniq `development` 8bb8401d.

- `Session` transitions `cancel`, `substitute-teacher` and `substitute-teacher-in-progress`, each requiring `SessionChangeGuard` (`lib/Settings/learniq_register.json:6331-6343`); a room change is an update of `roomId`.
- `SessionChangeNoticeHandler` fills `affectedLearnerIds` and `affectedParentIds` per lesson, and the `rosterChanged` notification sends "Je rooster is gewijzigd" per transition to those people. Three cancelled weeks mean three messages to every learner and parent.
- `SessionConflictListener` checks each changed lesson for clashes.
- `SubstitutionModal.vue` changes one lesson.

## What this change builds

1. A `SessionChangeBatch` schema: the lessons changed together, the kind of change, the reason, who made it, and the result per lesson.
2. "Apply to more weeks" in the substitution dialog: the weeks in which the same weekly lesson recurs (same group, course, weekday and start time) until a chosen date, each with a tick box, and the change applied to the ticked lessons.
3. `POST /api/session-change-batches`: runs the change on each lesson through the same transition and guard, records the outcome per lesson, and continues past a refused lesson.
4. One message per batch: the per-lesson `rosterChanged` notice is skipped for lessons in a batch, and the batch sends one "Your timetable has changed" message listing its lessons to the union of the affected people.

## Out of scope

- Moving lessons to another day or time in bulk (that changes the timetable itself, which the timetabling system owns under D10).
- Undoing a batch in one action (each lesson can be restored as today).

## Affected projects

- [x] `learniq`: register (new `SessionChangeBatch`, `Session.changeBatchId`, notification condition), a service and route, `SubstitutionModal.vue`, seed data, l10n.

## Risks

- A batch that half fails. Mitigation: the result per lesson is stored and shown, the refused lessons are named with their reason, and nothing is rolled back silently.
