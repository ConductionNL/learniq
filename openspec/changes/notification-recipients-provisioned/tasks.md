# Tasks: notification-recipients-provisioned

## Implementation Tasks

### Task 1: Map every role word onto a declared, reading group
- **spec_ref**: `openspec/changes/notification-recipients-provisioned/specs/scholiq-notifications/spec.md#requirement-group-recipients-must-be-groups-an-install-provisions-and-that-can-read-the-object`
- **files**: `lib/Settings/learniq_register.json`, `tests/Unit/Register/NotificationRecipientGroupsAreDeclaredTest.php`, `tests/Unit/Settings/AssessmentItemPoolsRegisterTest.php`, `tests/Unit/Settings/AttendanceThresholdRegisterTest.php`
- **acceptance_criteria**:
  - the register test is red on development (26 undeclared group names) and green after
  - touched schema versions and `info.version` bumped
- [x] Test written first and red
- [x] Implement
- [x] Test green

## Verification
- [x] `openspec validate notification-recipients-provisioned` passes
- [x] `composer check:strict`, `npm run lint`, hydra gates, each with its exit code in the PR body
