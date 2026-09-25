# Tasks: school-and-location-records

## Implementation Tasks

### Task 1: Add the `School` and `Location` schemas
- **spec_ref**: `openspec/changes/school-and-location-records/specs/school-structure/spec.md#requirement-school-and-location-are-persisted-as-openregister-records`, `#requirement-school-declares-a-brin-and-a-pedagogical-concept`, `#requirement-location-declares-vestigingscode-and-an-independent-onderwijslocatiecode`
- **files**: `lib/Settings/learniq_register.json` (new `School`, `Location` schemas under `components.schemas`; register `info.version` bump; seed data per design.md)
- **acceptance_criteria**:
  - GIVEN the register WHEN `School` is read THEN it declares `brin` (pattern-validated), `name`, `pedagogicalConcept` (enum, default `regular`), no lifecycle
  - GIVEN the register WHEN `Location` is read THEN it declares `schoolId` ($ref School), `vestigingscode` (required), `onderwijslocatiecode` (nullable, independent), `name`, `street`/`postalCode`/`city`
  - GIVEN the seed data WHEN read THEN two `School` and three `Location` seeds exist matching design.md's Seed Data section, including one Location with an independent `onderwijslocatiecode`
- [x] Implement
- [x] Test

### Task 2: Add `Cohort.locationId`
- **spec_ref**: `openspec/changes/school-and-location-records/specs/school-structure/spec.md#requirement-cohort-names-the-one-location-it-runs-at`
- **files**: `lib/Settings/learniq_register.json` (`Cohort.properties.locationId`, additive nullable $ref Location; backfill one Cohort seed)
- **acceptance_criteria**:
  - GIVEN `Cohort` WHEN read THEN it declares `locationId` (nullable, `$ref: Location`, default null), and `required` is unchanged
  - GIVEN a pre-existing Cohort seed WHEN read THEN one seed carries `locationId` pointing at Location seed 1, all others remain `null`
- [x] Implement
- [x] Test

### Task 3: Schools and Locations index+detail pages under People
- **spec_ref**: `openspec/changes/school-and-location-records/specs/school-structure/spec.md#requirement-frontend-is-declarative-for-school-and-location`
- **files**: `src/manifest.d/people.json` (two menu entries under `GroupPeople`; `Schools`/`SchoolDetail`/`Locations`/`LocationDetail` pages, cloned from the Enrolment/Credential index+detail shape: data widget + related widget + audit sidebar tab)
- **acceptance_criteria**:
  - GIVEN the manifest WHEN built THEN `Schools` and `Locations` appear as index pages under the People menu, each with a working detail route
  - GIVEN `SchoolDetail`/`LocationDetail` WHEN rendered THEN each shows a data widget, a related widget, and an audit-history sidebar tab, with no custom Vue view
  - GIVEN `npm run check:manifest` WHEN run THEN it passes with the four new pages present
- [x] Implement
- [x] Test

### Task 4: Register-JSON unit tests
- **spec_ref**: all requirements in `specs/school-structure/spec.md` above
- **files**: `tests/Unit/Settings/SchoolAndLocationRegisterTest.php` (new)
- **acceptance_criteria**:
  - GIVEN the test suite WHEN run THEN it asserts `School`/`Location` schema shape, the BRIN pattern, the independent `onderwijslocatiecode`, `Cohort.locationId`'s additive nullable shape, and the seed fixtures (per `GroepsplanRegisterTest`'s established shape-assertion pattern — this register runs outside this repo, so behaviour is not unit-testable, only declared shape is)
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate --change school-and-location-records --strict` passes
- [x] `npm run check:manifest`, `npm run check:register`, `npm run check:json-strict` pass
- [x] `vendor/bin/phpunit --filter SchoolAndLocationRegisterTest` passes

## Tests (company-wide ADR-009)
- `tests/Unit/Settings/SchoolAndLocationRegisterTest.php` covers schema shape, BRIN pattern, and seed fixtures.
- N/A — no new or changed API endpoints (OpenRegister's existing objects API serves the new schemas unmodified).
- N/A — no custom Vue component; manifest-declarative pages only (`check:manifest` is the UI-shape test).

## Documentation (company-wide ADR-010)
- N/A — no user-facing feature doc beyond the manifest page labels themselves (People → Schools / Locations); no docs/ page exists for school-structure sub-pages generally, matching Enrolment/Credential precedent.

## i18n (company-wide ADR-005)
- Menu/page labels ("Schools", "Locations", "Pedagogical concept") are English source-of-truth; NL labels added to `l10n/nl.json` alongside this change per ADR-007/025.

## Compliance
- `openspec validate --change school-and-location-records --strict` passes before this change is marked ready for apply.
- Diff is confined to `lib/Settings/learniq_register.json`, `src/manifest.d/people.json`, `l10n/nl.json`, and the new test file (ADR-031 — no PHP/Vue behaviour code).
