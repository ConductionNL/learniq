# Tasks: care-and-support-index

## Implementation Tasks

### Task 1: LearningPlan gains the six-week clock and outflow-bandwidth fields
- **spec_ref**: `openspec/changes/care-and-support-index/specs/learning-plan/spec.md#requirement-learningplan-declares-a-materialised-six-week-activation-clock`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN a `LearningPlan` in `draft` with `sixWeekDeadline` in the past WHEN `isOverdueForActivation` is evaluated THEN it is `true`
  - GIVEN the same plan has since activated WHEN evaluated THEN `isOverdueForActivation` is `false`
  - GIVEN `outflowBandwidth` is set WHEN a `trackedGrowth[]` entry is appended THEN it persists with `recordedAt`/`vaardigheidsscore`
- [x] Implement
- [x] Test

### Task 2: Add ObservationInstrument and Trajectory/TrajectoryStatusUpdate schemas
- **spec_ref**: `openspec/changes/care-and-support-index/specs/learning-plan/spec.md#requirement-observationinstrument-records-structured-kleuter-leerlijn-observations`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN an `ObservationInstrument` is created with one entry per leerlijn THEN all six persist
  - GIVEN a `Trajectory` in `referral` WHEN it transitions through the full declared lifecycle THEN each transition succeeds in order
  - GIVEN a `TrajectoryStatusUpdate` WHEN inspected THEN `appendOnly` is `true`
- [x] Implement
- [x] Test

### Task 3: Seed mock data for the new/extended shapes
- **spec_ref**: `openspec/changes/care-and-support-index/design.md#seed-data`
- **files**: `lib/Settings/learniq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN demo data loads THEN 3 `ObservationInstrument` and 3 `Trajectory` objects exist, and one `LearningPlan` mock row carries the new OPP residual fields
- [x] Implement
- [x] Test

### Task 4: Add LearningPlans columns, a CareTeamOverviewMenu query-preset deep-link, and the two new schemas' manifest pages
- **spec_ref**: `openspec/changes/care-and-support-index/specs/learning-plan/spec.md#requirement-a-care-team-lens-surfaces-pupils-in-support-without-a-duplicate-index-page`
- **files**: `src/manifest.d/learning.json`
- **acceptance_criteria**:
  - GIVEN the manifest is built WHEN `LearningPlans` is inspected THEN its columns include the learner, kind, coordinatorId, lifecycle, and nextReviewAt
  - GIVEN the manifest is built WHEN `CareTeamOverviewMenu` is inspected THEN it routes to `LearningPlans` with `query: {lifecycle: "active"}` and gate-68 duplicate-index-pages reports 0 findings
  - GIVEN the manifest is built WHEN navigating to the new pages THEN `ObservationInstrument`/`Trajectory`/`TrajectoryStatusUpdate` index+detail pages exist
- [x] Implement
- [x] Test

### Task 5: Add CareAndSupportIndexRegisterTest
- **spec_ref**: `openspec/changes/care-and-support-index/specs/learning-plan/spec.md#requirement-trajectory-tracks-a-bovenschoolse-voorziening-placement-lifecycle-with-an-append-only-status-log`
- **files**: `tests/Unit/Settings/CareAndSupportIndexRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN parsed THEN all new schema blocks, the calculation expression, the lifecycle transition table, and the appendOnly flag are well-formed and match the spec
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed schema shapes covered by PHPUnit register tests (`tests/Unit/Settings/`)
- No new API endpoints — OpenRegister's generic object API serves every new schema
- UI changes are declarative manifest pages only, no bespoke Vue component to browser-test
- All tests pass: `vendor/bin/phpunit --filter CareAndSupportIndex`
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for new manifest titles/labels and
  enum display labels (ADR-007); no em-dashes, no Title Case
- `openspec validate --change care-and-support-index` passes
