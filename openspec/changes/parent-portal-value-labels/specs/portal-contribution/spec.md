## ADDED Requirements

### Requirement: A guardian reads statuses and kinds of absence in the portal's language
The parent manifest MUST declare `valueLabels` on the status columns a guardian reads: `parentExcuseRequests.lifecycle`, `parentAttendance.status`, `parentConferenceSignups.lifecycle` and `parentConferenceSlots.lifecycle`. It MUST declare `valueLabels` on the `reasonKind` field config of `createExcuseRequest`. The keys of each map MUST equal the enum of the schema property it labels. The provider MUST translate every label through learniq's catalogue in the request's language, and MUST NOT change a key. Every label MUST have a Dutch entry in `l10n/nl.json`.

#### Scenario: A guardian on a Dutch portal reads an approved absence report as "Goedgekeurd"
@e2e exclude Manifest content; the rendering is portaliq's (`contribution-value-labels`). Pinned by tests/Unit/Portal/PortalLabelTranslatorTest.php testStatusesAndAbsenceKindsArriveInDutch against the real nl.json; the live check on the primary-school instance is in the PR.
- **GIVEN** Fatima Hulstkamp's report for Vera has `lifecycle: approved`
- **WHEN** she opens "Afwezigheidsmeldingen van mijn kind" on a Dutch portal
- **THEN** the status reads "Goedgekeurd"

#### Scenario: The absence form offers its kinds in Dutch
@e2e exclude Manifest content; the select is portaliq's. Pinned by tests/Unit/Portal/PortalLabelTranslatorTest.php testStatusesAndAbsenceKindsArriveInDutch.
- **WHEN** a guardian opens the absence form on a Dutch portal
- **THEN** "Soort afwezigheid" offers "Ziekte", "Medische afspraak" and the other kinds in Dutch
- **AND** the report stores `illness` when she picks "Ziekte"

#### Scenario: A renamed enum value fails the build
@e2e exclude Unit invariant; pinned by tests/Unit/Portal/PortalLabelTranslatorTest.php testEveryValueLabelMatchesTheSchemaEnum.
- **GIVEN** a schema enum value is renamed
- **WHEN** the unit tests run
- **THEN** the test names the property whose labels no longer match
