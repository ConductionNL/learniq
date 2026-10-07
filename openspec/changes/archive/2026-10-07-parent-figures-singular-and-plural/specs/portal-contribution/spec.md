## ADDED Requirements

### Requirement: The figure cards count in singular and plural
Each attendance figure card on the parent record page MUST declare its `unit` as `{one, other}`, and the late-minutes detail MUST declare its `label` the same way. The provider MUST translate both forms through learniq's catalogue in the request's language and MUST NOT translate any other key of such a map. Both forms MUST have a Dutch entry in `l10n/nl.json`.

#### Scenario: One day of absence reads singular
@e2e exclude Manifest content; the card is portaliq's (`kpi-unit-singular-and-plural`). Pinned by tests/Unit/Portal/PortalLabelTranslatorTest.php testTheFigureCardsArriveInDutchSingularAndPlural against the real nl.json; the live check on the primary-school instance is in the PR.
- **GIVEN** Vera was absent one day this school year
- **WHEN** Fatima Hulstkamp opens Vera on a Dutch portal
- **THEN** the "Afwezig" card reads "1 dag"

#### Scenario: Any other figure reads plural
@e2e exclude Manifest content; the choice of form is portaliq's. Pinned by tests/Unit/Portal/ParentRecordPageTest.php testTheFigureCardsCountInSingularAndPlural.
- **GIVEN** a child was absent 0 or 5 days
- **WHEN** the guardian opens the child
- **THEN** the card reads "dagen"
