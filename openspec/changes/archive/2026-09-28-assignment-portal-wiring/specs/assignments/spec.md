# assignments Specification

## ADDED Requirements

### Requirement: The server stamps who a submission belongs to

`SubmissionOwnerStamp` MUST run on every create and update of a `submission` object, on
OpenRegister's `ObjectCreatingEvent` and `ObjectUpdatingEvent`. It MUST treat a create as a portal
hand-in when there is no Nextcloud session, `learnerIds` is empty and `learnerRef` is set (portaliq
stamps the pupil's LearnerProfile UUID there). For a portal hand-in it MUST read that LearnerProfile
and the Assignment without RBAC and set `learnerIds` to `[profile.ncUserId]`, `learnerRefs` to
`[learnerRef]` and `tenant_id` to the Assignment's tenant (the profile's when the Assignment has
none). It MUST refuse the create when the profile does not exist, is merged away or deleted, when the
Assignment does not exist, or when the profile and the Assignment belong to different tenants. For
every other write it MUST set `learnerRef` to the UUID of the LearnerProfile whose `ncUserId` is
`learnerIds[0]`, preferring one that is not merged away, and MUST ignore a `learnerRef` the client
sent. When no profile matches, `learnerRef` MUST be null. When that lookup fails on an update of a
row whose `learnerIds` did not change, the stored `learnerRef` MUST be kept. After stamping, it MUST
refuse any create or update that has no `learnerIds` or no `tenant_id`, whoever the caller is.
`learnerIds` and `tenant_id` MUST NOT be in the schema's `required` list, because OpenRegister
validates `required` before any listener runs.

#### Scenario: A portal hand-in gets its learner and tenant from the pupil's profile

<!-- @e2e exclude Server-side write listener fed by portaliq's server-to-server create; no DOM surface in learniq. Covered by PHPUnit SubmissionOwnerStampTest::testAPortalHandInIsStampedFromTheProfile. -->

- **GIVEN** a LearnerProfile `lp-1` with `ncUserId: "pupil-1"` and an Assignment `as-1` in tenant `t-1`
- **WHEN** portaliq creates a Submission with `assignmentId: "as-1"`, `learnerRef: "lp-1"` and no Nextcloud session
- **THEN** the stored Submission carries `learnerIds: ["pupil-1"]`, `learnerRefs: ["lp-1"]` and `tenant_id: "t-1"`
- **AND** the write is not stopped

#### Scenario: A portal hand-in for an unknown or merged pupil is refused

<!-- @e2e exclude PHPUnit SubmissionOwnerStampTest (unknown profile, merged profile, missing assignment, tenant mismatch). -->

- **GIVEN** no active LearnerProfile `lp-9`
- **WHEN** portaliq creates a Submission with `learnerRef: "lp-9"`
- **THEN** the create is refused and no Submission is stored

#### Scenario: A staff create without learners is still refused

<!-- @e2e exclude PHPUnit SubmissionOwnerStampTest::testAStaffCreateWithoutLearnersIsRefused. -->

- **GIVEN** a signed-in teacher
- **WHEN** the teacher creates a Submission with `assignmentId` and `tenant_id` but no `learnerIds`
- **THEN** the create is refused with a message that names the learners and the school

#### Scenario: A forged learnerRef from the app is replaced

<!-- @e2e exclude PHPUnit SubmissionOwnerStampTest::testAForgedLearnerRefIsReplaced. -->

- **GIVEN** LearnerProfiles `lp-1` (`ncUserId: "pupil-1"`) and `lp-2` (`ncUserId: "pupil-2"`)
- **WHEN** pupil-1 creates a Submission in the app with `learnerIds: ["pupil-1"]` and `learnerRef: "lp-2"`
- **THEN** the stored Submission carries `learnerRef: "lp-1"`

#### Scenario: An update keeps the stored learnerRef when the lookup fails

<!-- @e2e exclude PHPUnit SubmissionOwnerStampTest::testAFailedLookupOnUpdateKeepsTheStoredRef. -->

- **GIVEN** a Submission with `learnerIds: ["pupil-1"]` and `learnerRef: "lp-1"`
- **WHEN** portaliq attaches a file to it and the profile lookup throws
- **THEN** the stored Submission still carries `learnerRef: "lp-1"`
