---
kind: config
---

# Proposal: school-and-location-records

## Summary
Learniq's register has no `School` or `Location` schema at all — `LearnerProfile.schoolId` is a free-text string with no BRIN, no vestiging, no onderwijslocatie (round-1 finding 1.1, MUST tier, corpus row `../parnassys/round1/documented-column.md` rows 1.1–1.14 and `../gibbon/round1/pages/FormGroup.md`; `../po-las/round1/documented-columns.md` for the PO/LAS shape). This change adds `School` (BRIN, name, pedagogical concept) and `Location` (vestigingscode, onderwijslocatiecode, address) as OpenRegister schemas, wires `Cohort.locationId` so every groep names the one location it belongs to (DUO's "one groep per location" rule, `../recon/legal-po-2026-09-25.md`), and adds an index+detail page pair for each under People (rung 4), following the existing Enrolment/Credential index+detail convention in `src/manifest.d/people.json`. Per decision D2 (`decisions.md`), School is data with no board/bestuur switcher this round.

## Motivation
ParnasSys and ESIS both model school structure as bestuur/school/vestiging with the vestiging(location) code validated against RIO recognition and named on ROD signals 029/032 (`../parnassys/round1/documented-column.md` row 1.1). Gibbon's `FormGroup` (`../gibbon/round1/pages/FormGroup.md`) shows the same groep-belongs-to-one-structural-unit shape at the classroom level. Learniq has neither: `grep` on the register finds no `School` or `Location` schema, and `Cohort` has no location reference at all — so today there is no way to declare which vestiging a groep runs at, which is a DUO statutory requirement (`legal-po-2026-09-25.md`: "one groep per location"), and no way to declare a school's BRIN or pedagogical concept (montessori/dalton/jenaplan/freinet/vrijeschool all change which statutory reporting profile applies). This is the foundational record every later school-structure change in this round (enrolment vestiging/leerjaar fields, funding checks, PO schooladvies) reads from.

## Affected Projects
- [x] Project: `learniq` — `lib/Settings/learniq_register.json` gains `School` and `Location` schemas plus an additive `Cohort.locationId`; `src/manifest.d/people.json` gains four pages (Schools index/detail, Locations index/detail) and two People-menu entries.

## Scope

### In Scope
- `School` schema: `brin` (DUO BRIN-nummer, pattern-validated), `name`, `pedagogicalConcept` (enum: regular, montessori, dalton, jenaplan, freinet, vrijeschool, other; default regular).
- `Location` schema: `schoolId` ($ref School), `vestigingscode`, `onderwijslocatiecode` (nullable — a vestiging can have more than one onderwijslocatie per `legal-po-2026-09-25.md`), `name`, `street`/`postalCode`/`city`.
- `Cohort.locationId` — additive, nullable $ref Location, so a groep declares the one location it belongs to.
- Index + detail manifest pages for both schemas under the existing People domain (`GroupPeople` menu), matching the Enrolment/Credential pattern exactly (data widget + related widget + audit sidebar tab, no custom Vue view).
- Register-JSON unit tests for both schemas' shape and the `Cohort.locationId` addition, plus `npm run check:manifest` / `check:register` coverage of the new pages.

### Out of Scope
- Any bestuur/multi-school switcher (D2 — deferred until a multi-bestuur pilot customer exists; rows 14.5/P-new-14).
- `Enrolment`'s vestigingscode/leerjaar/inschrijving-date fields (next change, `enrolment-statutory-fields`, stacked on this branch).
- BRIN/RIO live validation against DUO (no adapter exists; that is integriq's territory per D3, not this change).
- Migrating the existing free-text `LearnerProfile.schoolId` to a `$ref School` — left untouched to avoid a breaking rename mid-round; a future change can retire it once every consumer reads `Cohort.locationId`/`School` instead.

## Approach
Two new plain resource-metadata schemas (no lifecycle — same shape as `Room`, per `school-structure` spec's "Room is persisted as a bookable resource" precedent), one additive nullable relation on `Cohort`, and four manifest pages cloned from the Enrolment/Credential index+detail pattern already in `src/manifest.d/people.json`. Declarative only (ADR-031) — no PHP.

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json`: two new schemas (`School`, `Location`), one additive property (`Cohort.locationId`), register version bump.
- `src/manifest.d/people.json`: two new menu entries, four new pages (Schools, SchoolDetail, Locations, LocationDetail).
- `tests/Unit/Settings/SchoolAndLocationRegisterTest.php`: new.

## Cross-Project Dependencies
None. `enrolment-statutory-fields` (next in this lane) stacks on this branch to read `Location.vestigingscode`.

## Risks

### Risk 1: `Cohort.locationId` left unset on existing rows
**Severity:** Low — **Mitigation:** Additive and nullable; existing Cohorts keep working with no location declared until a coordinator backfills one. No lifecycle guard blocks activation on it this round.

## Rollback Strategy
Revert the register-JSON and manifest-JSON diffs; both are declarative-only, no data migration or PHP to unwind. Any `Location`/`School` objects already created by a school remain valid OpenRegister objects (orphaned but harmless) if the schemas are later removed.

## Open Questions
None — D2 in `decisions.md` already resolved the board-switcher question for this round.
