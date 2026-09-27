# grading Specification

## ADDED Requirements

### Requirement: Every GradeEntry carries a server-stamped learnerRef

`GradeEntryLearnerRefStamp` MUST set `GradeEntry.learnerRef` on every create and update of a
`grade-entry` object, derived from `learnerId` through `LearnerRefResolver`: the UUID of the
`LearnerProfile` whose `ncUserId` equals `learnerId`, preferring a profile whose `mergedInto` is
empty. A `learnerRef` sent by the client MUST be ignored, so no caller can point a grade at another
learner's portal subject. When no profile matches, `learnerRef` MUST be null, so the grade stays
out of the portal (fail-closed). The stamp MUST NOT block the write. On update, when the lookup
fails with an error, the stored `learnerRef` MUST be kept.

#### Scenario: A grade created without learnerRef gets it stamped

<!-- @e2e exclude Server-side write listener with no DOM surface; covered by PHPUnit GradeEntryLearnerRefStampTest. -->

- **GIVEN** a LearnerProfile `lp-1` with `ncUserId: "pupil-1"`
- **WHEN** a `GradeEntry` is created with `learnerId: "pupil-1"` and no `learnerRef`
- **THEN** the stored entry carries `learnerRef: "lp-1"`

#### Scenario: A forged learnerRef is replaced by the derived one

<!-- @e2e exclude PHPUnit GradeEntryLearnerRefStampTest. -->

- **GIVEN** LearnerProfiles `lp-1` (`ncUserId: "pupil-1"`) and `lp-2` (`ncUserId: "pupil-2"`)
- **WHEN** a `GradeEntry` is created with `learnerId: "pupil-1"` and `learnerRef: "lp-2"`
- **THEN** the stored entry carries `learnerRef: "lp-1"`

#### Scenario: A learner without a profile stays out of the portal

<!-- @e2e exclude PHPUnit GradeEntryLearnerRefStampTest. -->

- **GIVEN** no LearnerProfile with `ncUserId: "pupil-9"`
- **WHEN** a `GradeEntry` is created with `learnerId: "pupil-9"` and a client-sent `learnerRef`
- **THEN** the write succeeds and the stored entry carries `learnerRef: null`

#### Scenario: The survivor of a merge wins over the merged-away profile

<!-- @e2e exclude PHPUnit LearnerRefResolverTest. -->

- **GIVEN** LearnerProfiles `lp-old` (`ncUserId: "pupil-1"`, `mergedInto: "lp-new"`) and `lp-new`
  (`ncUserId: "pupil-1"`, `mergedInto` empty)
- **WHEN** a `GradeEntry` is created with `learnerId: "pupil-1"`
- **THEN** the stored entry carries `learnerRef: "lp-new"`

#### Scenario: A failed lookup on update keeps the stored learnerRef

<!-- @e2e exclude PHPUnit GradeEntryLearnerRefStampTest. -->

- **GIVEN** a stored `GradeEntry` with `learnerId: "pupil-1"` and `learnerRef: "lp-1"`
- **WHEN** it is updated while the LearnerProfile lookup throws
- **THEN** the write succeeds and the entry keeps `learnerRef: "lp-1"`

### Requirement: Existing GradeEntries are back-filled once

The `BackfillGradeEntryLearnerRef` repair step MUST stamp `learnerRef` on every existing
`grade-entry` object that has a `learnerId` and no `learnerRef`, using the same resolver. It MUST
skip rows that already carry a `learnerRef` and rows whose learner has no profile, and it MUST be
safe to run again: a second run writes nothing new.

#### Scenario: The backfill stamps unstamped rows and skips the rest

<!-- @e2e exclude Repair step with no DOM surface; covered by PHPUnit BackfillGradeEntryLearnerRefTest. -->

- **GIVEN** three GradeEntries: one for `pupil-1` without `learnerRef`, one for `pupil-1` with
  `learnerRef: "lp-1"`, and one for `pupil-9` who has no profile
- **WHEN** the repair step runs
- **THEN** only the first entry is saved, with `learnerRef: "lp-1"`, and a second run saves nothing
