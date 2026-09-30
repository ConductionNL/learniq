## ADDED Requirements

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
- **WHEN** a member of `confidential-counsellors` opens "Reports" and sets the status to in-progress
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
The manifest MUST expose a "Reports" entry for `concern-report`, gated on `user.isConfidentialCounsellor`, and a "Report a concern" entry for every signed-in user.

#### Scenario: A mentor sees only the report form
- **GIVEN** a user in `instructors` only
- **WHEN** the app navigation renders
- **THEN** there is a "Report a concern" entry and no "Reports" entry
