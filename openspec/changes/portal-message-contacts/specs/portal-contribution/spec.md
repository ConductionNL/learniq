## ADDED Requirements

### Requirement: A guardian and a pupil may write to the teachers of the pupil's current groups

The parent audience's `parentChildren` collection MUST declare `contacts` with provider `childMessageContacts` and record label field `givenName`; the student audience's `studentEnrolments` collection MUST declare `contacts` with provider `ownMessageContacts`. `childMessageContacts(profileId)` MUST answer the teachers of the groups the child is a current member of (a group that is completed or archived does not count); `ownMessageContacts(enrolmentId)` MUST answer the teachers of that enrolment's group only while the enrolment is `active`. Each answer entry MUST be `{staffRef, name, role}` with the Nextcloud user id, the account's display name and the role in words, the group's primary teacher first and each teacher once. A teacher whose account has no display name of its own MUST be left out. An id that does not resolve MUST answer nobody.

#### Scenario: Fatima writes about Vera
- GIVEN the po example set with Vera Hulstkamp in Groep 7 taught by po-leerkracht-09
- WHEN portaliq asks `childMessageContacts` for Vera's learner profile
- THEN the answer names po-leerkracht-09 by name and nobody else
- @e2e exclude portaliq draws the form; PortalMessageContactsTest reads the real po seed

#### Scenario: Noor writes to her mentor
- GIVEN the vo example set with Noor Bakker actively enrolled in H4b, whose primary teacher is Sanne Kramer, a mentor
- WHEN portaliq asks `ownMessageContacts` for that enrolment
- THEN the first entry is Sanne Kramer with the role Mentor
- @e2e exclude portaliq draws the form; PortalMessageContactsTest reads the real vo seed

#### Scenario: Last year's group names nobody
- GIVEN Noor's completed H3b enrolment
- WHEN portaliq asks `ownMessageContacts` for it
- THEN the answer is empty
- @e2e exclude covered by PortalMessageContactsTest
