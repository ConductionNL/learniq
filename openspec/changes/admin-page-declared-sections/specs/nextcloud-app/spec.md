# nextcloud-app Delta: admin-page-declared-sections

## ADDED Requirements

### Requirement: App configuration lives on the admin page

The kind of organisation, the timetable visibility policy and the room opening
hours SHALL be set on the Nextcloud admin page of learniq, not on pages inside
the app. The admin page SHALL save them through learniq's endpoints, never by
writing OpenRegister objects from the browser.

#### Scenario: An admin opens the room opening hours

- **WHEN** an administrator opens the Timetable exchange tab of the admin page
- **THEN** the opening hours are there, one row per day, and saving them calls `PUT /api/reports/room-use/opening-hours`

### Requirement: The server writes the privacy agreement's audit fields

Saving the Compliance record SHALL go through `PUT /api/admin/compliance`, which
accepts only the agreement and notice fields. The server SHALL set
`lastReviewedBy` and `lastReviewedAt` on every save, set
`privacyconvenantSignedAt` when the agreement becomes signed, keep it while it
stays signed, and clear it when it is unsigned.

#### Scenario: Re-saving a signed agreement

- **GIVEN** an agreement signed on 1 March
- **WHEN** an administrator changes the privacy notice version and saves
- **THEN** the agreement still reads signed on 1 March
