## ADDED Requirements

### Requirement: A pupil reads by name
The learner profile schema MUST declare `configuration.objectNameField` as `{{ givenName }} {{ familyName }}`, so OpenRegister names every saved profile after the pupil. A repair step MUST save every stored profile whose `@self.name` is not that name yet, without a session, and MUST save nothing on a second run. A teacher list that shows the pupil of a row MUST show the row's learner profile resolved to its name, never the Nextcloud user id, and every code such a list shows MUST have a label with a Dutch entry.

#### Scenario: A teacher reads the bookings to answer
@e2e exclude Manifest and register content; the list is nextcloud-vue's object list with the fkResolve cell. Pinned by tests/unit-js/pupilsReadByName.test.mjs; the live check on the primary-school instance is in the PR.
- **GIVEN** Vera Hulstkamp's guardian booked a conversation in a round
- **WHEN** her teacher opens the round and reads "Bookings to answer"
- **THEN** the row names the pupil "Vera Hulstkamp"
- **AND** not `po-leerling-147`

#### Scenario: A profile stored before the name existed gets it on upgrade
@e2e exclude Repair step. Pinned by tests/Unit/Repair/BackfillLearnerProfileNamesTest.php.
- **GIVEN** a learner profile stored while the schema had no display name, so it reads by its uuid
- **WHEN** the app is upgraded and BackfillLearnerProfileNames runs
- **THEN** the profile reads "Vera Hulstkamp"
- **AND** a second run saves nothing

#### Scenario: A teacher reads the absence reports
@e2e exclude Manifest content. Pinned by tests/unit-js/pupilsReadByName.test.mjs.
- **GIVEN** a guardian reported Vera ill
- **WHEN** her teacher opens the absence reports in Dutch
- **THEN** the row shows "Vera Hulstkamp", the dates, "Ziekte" and "Ingediend"
