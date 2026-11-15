---
kind: config
depends_on: [school-and-location-records]
---

# Proposal: enrolment-statutory-fields

## Summary
`Enrolment` has no inschrijving date, volgnummer, vestiging code, leerjaar or uitschrijving destination school (round-1 findings 3.1, 3.2, 1.4, all MUST tier; corpus `../parnassys/round1/documented-column.md` rows 3.1/3.2/1.4, `../po-las/round1/documented-columns.md` row 1.4 ESIS combinatiegroep). This change adds `inschrijvingDate`, `volgnummer` and `locationId` (the inschrijving's own vestiging, a `$ref Vestiging` from the previous change in this stack) for the inschrijving side, `destinationSchoolId` alongside the existing `withdraw` transition and free-text `reason` for the uitschrijving side, and `leerjaar` per pupil so a combination group (`Groep 5/6`) is one Cohort with one leerjaar value per Enrolment rather than parsed out of the cohort's name. `leerjaar` is surfaced as a column on `CohortDetail`'s roster.

## Motivation
ParnasSys and ESIS both carry a leerjaar per pupil independent of the group/cohort itself, specifically to support combinatiegroepen (`ParnasSys`: "combinatiegroep 3/4 ... met een leerjaar per pupil"; `ESIS`: "combinatiegroep 3-4 carries onderwijssoorten BO-03 and BO-04"). `RolloverService.php` in this repo currently parses the leerjaar digit out of the Cohort's free-text `name` — which breaks for exactly the combination-group case DUO's own rule anticipates (row 1.4). Inschrijving date/volgnummer/vestiging and uitschrijving destination school are both explicit DUO/ROD fields (`legal-po-2026-09-25.md`) that today have no home on `Enrolment` at all.

## Affected Projects
- [x] Project: `learniq` — `lib/Settings/learniq_register.json` (`Enrolment` gains five additive nullable properties); `src/manifest.d/learning.json` (`CohortDetail`'s roster gains a `leerjaar` column).

## Scope

### In Scope
- `Enrolment.inschrijvingDate` (date, nullable), `Enrolment.volgnummer` (integer, nullable), `Enrolment.locationId` ($ref `Vestiging`, nullable) — the inschrijving's own vestiging, independent of any `Cohort.locationId` the pupil is later grouped into.
- `Enrolment.destinationSchoolId` ($ref `School`, nullable) — captured alongside the existing `withdraw` transition and `reason` field.
- `Enrolment.leerjaar` (integer, nullable, 1 to 8) — per pupil, so `Groep 5/6` is one `Cohort` with `leerjaar` 5 for some enrolments and 6 for others.
- A `leerjaar` column on `CohortDetail`'s existing `coh-enrol` roster widget (`src/manifest.d/learning.json`).
- A register-JSON unit test for the five new properties' shape.

### Out of Scope
- Changing `RolloverService.php`'s cohort-name leerjaar parsing (a code change; tracked separately, not this config-only change).
- ROD wire transmission of any of these fields (integriq's adapter territory, D3).
- Retrofitting `leerjaar` onto historical Enrolment rows (additive/nullable; existing rows read `null` until backfilled).

## Approach
Five additive, nullable properties on the existing `Enrolment` schema plus one manifest column. Declarative only (ADR-031) — no PHP, no lifecycle change.

## New Dependencies
None. Depends on this lane's own `school-and-location-records` change (stacked branch) for the `Vestiging`/`School` schemas `locationId`/`destinationSchoolId` reference.

## Impact
- `lib/Settings/learniq_register.json`: five additive properties on `Enrolment`; register version bump.
- `src/manifest.d/learning.json`: one new column on `CohortDetail`'s roster widget.
- `tests/Unit/Settings/EnrolmentStatutoryFieldsRegisterTest.php`: new.

## Cross-Project Dependencies
None beyond this lane's own stacked `school-and-location-records` branch.

## Rollback Strategy
Revert the register-JSON and manifest-JSON diffs; both are additive/declarative, no migration to unwind.

## Open Questions
None.
