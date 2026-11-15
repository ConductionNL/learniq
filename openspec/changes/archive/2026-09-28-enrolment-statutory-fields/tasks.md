# Tasks: enrolment-statutory-fields

## Implementation Tasks

### Task 1: Add inschrijving/uitschrijving/leerjaar properties to Enrolment
- **spec_ref**: `openspec/changes/enrolment-statutory-fields/specs/enrolment/spec.md#requirement-enrolment-carries-inschrijving-date-volgnummer-and-its-own-vestiging`, `#requirement-enrolment-carries-a-destination-school-on-withdrawal`, `#requirement-enrolment-carries-leerjaar-per-pupil-independent-of-the-cohort-name`
- **files**: `lib/Settings/learniq_register.json` (`Enrolment.properties`: `inschrijvingDate`, `volgnummer`, `locationId` ($ref Vestiging), `destinationSchoolId` ($ref School), `leerjaar`; register version bump; two new Enrolment seeds on the "Groep 5/6" Cohort seed)
- **acceptance_criteria**:
  - GIVEN `Enrolment` WHEN read THEN it declares all five new properties, each nullable, none in `required`
  - GIVEN `leerjaar` WHEN read THEN it is an integer with `minimum: 1`, `maximum: 8`
  - GIVEN the seed data WHEN read THEN two Enrolment seeds reference the "Groep 5/6" Cohort seed with `leerjaar` 5 and 6 respectively
- [x] Implement
- [x] Test

### Task 2: Surface leerjaar on CohortDetail's roster
- **spec_ref**: `openspec/changes/enrolment-statutory-fields/specs/enrolment/spec.md#requirement-cohortdetails-roster-surfaces-leerjaar`
- **files**: `src/manifest.d/learning.json` (`CohortDetail.config.widgets[coh-enrol].content.columns`: add a `leerjaar` column)
- **acceptance_criteria**:
  - GIVEN `CohortDetail`'s `coh-enrol` widget WHEN read THEN its `columns` array includes a `leerjaar` entry
  - GIVEN `npm run check:manifest` WHEN run THEN it passes
- [x] Implement
- [x] Test

### Task 3: Register-JSON unit tests
- **spec_ref**: all requirements in `specs/enrolment/spec.md` above
- **files**: `tests/Unit/Settings/EnrolmentStatutoryFieldsRegisterTest.php` (new)
- **acceptance_criteria**:
  - GIVEN the test suite WHEN run THEN it asserts the five new properties' shape, the `leerjaar` bounds, and the seed fixtures' independent leerjaar values on the shared combination-group Cohort
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate --changes enrolment-statutory-fields --strict` passes
- [x] `npm run check:manifest`, `npm run check:register`, `npm run check:json-strict` pass
- [x] `vendor/bin/phpunit --filter EnrolmentStatutoryFieldsRegisterTest` passes

## Tests (company-wide ADR-009)
- `tests/Unit/Settings/EnrolmentStatutoryFieldsRegisterTest.php` covers schema shape and seed fixtures.
- N/A — no new or changed API endpoints.
- N/A — no custom Vue component; manifest-declarative column only.

## Documentation (company-wide ADR-010)
- N/A — no user-facing feature doc beyond the property/column labels themselves.

## i18n (company-wide ADR-005)
- New property titles ("Inschrijving Date", "Volgnummer", "Leerjaar", "Destination School ID") and the "Leerjaar" column label get en/nl catalogue entries per ADR-007/025.

## Compliance
- `openspec validate --change enrolment-statutory-fields --strict` passes before this change is marked ready for apply.
- Diff is confined to `lib/Settings/learniq_register.json`, `src/manifest.d/learning.json`, `l10n/*.json`, and the new test file (ADR-031, no PHP/Vue behaviour code).
