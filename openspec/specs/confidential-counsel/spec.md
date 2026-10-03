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
The register MUST declare `ConcernReport` (slug `concern-report`) with `topic`, `description`, `happenedOn`, `wantsConversation`, `status`, `reporterId` and `tenant_id`. Any authenticated user MUST be able to create one. Its read entries MUST be exactly the bare group `confidential-counsellors` and an authenticated user matched on `reporterId`. Update and delete MUST be `confidential-counsellors` only. No other group MAY appear in its authorization, and it MUST NOT reference or be referenced by another schema.

#### Scenario: A learner files a report and sees it
- **GIVEN** learner `lrn-12` on the "Report a concern" page
- **WHEN** the learner submits topic bullying and a description
- **THEN** the report is stored with status received and appears in the learner's own list

#### Scenario: A teacher cannot read a report
- **GIVEN** a concern report filed by `lrn-12`
- **WHEN** a user in `instructors` and `administration-managers` lists concern reports
- **THEN** the report is not returned

#### Scenario: Another learner cannot read a report
- **GIVEN** a concern report filed by `lrn-12`
- **WHEN** learner `lrn-13` lists concern reports
- **THEN** the report is not returned

#### Scenario: A counsellor reads and updates the report
- **GIVEN** a concern report filed by `lrn-12`
- **WHEN** a member of `confidential-counsellors` opens "Concern reports" and sets the status to in-progress
- **THEN** the counsellor sees the report and who filed it, and the learner sees status in-progress

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
