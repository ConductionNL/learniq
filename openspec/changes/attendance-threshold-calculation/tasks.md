# Tasks: attendance-threshold-calculation

## Implementation Tasks

### Task 1: Declare unexcusedLesuren and isThresholdCrossed calculations
- **spec_ref**: `openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#requirement-threshold-crossing-is-a-declared-calculation-trigger`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN `AttendanceThreshold.x-openregister-aggregations` WHEN read THEN it
    declares an aggregate counting `attendance-record` rows where
    `cohortId == @self.cohortId` and `status == absent-unexcused`
  - GIVEN `AttendanceThreshold.x-openregister-calculations` WHEN read THEN
    `unexcusedLesuren` and `isThresholdCrossed` (`unexcusedLesuren >= limit`)
    are both declared, `materialise: true`
- [x] Implement
- [x] Test

### Task 2: Add the check-threshold transition and its transient input properties
- **spec_ref**: `openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-records-a-real-per-learner-crossing-and-creates-an-attendanceflag`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN `AttendanceThreshold.x-openregister-lifecycle.transitions` WHEN read
    THEN `check-threshold` (from: active, to: active, requires:
    `OCA\Learniq\Lifecycle\AttendanceThresholdCrossingGuard`) is declared with
    `checkedLearnerId`/`checkedMetricValue` required inputs
  - GIVEN `AttendanceThreshold.properties` WHEN read THEN it declares
    `checkedLearnerId`/`checkedMetricValue`/`checkedWindowStart`/
    `checkedWindowEnd`/`checkedBreachingRecordIds`, all nullable
- [x] Implement
- [x] Test

### Task 3: Add AttendanceThresholdCrossingGuard
- **spec_ref**: `openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-below-the-limit-is-refused`
- **files**: `lib/Lifecycle/AttendanceThresholdCrossingGuard.php`
- **acceptance_criteria**:
  - GIVEN `checkedMetricValue >= limit` and `checkedLearnerId` set WHEN
    `check()` runs THEN it returns true
  - GIVEN `checkedMetricValue < limit` OR `checkedLearnerId` empty WHEN
    `check()` runs THEN it returns false
- [x] Implement
- [x] Test

### Task 4: Correct AttendanceFlagCreationHandler
- **spec_ref**: `openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-records-a-real-per-learner-crossing-and-creates-an-attendanceflag`
- **files**: `lib/Lifecycle/AttendanceFlagCreationHandler.php`
- **acceptance_criteria**:
  - GIVEN an `ObjectTransitionedEvent` with `action: check-threshold` on an
    `attendance-threshold` WHEN `handle()` runs THEN it creates an
    `AttendanceFlag` reading `checkedLearnerId`/`checkedMetricValue`/
    `checkedWindowStart`/`checkedWindowEnd`/`checkedBreachingRecordIds` from
    `$event->getObject()`, never from a `getContext()` call
- [x] Implement
- [x] Test

### Task 5: Unit tests with `createMock()` doubles
- **spec_ref**: `openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#requirement-threshold-crossing-is-a-declared-calculation-trigger`
- **files**: `tests/Unit/Lifecycle/AttendanceThresholdCrossingGuardTest.php`,
  `tests/Unit/Lifecycle/AttendanceFlagCreationHandlerTest.php`,
  `tests/Unit/Settings/AttendanceThresholdRegisterTest.php`
- **acceptance_criteria**:
  - Every scenario in the spec has a corresponding test method
  - A test proves a crossing (guard passes) results in an `AttendanceFlag`
    being created — the brief's explicit acceptance bar
  - Doubles use `createMock()` only — never `addMethods()`
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) —
  `AttendanceThresholdCrossingGuardTest`, `AttendanceFlagCreationHandlerTest`,
  `AttendanceThresholdRegisterTest`
- [ ] Newman/Postman tests for new/changed API endpoints — N/A, no API changed
- [ ] Browser tests (Playwright MCP) for UI changes — N/A, no UI changed
- [x] All tests pass (`vendor/bin/phpunit --filter 'AttendanceThreshold|AttendanceFlagCreationHandler'`)

## Documentation (company-wide ADR-010)
- [ ] Feature documentation updated in `docs/` — N/A, no new user-facing surface
- [ ] Screenshot captured and committed to `docs/images/` — N/A, no visual change

## i18n (company-wide ADR-005)
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added — N/A,
  the notification subject already exists and is unchanged
