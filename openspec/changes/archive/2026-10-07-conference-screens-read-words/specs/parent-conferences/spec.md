## ADDED Requirements

### Requirement: The teacher's conference screens read words, not codes
The conference rounds, conference slots and school calendar lists MUST declare their columns, and no column MUST show the tenant, a uuid reference or a Nextcloud user id. The school calendar MUST render its start and end as dates. The slot page MUST show its readable fields first (time, teacher, status) and MUST NOT show the tenant, the signup, the eligible pupils, the teacher's user id or the guardian reference. Every status these screens show MUST have a label, and every label and schema title they show MUST have a Dutch entry.

#### Scenario: A teacher reads the list of conference slots
@e2e exclude Manifest content; the list is nextcloud-vue's index page. Pinned by tests/unit-js/conferenceScreensReadWords.test.mjs; the live check on the primary-school instance is in the PR.
- **GIVEN** Meester Daan opens Conference slots
- **WHEN** the list renders
- **THEN** it shows time, teacher, status, start and location
- **AND** no tenant id, uuid or user id

#### Scenario: A round's status reads as a word
@e2e exclude Register content. Pinned by tests/unit-js/conferenceScreensReadWords.test.mjs "every status a conference screen shows has a label in Dutch".
- **GIVEN** a round whose lifecycle is `booking-closed`
- **WHEN** a teacher reads the list of rounds in Dutch
- **THEN** the status reads "Boeken gesloten"

#### Scenario: The school calendar reads dates
@e2e exclude Manifest content. Pinned by tests/unit-js/conferenceScreensReadWords.test.mjs "the school calendar reads its dates as dates".
- **GIVEN** a school event that starts at "2025-12-18T17:30:00+01:00"
- **WHEN** a coordinator opens the school calendar
- **THEN** the start reads as a date, not as the stored timestamp
