## ADDED Requirements

### Requirement: Every LvsResult names its pupil by LearnerProfile reference
`LvsResult` MUST declare `learnerRef`: a string, format uuid, `$ref` LearnerProfile, nullable and not required. `LvsResultLearnerRefStamp` MUST set it on every create and update of an `lvs-result` object to the UUID of the LearnerProfile whose `ncUserId` equals the result's `learnerId`, looked up in the result's `tenant_id` when it has one. A `learnerRef` sent by the client MUST be ignored. A learner without a profile MUST get `learnerRef: null`. The stamp MUST NOT block a write: a failed lookup gives null on create and keeps the stored value on an update that keeps the same learner. Authorization and the read rule MUST keep scoping on `learnerId`. Every LVS result in a shipped example set MUST carry a `learnerRef` that names a learner profile of the same pupil in the same set.

#### Scenario: An imported Cito result is linked to its pupil
@e2e exclude Server-side write listener with no DOM surface; pinned by tests/Unit/Listener/LvsResultLearnerRefStampTest.php::testAnImportedResultIsLinkedToItsPupil.
- **GIVEN** a learner profile `lp-3` for `pupil-1` in tenant B
- **WHEN** an integriq `lvs-results` job for tenant B lands a Cito result for `pupil-1`
- **THEN** the stored result carries `learnerRef: "lp-3"`

#### Scenario: A forged learnerRef is replaced
@e2e exclude Server-side write listener with no DOM surface; pinned by tests/Unit/Listener/LvsResultLearnerRefStampTest.php::testAForgedLearnerRefIsReplaced.
- **GIVEN** a learner profile `lp-1` for `pupil-1`
- **WHEN** a coordinator saves a result for `pupil-1` with `learnerRef: "lp-2"`
- **THEN** the stored result carries `learnerRef: "lp-1"`

#### Scenario: Every example LVS result names its pupil's profile
@e2e exclude Register and example-set contract with no DOM surface; pinned by tests/Unit/Settings/LvsResultLearnerRefRegisterTest.php::testEveryExampleLvsResultResolvesToItsPupilsProfile.
- **GIVEN** the primary-school example set
- **WHEN** the contract test reads its LVS results
- **THEN** each one carries a `learnerRef` that names a learner profile in the set whose `ncUserId` is the result's `learnerId`

### Requirement: Existing LvsResults are back-filled once
The `BackfillLvsResultLearnerRef` repair step MUST stamp `learnerRef` on every existing `lvs-result` object that has a `learnerId` and no `learnerRef`, looking the profile up in the result's tenant. It MUST skip rows that already carry a `learnerRef` and rows whose learner has no profile, MUST leave every other field as it was, and MUST be safe to run again: a second run writes nothing new.

#### Scenario: The backfill stamps unstamped rows and skips the rest
@e2e exclude Repair step with no DOM surface; pinned by tests/Unit/Repair/BackfillLvsResultLearnerRefTest.php::testStampsOnlyTheRowsThatNeedIt.
- **GIVEN** three LVS results: one verified for `pupil-1` without `learnerRef`, one for `pupil-1` with `learnerRef: "lp-1"`, and one for `pupil-9` who has no profile
- **WHEN** the repair step runs
- **THEN** only the first result is saved, with `learnerRef: "lp-1"` and its score and lifecycle unchanged
- **AND** a second run saves nothing
