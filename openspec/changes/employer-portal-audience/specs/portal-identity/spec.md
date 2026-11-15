## ADDED Requirements

### Requirement: The institute invites a company's contact person as its employer

`occ learniq:portal:invite-employer <organisationRef> <organisation> [email]` MUST provision a portal account on the `employer` audience for an active `client-organisation` the institute created, with identity type `eherkenning` and the company's eHerkenning reference when it has one, and MUST write the claims `organisationRef` (the company), `organisationName` (its name) and, when the company names a location, `editionLocationRef`. Every claim an employer collection is scoped by MUST be among them. An unknown or inactive company, or an address that is no address, MUST be refused before anything is dispatched.

#### Scenario: Jansen's contact person is invited
- **GIVEN** the training set is loaded
- **WHEN** the administrator runs the command for Jansen Installatietechniek BV
- **THEN** an `employer` account exists with those three claims, found again when Linda signs in with eHerkenning
- @e2e exclude occ command, covered by PHPUnit `EmployerSitePagesTest::testTheInvitationWritesTheClaimsTheCollectionsRead`
