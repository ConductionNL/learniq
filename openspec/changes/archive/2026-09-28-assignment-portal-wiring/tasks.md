# Tasks: assignment-portal-wiring

## Implementation Tasks

### Task 1: Add LearnerProfileLookup
- **spec_ref**: `openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to`
- **files**: `lib/Service/Portal/LearnerProfileLookup.php`, `tests/Unit/Service/Portal/LearnerProfileLookupTest.php`
- **acceptance_criteria**:
  - GIVEN an active profile WHEN `byRef` THEN its row with `id` is returned
  - GIVEN a merged, deleted or missing profile WHEN `byRef` THEN null
  - GIVEN a merged and a surviving profile for one user WHEN `refForUser` THEN the survivor's uuid
  - The lookup nests `register` and `schema` under `filters` and filters on `ncUserId`
- [x] Implement
- [x] Test

### Task 2: Add SubmissionOwnerStamp and register it
- **spec_ref**: `openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to`
- **files**: `lib/Listener/SubmissionOwnerStamp.php`, `lib/AppInfo/Registrar/IntegrityListenerRegistrar.php`, `tests/Unit/Listener/SubmissionOwnerStampTest.php`
- **acceptance_criteria**:
  - GIVEN a stubbed portal create WHEN stamped THEN `learnerIds`, `learnerRefs` and `tenant_id` come from the profile and the Assignment
  - GIVEN an unknown or merged profile, a missing Assignment or a tenant mismatch WHEN a portal create THEN refused
  - GIVEN a signed-in create without `learnerIds` WHEN stamped THEN refused
  - GIVEN a forged `learnerRef` on an app create WHEN stamped THEN replaced by the derived one
  - GIVEN an update whose lookup throws and whose learners did not change WHEN stamped THEN the stored ref is kept
  - The stamp is registered for create and update (asserted from the registrar)
- [x] Implement
- [x] Test

### Task 3: Submission schema: learnerRef, required, versions, seeds, catalogue
- **spec_ref**: `openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to`
- **files**: `lib/Settings/learniq_register.json`, `lib/Settings/learniq_mock_register.json`, `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`
- **acceptance_criteria**:
  - Submission declares a nullable uuid `learnerRef`; `required` is `[assignmentId]`
  - Submission `version` and register `info.version` are bumped
  - The three Submission seeds carry `learnerRef`
  - `npm run check:schema-l10n` does not grow
- [x] Implement
- [x] Test

### Task 4: Wire the hand-in in the portal contribution
- **spec_ref**: `openspec/changes/assignment-portal-wiring/specs/portal-contribution/spec.md#requirement-a-pupil-hands-in-work-through-the-portal-with-a-real-file-req-pcon-007`
- **files**: `lib/Portal/PortalContributionProvider.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - `createSubmission` declares `fieldConfigs.attachmentRefs` (file, multiple, accept, maxSizeMb 20) and `minTrust: low`
  - `createSubmission` and `studentSubmissions` scope by `learnerRef`
- [x] Implement
- [x] Test

### Task 5: Document the portal hand-in
- **spec_ref**: `openspec/changes/assignment-portal-wiring/specs/portal-contribution/spec.md#requirement-a-pupil-hands-in-work-through-the-portal-with-a-real-file-req-pcon-007`
- **files**: `docs/user-guide/user/04-assignments.md`
- **acceptance_criteria**:
  - The assignments guide says what a pupil can do in the portal today and what still needs the app
- [x] Implement

## Quality checklist

- New classes covered by PHPUnit (`tests/Unit/`)
- No new API endpoint, no learniq UI change
- New schema text has English and Dutch catalogue entries
- `openspec validate assignment-portal-wiring` passes
