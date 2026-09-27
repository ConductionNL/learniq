# Design: enrolment-statutory-fields

## Context
`Enrolment` today is a bare learner-course join (`learnerId`, `courseId`, `cohortId`, `source`, lifecycle `pending -> active -> completed | withdrawn | failed`). Rows 3.1/3.2/1.4 need it to also carry the statutory inschrijving/uitschrijving/leerjaar shape. `RolloverService.php` currently derives leerjaar by parsing the Cohort's `name` string, which cannot express a combination group's per-pupil split (row 1.4's whole point).

## Goals / Non-Goals
- **Goal**: give `Enrolment` a home for inschrijving date/volgnummer/vestiging, uitschrijving destination school, and per-pupil leerjaar.
- **Goal**: surface leerjaar where a coordinator already looks (the CohortDetail roster).
- **Non-goal**: fixing `RolloverService.php`'s name-parsing (code change, separate from this config-only change).
- **Non-goal**: ROD transmission (integriq, D3).

## Decisions

### Decision 1: `Enrolment.locationId` is separate from `Cohort.locationId`
The inschrijving's vestiging (a statutory fact about where the pupil is enrolled) and the cohort's vestiging (which physical location a groep runs at) are two different questions that usually agree but are not definitionally the same: a pupil can be enrolled before being placed in any cohort at all (`cohortId` is nullable on `Enrolment` already). Reusing `Cohort.locationId` would make inschrijving vestiging depend on a cohort assignment existing, which is backwards for a statutory field. Both reference the same `Vestiging` schema from `school-and-location-records`.

### Decision 2: `leerjaar` is an integer 1-8, not an enum
PO leerjaar runs 1-8, VO leerjaar 1-6; a plain bounded integer covers both segments without a segment-conditional enum, and matches this register's existing plain-integer style for bounded statutory values (e.g. `Room.capacity`).

### Decision 3: no lifecycle guard requires these fields
None of the three inschrijving fields or `destinationSchoolId` gate the `activate`/`withdraw` transitions. Requiring them would be a behavioural (code, guard-class) change; this round only adds the data model. A future statutory-completeness change can add the guard once a school actually needs enforcement.

## Declarative-vs-imperative decision (ADR-031)
No lifecycle, aggregation, calculation, or notification behaviour is added. Five additive properties plus one manifest column, JSON-only in `lib/Settings/learniq_register.json` and `src/manifest.d/learning.json`.

## Seed Data (ADR-001)
Two `Enrolment` seeds on the existing combination-group `Cohort` seed ("Groep 5/6", added in `school-and-location-records`): one with `leerjaar: 5`, one with `leerjaar: 6`, both with `inschrijvingDate`, a distinct `volgnummer`, and `locationId` pointing at the same seeded `Vestiging` the cohort references.

## Risks / Trade-offs
[Risk] `leerjaar` left unset on historical rows → Mitigation: additive/nullable, no backfill required; the roster column renders empty for those rows.

## Migration Plan
Declarative only. No Nextcloud PHP migration class; revert the JSON diffs to roll back.

## Open Questions
None.
