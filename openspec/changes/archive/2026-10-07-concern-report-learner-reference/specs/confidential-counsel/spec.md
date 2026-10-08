## MODIFIED Requirements

### Requirement: A learner can report a concern to the confidential counsellors
The register MUST declare `ConcernReport` (slug `concern-report`) with `topic`, `description`, `happenedOn`, `wantsConversation`, `status`, `reporterId` and `tenant_id`. Any authenticated user MUST be able to create one. Its read entries MUST be exactly the bare group `confidential-counsellors` and an authenticated user matched on `reporterId`. Update and delete MUST be `confidential-counsellors` only. No other group MAY appear in its authorization.

`ConcernReport` MAY declare `learnerId`: a string, format uuid, `$ref` LearnerProfile, nullable and not required, with no `inversedBy`. It names the pupil a member of staff reported about from that pupil's page, where the form opens with `learnerId` filled in. `learnerId` MUST be the only reference out of `ConcernReport`, and no other schema MAY reference `ConcernReport`. The reference MUST NOT widen read access: the pupil it names, that pupil's guardians and that pupil's teachers MUST NOT read the report through it. The Report a concern page and the start-page button for pupils and guardians MUST leave `learnerId` out of the form.

#### Scenario: A learner files a report and sees it
@e2e exclude Register contract with no DOM surface in CI; pinned by tests/Unit/Settings/ConcernReportRegisterTest.php::testTheWrittenPayloadsPassTheRealSchema.
- **GIVEN** learner `lrn-12` on the "Report a concern" page
- **WHEN** the learner submits topic bullying and a description
- **THEN** the report is stored with status received and appears in the learner's own list

#### Scenario: A teacher cannot read a report
@e2e exclude Read rule evaluated by OpenRegister with no DOM surface; pinned by tests/Unit/Register/ConcernReportReadAccessTest.php::testNobodyButTheReporterAndTheCounsellorsReadsAReport.
- **GIVEN** a concern report filed by `lrn-12`
- **WHEN** a user in `instructors` and `administration-managers` lists concern reports
- **THEN** the report is not returned

#### Scenario: Another learner cannot read a report
@e2e exclude Read rule evaluated by OpenRegister with no DOM surface; pinned by tests/Unit/Register/ConcernReportReadAccessTest.php::testNobodyButTheReporterAndTheCounsellorsReadsAReport.
- **GIVEN** a concern report filed by `lrn-12`
- **WHEN** learner `lrn-13` lists concern reports
- **THEN** the report is not returned

#### Scenario: A counsellor reads and updates the report
@e2e exclude Read rule evaluated by OpenRegister with no DOM surface; pinned by tests/Unit/Register/ConcernReportReadAccessTest.php::testTheReporterAndEveryCounsellorReadIt.
- **GIVEN** a concern report filed by `lrn-12`
- **WHEN** a member of `confidential-counsellors` opens "Concern reports" and sets the status to in-progress
- **THEN** the counsellor sees the report and who filed it, and the learner sees status in-progress

#### Scenario: A staff report from the pupil's page names the pupil
@e2e exclude Manifest overlay contract with no DOM surface in CI; pinned by tests/unit-js/structureProfile.test.mjs, test "Report a concern is an action on the pupil page, the same form pre-filled with the pupil, and its page stays".
- **GIVEN** mentor `docent-03` on the page of pupil `lrn-12`, whose learner profile is `lp-12`
- **WHEN** the mentor chooses Report a concern and submits a description
- **THEN** the stored report carries `learnerId` `lp-12` and `reporterId` `docent-03`

#### Scenario: Naming the pupil opens the report to nobody new
@e2e exclude Read rule evaluated by OpenRegister with no DOM surface; pinned by tests/Unit/Settings/ConcernReportRegisterTest.php::testOnlyCounsellorsAndTheReporterReadAReport.
- **GIVEN** a concern report filed by `docent-03` with `learnerId` `lp-12`
- **WHEN** learner `lrn-12`, a guardian of `lrn-12` or another teacher of `lrn-12` lists concern reports
- **THEN** the report is not returned

#### Scenario: The pupil reference is the only one, and it points one way
@e2e exclude Register contract with no DOM surface; pinned by tests/Unit/Settings/ConcernReportRegisterTest.php::testTheReportIsStructurallyIsolated.
- **GIVEN** the register
- **WHEN** every schema's properties are scanned for references
- **THEN** `ConcernReport` references only LearnerProfile, through `learnerId`, without `inversedBy`, and no schema references `ConcernReport`

#### Scenario: A pupil's own report asks no pupil
@e2e exclude Manifest overlay contract with no DOM surface in CI; pinned by tests/unit-js/structureProfile.test.mjs, test "pupils and guardians get Report a concern as a button on their start page, nobody else does".
- **GIVEN** learner `lrn-12` on the "Report a concern" page or on the start page
- **WHEN** the report form opens
- **THEN** the form has no pupil field, and the stored report has no `learnerId`
