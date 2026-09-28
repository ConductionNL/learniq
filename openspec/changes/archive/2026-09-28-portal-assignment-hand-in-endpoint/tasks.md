# Tasks: portal-assignment-hand-in-endpoint

Kind: code. Follow-up named in `assignment-portal-wiring` (learniq #1068); pattern of
`assessment-portal-endpoints` (learniq #1096); decision D15.

## Implementation Tasks

### Task 1: Name the guard's refusal texts
- **spec_ref**: `openspec/changes/portal-assignment-hand-in-endpoint/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint`
- **files**: `lib/Lifecycle/SubmissionWindowGuard.php`
- **acceptance_criteria**:
  - GIVEN the guard WHEN it denies THEN the text is one of its public constants; `SubmissionWindowGuardTest` stays green unchanged
- [x] Implement
- [x] Test

### Task 2: The hand-in service
- **spec_ref**: `openspec/changes/portal-assignment-hand-in-endpoint/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint`
- **files**: `lib/Service/Portal/PortalSubmissionHandIn.php`, `lib/Service/Portal/PortalMessages.php`, `tests/Unit/Service/Portal/PortalSubmissionHandInTest.php`
- **acceptance_criteria**:
  - GIVEN a draft inside the window WHEN handed in THEN `submit` runs as the pupil
  - GIVEN a passed deadline WHEN late work is accepted THEN `submitLate`; otherwise 422 and nothing fires
  - GIVEN another pupil's or a non-draft submission WHEN handed in THEN 404 or 409 and nothing fires
- [x] Implement
- [x] Test

### Task 3: The receiver endpoint
- **spec_ref**: `openspec/changes/portal-assignment-hand-in-endpoint/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint`
- **files**: `lib/Controller/PortalSubmissionController.php`, `appinfo/routes.php`, `tests/Unit/Controller/PortalSubmissionControllerTest.php`
- **acceptance_criteria**:
  - GIVEN no or a bad assertion, another audience, no learnerRef, no account WHEN called THEN 401 (throttled), 403, 403, 403 `not_available`
- [x] Implement
- [x] Test

### Task 4: The handIn action in the student manifest
- **spec_ref**: `openspec/changes/portal-assignment-hand-in-endpoint/specs/portal-contribution/spec.md#requirement-a-pupil-hands-in-a-draft-submission-from-the-portal-req-pcon-009`
- **files**: `lib/Portal/PortalContributionProvider.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`, `docs/user-guide/user/04-assignments.md`
- **acceptance_criteria**:
  - GIVEN a student subject WHEN the manifest is built THEN `handIn` and `studentSubmissions.rowActions` are declared as specified
- [x] Implement
- [x] Test

## Verification
- [x] `openspec validate portal-assignment-hand-in-endpoint`, diff-scoped checks, `composer check:strict` once, `npm run lint`, `npm run format`, hydra gates

## Quality checklist

- No schema change, so no register bump and no seed data.
- New pupil messages get Dutch catalogue values, then `npm run l10n:build`.
