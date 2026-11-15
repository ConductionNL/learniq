# confidential-counsel Specification

## Purpose
Give a school's confidential counsellor (vertrouwenspersoon) a place for case notes that no school leader, mentor, coordinator or compliance officer can open. One declared scope, `confidential-counsellors`, and one isolated schema, `ConfidentialNote`, readable only by its author and the people the author names on the case, with no link to or from the pupil record.

## Requirements

### Requirement: The confidential counsellor has one declared scope
The register MUST declare the scope `confidential-counsellors` next to the eight existing groups, so OpenRegister provisions it. `DashboardRoleService::GROUP_BACKED_ROLES` MUST map the role `confidential-counsellor` to it, ranked below every staff role and above `guardian`.

#### Scenario: A teacher who is also the vertrouwenspersoon keeps the teacher view
- **GIVEN** a user in `instructors` and in `confidential-counsellors`
- **WHEN** the primary role is resolved
- **THEN** it is `instructor`, and `isConfidentialCounsellor()` is true

#### Scenario: An external vertrouwenspersoon is not a learner
- **GIVEN** a user whose only learniq group is `confidential-counsellors`
- **WHEN** the primary role is resolved
- **THEN** it is `confidential-counsellor`

### Requirement: Confidential notes are readable by their author and named participants only
`ConfidentialNote` MUST declare an `authorization` block whose read entries are exactly: a `confidential-counsellors` member matched on `authorId`, and any authenticated user contained in `participantIds`. Create MUST be `confidential-counsellors`; update and delete MUST be a `confidential-counsellors` member matched on `authorId`. No action MAY name `instructors`, `hr`, `compliance-officers`, `team-leads`, `coordinators`, `administration-managers`, `learners` or `guardians`.

#### Scenario: A school leader cannot read a note
- **GIVEN** a `ConfidentialNote` written by vertrouwenspersoon `vp-01` with no participants
- **WHEN** a user in `administration-managers` and `compliance-officers` lists confidential notes
- **THEN** the note is not returned

#### Scenario: A named participant reads the note but cannot change it
- **GIVEN** a note by `vp-01` with `participantIds: ["staff-07"]`
- **WHEN** `staff-07` reads it and then tries to update it
- **THEN** the read succeeds and the update is refused

#### Scenario: A former vertrouwenspersoon loses access
- **GIVEN** a note by `vp-01`, and `vp-01` has been removed from `confidential-counsellors`
- **WHEN** `vp-01` lists confidential notes
- **THEN** the note is not returned

### Requirement: Confidential notes are structurally isolated
`ConfidentialNote` MUST NOT carry a `$ref` to another schema, no other schema MAY `$ref` it, it MUST NOT be searchable, it MUST hard-delete, and it MUST NOT be `appendOnly`.

#### Scenario: A pupil record never leads to a confidential note
- **GIVEN** the register
- **WHEN** every schema's properties are scanned for references
- **THEN** none references `ConfidentialNote` and `ConfidentialNote` references none

### Requirement: The confidential notes menu is shown to confidential counsellors only
The manifest MUST expose a "Confidential notes" menu entry with index and detail pages for `confidential-note`, gated on `user.isConfidentialCounsellor`, which the page shell fills from `DashboardRoleService::isConfidentialCounsellor()`.

#### Scenario: A mentor does not see the menu
- **GIVEN** a user in `instructors` only
- **WHEN** the app navigation renders
- **THEN** there is no "Confidential notes" entry

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

### Requirement: The server decides who filed a report
`reporterId` MUST be set by the server to the signed-in user on create, whatever the client sends, and MUST keep its stored value on every update. A create without a signed-in user MUST be refused.

#### Scenario: A report cannot be filed in someone else's name
- **GIVEN** learner `lrn-12` signed in
- **WHEN** the learner creates a concern report with `reporterId` set to `lrn-13`
- **THEN** the stored report has `reporterId` `lrn-12`

#### Scenario: A counsellor cannot move a report to another person
- **GIVEN** a concern report filed by `lrn-12`
- **WHEN** a counsellor updates it with `reporterId` set to `lrn-13`
- **THEN** the stored report still has `reporterId` `lrn-12`

### Requirement: Counsellors are told a report arrived, without names
On create, `ConcernReport` MUST notify the `confidential-counsellors` group through `nc-notification`, and the subject MUST NOT contain the reporter, the topic or the description.

#### Scenario: The notification is anonymous
- **GIVEN** counsellor `vp-01` in `confidential-counsellors`
- **WHEN** learner `lrn-12` files a report
- **THEN** `vp-01` receives the notification "A new confidential report has arrived" and no one outside the group receives one

### Requirement: The reports menu is shown to confidential counsellors only
The manifest MUST expose a "Concern reports" entry for `concern-report`, gated on `user.isConfidentialCounsellor`, and a "Report a concern" entry for every signed-in user.

#### Scenario: A mentor sees only the report form
- **GIVEN** a user in `instructors` only
- **WHEN** the app navigation renders
- **THEN** there is a "Report a concern" entry and no "Concern reports" entry
