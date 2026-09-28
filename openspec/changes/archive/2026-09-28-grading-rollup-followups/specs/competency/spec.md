# Competency: deferred attainment roll-up delta

## ADDED Requirements

### Requirement: The competency attainment roll-up runs outside the save that triggers it

`CompetencyAttainmentRollupHandler` MUST NOT read or write objects inside the save that fired it. For a created WerkprocesAssessment, a GradeEntry moving to `published` and a WerkprocesAssessment moving to `confirmed`, it MUST queue the work through `ListenerDeferralService`, deduplicated per kind and object, and `CompetencyAttainmentRollupJob` MUST run it as the acting user with the same outcome as before.

#### Scenario: A new werkproces assessment is saved without waiting for its competency
@e2e exclude Deferral with no UI of its own; pinned by tests/Unit/Listener/CompetencyAttainmentRollupHandlerTest.php::testTheHandlerQueuesTheWorkAndWritesNothingItself.
- **GIVEN** a WerkprocesAssessment with a werkproces code
- **WHEN** it is created
- **THEN** the save writes no other object
- **AND** a `werkproces-created` roll-up is queued for `CompetencyAttainmentRollupJob`
