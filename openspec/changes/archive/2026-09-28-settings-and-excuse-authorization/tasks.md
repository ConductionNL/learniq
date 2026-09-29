# Tasks: settings-and-excuse-authorization

## Implementation Tasks

### Task 1: LearniqSettings authorization block
- **spec_ref**: `openspec/changes/settings-and-excuse-authorization/specs/nextcloud-app/spec.md#requirement-only-administration-managers-and-admins-change-the-organisations-segment`
- **files**: `lib/Settings/learniq_register.json`, `tests/Unit/Settings/SegmentFeatureFlagsRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN LearniqSettings.authorization is read THEN staff read, administration-managers write, no delete, nothing for learners or guardians
- [x] Implement
- [x] Test

### Task 2: ExcuseRequest required list and owner stamp
- **spec_ref**: `openspec/changes/settings-and-excuse-authorization/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it`
- **files**: `lib/Settings/learniq_register.json`, `lib/Listener/ExcuseRequestOwnerStamp.php`, `lib/AppInfo/Registrar/IntegrityListenerRegistrar.php`, `tests/Unit/Listener/ExcuseRequestOwnerStampTest.php`, `tests/Unit/Settings/ExcuseRequestRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN a portal report by a pupil or a listed guardian WHEN created THEN the owner fields are stamped
  - GIVEN a guardian not on the profile, an unknown pupil or a failed lookup WHEN created THEN the write is refused
  - GIVEN a staff write without the pupil, a submitter or the school WHEN saved THEN it is refused
- [x] Implement
- [x] Test

### Task 3: Versions, docs, verification, PR
- **spec_ref**: `openspec/changes/settings-and-excuse-authorization/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it`
- **files**: `lib/Settings/learniq_register.json`, `docs/user-guide/user/05-attendance.md`
- **acceptance_criteria**:
  - GIVEN both schemas WHEN shipped THEN each is at 0.3.0 and info.version moved
  - GIVEN the full suite WHEN run THEN no failure outside development's known set
- [x] Implement
- [x] Test

## Quality checklist

- PHPUnit: every stamp path and refusal, the register pins
- No new endpoint: no Newman
- No screen in learniq: no Playwright
- Docs: attendance user guide, portal reports
- i18n: no new schema strings; the refusal messages follow SubmissionOwnerStamp (plain English, as returned by the write)
- `openspec validate` passes
