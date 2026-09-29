# Attendance: excuse request owner delta

## ADDED Requirements

### Requirement: The server stamps who an excuse request is about and who filed it

`ExcuseRequest.required` MUST list only what every caller sends: `dateFrom`, `dateTo`, `reason` and `reasonKind`. OpenRegister validates `required` before any listener runs, so the owner fields MUST be filled and enforced by a pre-write listener instead. For a portal report (no Nextcloud session, no `learnerId`, a `learnerRef`) the server MUST take `learnerId`, `learnerRef` and `tenant_id` from the pupil's LearnerProfile, and MUST refuse the report when that profile is unknown or cannot be read. A pupil's own report MUST name the pupil as submitter and record `submittedAuthLevel` `basic`. A guardian's report (`submittedByRef` set) MUST be refused unless the pupil's profile lists that guardian in `guardianRefs`; it MUST record the guardian's user id in `submittedBy` when the guardian has one and `submittedAuthLevel` `substantial`. Every other write MUST have its `learnerRef` derived from `learnerId`, ignoring a client value, and MUST get `submittedAuthLevel` `basic` when it sends none. Every write that still lacks `learnerId`, a submitter (`submittedBy` or `submittedByRef`) or `tenant_id` MUST be refused.

#### Scenario: A pupil reports an absence through the portal
@e2e exclude Pre-write listener with no screen of its own in learniq; pinned by tests/Unit/Listener/ExcuseRequestOwnerStampTest.php (testAPupilReportIsStampedFromTheProfile).
- **GIVEN** pupil `pupil-1` with LearnerProfile `lp-1`
- **WHEN** portaliq creates an ExcuseRequest with the dates, reason and kind, and `learnerRef: "lp-1"`
- **THEN** it is stored with `learnerId: "pupil-1"`, `submittedBy: "pupil-1"`, the pupil's school and `submittedAuthLevel: "basic"`

#### Scenario: A guardian reports an absence for their child
@e2e exclude Pre-write listener; pinned by tests/Unit/Listener/ExcuseRequestOwnerStampTest.php (testAGuardianReportNamesTheChildAndTheGuardian).
- **GIVEN** the pupil's profile lists guardian `gp-1`, whose user is `ouder-1`
- **WHEN** portaliq creates an ExcuseRequest with `learnerRef: "lp-1"` and `submittedByRef: "gp-1"`
- **THEN** it is stored with `learnerId: "pupil-1"`, `submittedBy: "ouder-1"` and `submittedAuthLevel: "substantial"`

#### Scenario: A guardian cannot report for somebody else's child
@e2e exclude Pre-write listener; pinned by tests/Unit/Listener/ExcuseRequestOwnerStampTest.php (testAGuardianOfAnotherChildIsRefused).
- **GIVEN** the pupil's profile does not list guardian `gp-9`
- **WHEN** portaliq creates an ExcuseRequest with `learnerRef: "lp-1"` and `submittedByRef: "gp-9"`
- **THEN** the write is refused with reason `excuse-guardian-unknown`

#### Scenario: Staff are still held to the owner fields
@e2e exclude Pre-write listener; pinned by tests/Unit/Listener/ExcuseRequestOwnerStampTest.php (testAStaffCreateWithoutItsOwnerFieldsIsRefused).
- **GIVEN** a signed-in mentor
- **WHEN** they create an ExcuseRequest without `learnerId`, without a submitter or without `tenant_id`
- **THEN** the write is refused with reason `excuse-owner-missing`
