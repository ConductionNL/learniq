## Implementation Tasks

### Task 1: Add beeldmateriaal consent properties to `LearnerProfile`
- **spec_ref**: `openspec/changes/portal-contribution-guardian-audiences/specs/avg-verwerkingsregister/spec.md#requirement-learnerprofile-records-per-purpose-beeldmateriaal-consent`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN `LearnerProfile.properties.beeldmateriaalConsent` THEN it is an object with nullable-boolean `website`/`socialMedia`/`schoolgids`/`classPhoto`/`video`, each default null
  - GIVEN `LearnerProfile.properties.beeldmateriaalConsentReviewDueAt` THEN it is a nullable date, default null
  - GIVEN `LearnerProfile.required` THEN neither new property is added to it
- [x] Implement
- [x] Test

### Task 2: Add the `parentChildren` collection and `groupByField` to the four existing parent collections
- **spec_ref**: `openspec/changes/portal-contribution-guardian-audiences/specs/portal-contribution/spec.md#requirement-the-parent-audience-exposes-per-child-and-per-guardian-group-directory-data-req-pcon-006`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN `parentContribution()` THEN it declares a `parentChildren` collection with `schema: learner-profile`, `scopeField: guardianRefs`, no `via`, and `fields` including `givenName`/`familyName`/`guardianRefs`/`beeldmateriaalConsent`/`beeldmateriaalConsentReviewDueAt`
  - GIVEN `parentGrades`/`parentAttendance`/`parentExcuseRequests`/`parentReportCards` THEN each declares `groupByField: 'learnerRef'`
- [x] Implement
- [x] Test

### Task 3: Re-enable `parentActions()` with `createExcuseRequest`
- **spec_ref**: `openspec/changes/portal-contribution-guardian-audiences/specs/portal-contribution/spec.md#requirement-the-parent-audience-can-report-a-childs-absence-validated-against-the-callers-own-children-req-pcon-007`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN `parentContribution()['actions']` THEN it contains exactly one action, `createExcuseRequest`, with `scopeField: submittedByRef`, `scopeClaim: guardianRef`, `via` byte-identical to the read collections' `$childJoin`, `minTrust: substantial`, and `fields` including `learnerRef`
  - GIVEN the same action THEN `scopeField` is never `learnerRef`
- [x] Implement
- [x] Test

### Task 4: Belt-and-braces unit tests and drift pins
- **spec_ref**: `openspec/changes/portal-contribution-guardian-audiences/specs/portal-contribution/spec.md`
- **files**: `tests/Unit/Portal/PortalContributionProviderTest.php`, `tests/Unit/Settings/GuardianAudienceRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the provider test suite THEN it asserts `parentChildren`'s exact shape, the four collections' `groupByField`, and `createExcuseRequest`'s exact key set including the `scopeField`/`via` drift pin named in Task 3
  - GIVEN the register test THEN it asserts the two new `LearnerProfile` properties' exact shape
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate portal-contribution-guardian-audiences --strict` passes
- [x] Manual review against acceptance criteria

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests (`tests/Unit/Portal/PortalContributionProviderTest.php`, `tests/Unit/Settings/GuardianAudienceRegisterTest.php`)
- [x] N/A — no new API endpoint, no UI change; the portal itself is portaliq's per ADR-046

## Documentation (company-wide ADR-010)
- [x] N/A — declarative provider/schema change; no learniq-owned user-facing surface

## i18n (company-wide ADR-005)
- [x] N/A — manifest labels are portal-side data (English source), same posture `portal-contribution`'s own tasks.md already documents
