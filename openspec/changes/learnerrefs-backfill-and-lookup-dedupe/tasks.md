# Tasks: learnerrefs-backfill-and-lookup-dedupe

## Implementation Tasks

### Task 1: LearnerRefResolver answers the portal's lookups
- **spec_ref**: `openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile`
- **files**: `lib/Service/LearnerRefResolver.php`, `lib/Service/Portal/LearnerProfileLookup.php`, `tests/Unit/Service/LearnerRefResolverByRefTest.php`
- **acceptance_criteria**:
  - `byRef()` and `resolveAcrossTenants()` pass the cases that tested LearnerProfileLookup; only the across-tenants lookup drops tenant scoping; the facade delegates
- [x] Implement
- [x] Test

### Task 2: The callers use the resolver
- **spec_ref**: `openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/grading/spec.md#scenario-a-portal-stamp-and-a-teacher-side-stamp-find-the-same-profile`
- **files**: `lib/Listener/SubmissionOwnerStamp.php`, `lib/Service/AssessmentResultPortalStamp.php`, `lib/Service/Portal/PortalLearnerResolver.php`, their tests
- **acceptance_criteria**:
  - no caller of LearnerProfileLookup is left in `lib/`; the portal callers use the across-tenants lookup
- [x] Implement
- [x] Test

### Task 3: Existing submissions are back-filled on upgrade
- **spec_ref**: `openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/assignments/spec.md#requirement-existing-submissions-are-back-filled`
- **files**: `lib/Repair/BackfillSubmissionLearnerRefs.php`, `appinfo/info.xml`, `tests/Unit/Repair/BackfillSubmissionLearnerRefsTest.php`
- **acceptance_criteria**:
  - group, partial, stale and done rows handled; a second run saves nothing; a failed lookup saves nothing; the step is registered after InitializeSettings
- [x] Implement
- [x] Test

## Verification
- [ ] `openspec validate learnerrefs-backfill-and-lookup-dedupe` passes
- [ ] `composer check:strict`, `npm run lint`, hydra gates, each with its exit code in the PR body

## Quality checklist

- No new endpoint, string or schema
