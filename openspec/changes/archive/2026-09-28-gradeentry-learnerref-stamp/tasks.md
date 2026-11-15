# Tasks: gradeentry-learnerref-stamp

## Implementation Tasks

### Task 1: Add LearnerRefResolver
- **spec_ref**: `openspec/changes/gradeentry-learnerref-stamp/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref`
- **files**: `lib/Service/LearnerRefResolver.php`, `tests/Unit/Service/LearnerRefResolverTest.php`
- **acceptance_criteria**:
  - GIVEN a profile with `ncUserId: "pupil-1"` WHEN resolved THEN its UUID is returned
  - GIVEN a merged-away and a surviving profile for one user WHEN resolved THEN the survivor wins
  - GIVEN no profile WHEN resolved THEN null
  - The lookup nests register/schema under `filters` and filters on `ncUserId`
- [x] Implement
- [x] Test

### Task 2: Add GradeEntryLearnerRefStamp and register it
- **spec_ref**: `openspec/changes/gradeentry-learnerref-stamp/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref`
- **files**: `lib/Listener/GradeEntryLearnerRefStamp.php`, `lib/AppInfo/Registrar/IntegrityListenerRegistrar.php`, `tests/Unit/Listener/GradeEntryLearnerRefStampTest.php`
- **acceptance_criteria**:
  - GIVEN a create without `learnerRef` WHEN stamped THEN the derived UUID is set
  - GIVEN a forged `learnerRef` WHEN stamped THEN it is replaced
  - GIVEN no profile WHEN stamped THEN `learnerRef` is null and the write is not stopped
  - GIVEN an update whose lookup throws WHEN stamped THEN the stored value is kept
  - GIVEN another schema WHEN the event fires THEN nothing is modified
- [x] Implement
- [x] Test

### Task 3: Add the BackfillGradeEntryLearnerRef repair step
- **spec_ref**: `openspec/changes/gradeentry-learnerref-stamp/specs/grading/spec.md#requirement-existing-gradeentries-are-back-filled-once`
- **files**: `lib/Repair/BackfillGradeEntryLearnerRef.php`, `appinfo/info.xml`, `tests/Unit/Repair/BackfillGradeEntryLearnerRefTest.php`
- **acceptance_criteria**:
  - GIVEN unstamped, stamped and profile-less rows WHEN the step runs THEN only the unstamped row with a profile is saved
  - GIVEN the step ran once WHEN it runs again THEN nothing is saved
- [x] Implement
- [x] Test

## Quality checklist

- New classes covered by PHPUnit (`tests/Unit/`), with a fake that drops undeclared filter keys like OpenRegister does
- No new API endpoint, no UI change, no new user-facing string
- `openspec validate gradeentry-learnerref-stamp` passes
