## ADDED Requirements

### Requirement: The install guide names every step a fresh portal install needs

The installation guide MUST name, in order, the steps that make a loaded school set a working portal: the loads, `occ maintenance:repair` with cron, the portal's organisation, the organisation's DigiD and eHerkenning issuer, and the portal accounts of pupils, students and participants.

#### Scenario: An operator follows the guide on a fresh instance
- **GIVEN** a fresh instance with openregister, thematiq, portaliq and learniq enabled
- **WHEN** the operator follows "Example portals: load a set from the command line" in `docs/installation.md`
- **THEN** every step portal proof run 1 had to work out by hand is in the guide
- @e2e exclude documentation only
