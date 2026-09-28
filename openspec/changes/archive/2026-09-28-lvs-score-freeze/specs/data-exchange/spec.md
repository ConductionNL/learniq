# Data exchange: verified LVS score freeze delta

## ADDED Requirements

### Requirement: A verified LVS score cannot be changed

Once an `LvsResult` is stored as `verified` or `archived`, an update by a signed-in user who is not a Nextcloud admin MUST NOT change `provider`, `instrument`, `moment`, `takenAt`, `rawScore`, `vaardigheidsscore`, `niveau`, `referentieniveau`, `dle`, `dataExchangeJobId` or `tenant_id`. A verified result MUST only move on to `archived`, and an archived result MUST NOT change state. `learnerId` and `assessmentResultId` MAY still change. An `imported` result MAY be corrected. Admins and system context are not held to this rule.

#### Scenario: A coordinator cannot change a verified score
@e2e exclude Pre-write listener with no UI of its own; pinned by tests/Unit/Listener/LvsResultFreezeListenerTest.php::testCoordinatorCannotChangeAVerifiedScore.
- **GIVEN** an `LvsResult` in `verified` with `vaardigheidsscore` 187
- **AND** a user in `coordinators`
- **WHEN** the user saves it with `vaardigheidsscore` 201
- **THEN** the save is refused with reason `lvs-result-verified`

#### Scenario: An imported score may still be corrected
@e2e exclude Pre-write listener with no UI of its own; pinned by tests/Unit/Listener/LvsResultFreezeListenerTest.php::testAnImportedScoreMayBeCorrected.
- **GIVEN** an `LvsResult` in `imported`
- **WHEN** a coordinator corrects its `vaardigheidsscore`
- **THEN** the save goes through

#### Scenario: A verified result cannot go back to imported
@e2e exclude Pre-write listener with no UI of its own; pinned by tests/Unit/Listener/LvsResultFreezeListenerTest.php::testAVerifiedResultCannotGoBackToImported.
- **GIVEN** an `LvsResult` in `verified`
- **WHEN** a coordinator saves it as `imported`
- **THEN** the save is refused with reason `lvs-result-lifecycle`
- **AND** saving it as `archived` goes through
